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

// Tailwind's compiled output. This is a committed build artefact, not something
// compiled at runtime — see the header of tailwind.config.js for the command
// that regenerates it.
import './styles/tailwind.css';

import Chart from 'chart.js';

/*
 * Chart.js is exposed as a global on purpose.
 *
 * The templates draw their charts from page-level inline scripts, and those are
 * CLASSIC scripts while this file is a MODULE. Classic inline scripts execute
 * during HTML parsing; module scripts are deferred until after parsing. So a
 * template's `new Chart(...)` cannot `import` this — it has to find it on
 * `window`.
 *
 * The ordering caveat is handled on the other side: a template that needs
 * `Chart` must not call into it before `DOMContentLoaded`, because deferred
 * scripts are guaranteed to have run by then. See dashboard/index.html.twig.
 */
window.Chart = Chart;
