/*
 * Application entrypoint.
 *
 * Loaded through `{{ importmap('app') }}` in base.html.twig. This is the file
 * that makes the app's JavaScript self-hosted (GUIDING-LIGHT §3.5): every
 * dependency below resolves through Symfony's importmap to a file served from
 * this origin, so there is no CDN, no build step at deploy time and no
 * `node_modules` in the image.
 */

import './stimulus_bootstrap.js';

/*
 * Tailwind's compiled output is NOT imported here.
 *
 * It was, as `import './styles/tailwind.css'`, and that is what broke the app.
 * AssetMapper cannot import CSS from JavaScript the way a bundler does, so it
 * hands the importmap an empty `data:application/javascript,` stub instead. The
 * app's CSP is `script-src 'self'` — no `data:` — so the stub is blocked, and
 * because ES module graphs fail as a unit, app.js, Stimulus, Turbo and Chart.js
 * all failed to load behind one console warning.
 *
 * The stylesheet is now a plain <link> in base.html.twig. See the note there.
 */

/*
 * Chart.js.
 *
 * Two separate mistakes were made here and both had to be fixed.
 *
 * 1. It MUST be a namespace import, not `Chart from 'chart.js'`.
 *
 *    The vendored build is the ESM bundle, whose only export shape is a flat
 *    set of named exports — `Chart`, `BarController`, `CategoryScale` and so
 *    on. It has no `default` export at all, so `import Chart from 'chart.js'`
 *    is a module-resolution error: "The requested module 'chart.js' does not
 *    provide an export named 'default'". That error is thrown before a single
 *    line of this file runs, so `window.Chart` is never assigned and every
 *    chart on the dashboard silently fails.
 *
 * 2. The controllers have to be registered explicitly.
 *
 *    The old CDN tag loaded the UMD bundle, which registers every controller,
 *    scale and plugin as a side effect of loading. The ESM bundle does not: it
 *    exports them as `registerables` and leaves registration to the caller.
 *    Without this call the first chart throws
 *    `"bar" is not a registered controller` — after the importmap is fixed,
 *    which is what makes it a second, independent failure rather than the same
 *    one wearing a different hat.
 */
import * as Chart from 'chart.js';

Chart.Chart.register(...Chart.registerables);

/*
 * Chart.js is exposed as a global on purpose.
 *
 * The templates draw their charts from page-level inline scripts, and those are
 * CLASSIC scripts while this file is a MODULE. Classic inline scripts execute
 * during HTML parsing; module scripts are deferred until after parsing. So a
 * template's `new Chart(...)` cannot `import` this — it has to find it on
 * `window`.
 *
 * The namespace import above is an object whose `Chart` member is the actual
 * constructor, which is what the templates call. Publishing the namespace
 * itself would give them `Chart` the module, not `Chart` the class, so the
 * named member is what goes on `window`.
 *
 * The ordering caveat is handled on the other side: a template that needs
 * `Chart` must not call into it before `DOMContentLoaded`, because deferred
 * scripts are guaranteed to have run by then. See dashboard/index.html.twig.
 */
window.Chart = Chart.Chart;
