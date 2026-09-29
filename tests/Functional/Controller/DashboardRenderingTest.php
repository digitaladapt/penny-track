<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\ApiKey;
use App\Entity\Receipt;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards the rendered HTML of the dashboard, not just its status code.
 *
 * Both bugs this covers shipped green through 173 tests, because the only
 * assertion on the page was `assertSelectorExists('#summary-cards')` — the
 * element a <body> swap guarantees — while the failure was in the page's
 * JavaScript, a layer PHPUnit does not execute.
 *
 * Everything is built inside renderDashboard() rather than in setUp(), so no
 * state is carried in typed properties that PHPStan cannot prove are
 * initialised. The pre-existing tests carry baseline entries for exactly that;
 * adding more of them for a brand-new file would be working around the check
 * rather than satisfying it.
 */
class DashboardRenderingTest extends WebTestCase
{
    /**
     * Twig is not JavaScript-aware and does not know it is inside a script
     * element. A `{{ ... }}` in there is expanded wherever it is found, and
     * `{{ importmap('app') }}` and `{{ asset(...) }}` both render markup that
     * carries a literal `</script>`-shaped end tag.
     *
     * An HTML parser ends the script element at the FIRST such tag. Everything
     * after it — the rest of the script — is then treated as document text:
     * it renders visibly on the page, and the remainder never executes.
     *
     * That is exactly how the dashboard lost its charts: a `{{ importmap('app') }}`
     * inside a block comment in templates/dashboard/index.html.twig.
     */
    public function test_no_script_element_contains_a_premature_end_tag(): void
    {
        $html = $this->renderDashboard();

        // Mimic the parser: an element's content runs up to the first close tag.
        preg_match_all('#<script\b[^>]*>(.*?)</script#is', $html, $matches);

        $this->assertNotEmpty($matches[1], 'Expected the dashboard to contain inline scripts.');

        $offenders = [];
        foreach ($matches[1] as $body) {
            if (str_contains($body, '</script') || str_contains($body, '<script')) {
                $offenders[] = trim(mb_substr($body, 0, 120));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A <script> element's content must not contain nested script markup: it ends the element\n"
            ."early, dumps the remainder onto the page as visible text, and stops the script running.\n"
            .'This is almost always a Twig expression inside the script — most often an importmap()\n'
            .'or asset() call, which emit markup. Move it out, or describe it in prose in the comment.',
        );
    }

    /**
     * The importmap must contain no `data:` entries.
     *
     * AssetMapper renders a JS-side import of a CSS file as an empty
     * `data:application/javascript,` stub. The app's CSP is `script-src 'self'`,
     * which does not permit `data:`, so the browser blocks the stub — and since
     * an ES module graph fails as a unit, that one blocked CSS import took
     * app.js, Stimulus, Turbo and Chart.js down with it.
     *
     * The stylesheet is linked from the template instead, which keeps this list
     * clean and the policy strict. See templates/base.html.twig.
     */
    public function test_importmap_contains_no_data_urls(): void
    {
        $imports = $this->renderedImportMap();

        $blocked = array_keys(array_filter(
            $imports,
            static fn ($url) => str_starts_with((string) $url, 'data:'),
        ));

        $this->assertSame(
            [],
            $blocked,
            "The importmap contains data: URLs, which the app's `script-src 'self'` CSP blocks.\n"
            ."A blocked entry fails its whole module graph — the page still returns 200, but no\n"
            .'JavaScript runs and every chart stays empty.',
        );
    }

    /**
     * The stylesheet has to reach the page, and — since it is also emitted as a
     * preload link — exactly once per URL.
     */
    public function test_stylesheet_is_linked_once(): void
    {
        $html = $this->renderDashboard();

        preg_match_all('#<link rel="stylesheet" href="([^"]+)"#', $html, $m);
        $this->assertNotEmpty($m[1], 'The dashboard must link its compiled stylesheet.');

        $counts = array_count_values($m[1]);
        foreach ($counts as $href => $count) {
            $this->assertSame(
                1,
                $count,
                "Stylesheet {$href} is linked {$count} times; it should be emitted once.",
            );
            $this->assertStringContainsString('tailwind', $href);
        }
    }

    /**
     * Chart.js is published to `window` by assets/app.js, and the templates call
     * it from classic inline scripts. The reachability of that global is what
     * the whole chart pipeline hangs on, so assert the entrypoint's shape here.
     *
     * Read from disk rather than over HTTP: the compiled asset lives under
     * public/assets/, which is build output and absent from a fresh checkout.
     */
    public function test_chart_global_is_assigned_and_registers_controllers(): void
    {
        $app = (string) file_get_contents($this->projectDir().'/assets/app.js');

        $this->assertStringContainsString(
            'window.Chart',
            $app,
            'assets/app.js must publish Chart.js on window; the templates call it from classic scripts.',
        );

        // The vendored build is the ESM bundle: it has no default export and it
        // does not self-register, so both of these have to be explicit.
        $this->assertStringContainsString(
            'registerables',
            $app,
            'Chart.js controllers must be registered explicitly; the ESM bundle no longer does it on load.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '#^\s*import\s+Chart\s+from#m',
            $app,
            'Chart.js has no default export, so `import Chart from "chart.js"` fails at module resolution.',
        );
    }

    /**
     * Boots a client, creates the schema, seeds one receipt, and returns the
     * dashboard HTML. Deliberately self-contained — see the class docblock.
     */
    private function renderDashboard(): string
    {
        $client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $apiKey = new ApiKey();
        $apiKey->setKeyHash(password_hash(bin2hex(random_bytes(32)), \PASSWORD_BCRYPT));
        $em->persist($apiKey);

        $receipt = new Receipt();
        $receipt->setAmount('42.00');
        $receipt->setBusiness('Tesco');
        $receipt->setCategory('Groceries');
        $em->persist($receipt);

        $em->flush();

        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @return array<string, string>
     */
    private function renderedImportMap(): array
    {
        $html = $this->renderDashboard();

        $this->assertMatchesRegularExpression(
            '#<script type="importmap"[^>]*>(.*?)</script>#s',
            $html,
            'Expected the dashboard to render an importmap.',
        );
        preg_match('#<script type="importmap"[^>]*>(.*?)</script>#s', $html, $m);

        $data = json_decode($m[1], true);
        $this->assertIsArray($data, 'The rendered importmap must be valid JSON.');

        /** @var array<string, string> $imports */
        $imports = $data['imports'] ?? [];

        return $imports;
    }

    private function projectDir(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir');
    }
}
