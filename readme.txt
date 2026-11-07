=== SEO Console ===
Contributors: artemisdigital
Tags: seo, page speed, core web vitals, technical seo, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Technical SEO audits, page speed monitoring and AI suggestions you review, edit and approve. Every change is logged and can be undone.

== Description ==

SEO Console adds technical SEO tooling for agencies and site owners next to Rank Math or Yoast SEO. It never replaces them and never duplicates their output (titles, meta tags, schema graph, sitemaps, robots.txt).

* **Page speed:** PageSpeed Insights lab data and Chrome UX Report field data, trends and a to-do list.
* **Technical audit:** a background crawl of your own site that finds broken links, missing titles and descriptions, missing image alt text and more.
* **AI suggestions:** Claude proposes SEO titles, meta descriptions and alt text. Nothing is applied until a person reviews, optionally edits and approves it.
* **Change log with undo:** every change SEO Console makes is logged with its before and after value and can be reverted. If the site does not load correctly after a change, the change is undone automatically.
* **Safe Mode:** one switch (or the `SEOC_SAFE_MODE` constant) stops SEO Console from adding anything to public pages.
* **Shared-hosting friendly:** heavy work runs in small background batches (Action Scheduler).

SEO Console works on its own hosting: there is no SEO Console server. The optional external services below are only contacted after you enable the related module and enter your own API key.

== External services ==

SEO Console can connect to the following third-party services. None of them is contacted until you enable the related feature and enter your own API key in SEO Console → Settings. API keys are stored encrypted in your database (or in wp-config.php constants) and are never sent anywhere except to the service they belong to.

= Anthropic API (Claude) =

Used for AI suggestions (SEO titles, meta descriptions, image alt text) and the AI assistant.

* What is sent: the instructions for the task and the content needed for it, for example the post title, an excerpt of the page text, the current SEO title and description, image file names and the surrounding text, and summaries of audit or speed results. Your Anthropic API key is sent in the request header.
* When: only when you (or a background job you started, such as "generate suggestions") request an AI action, after you entered a Claude API key and the monthly AI budget is above 0.
* Endpoint: https://api.anthropic.com/v1/messages
* Terms: https://www.anthropic.com/legal/commercial-terms
* Privacy policy: https://www.anthropic.com/legal/privacy

= Google PageSpeed Insights API =

Used by the Speed module to measure lab performance (Lighthouse) of a few representative pages.

* What is sent: the public URLs of the pages being tested and your Google API key.
* When: only after the Speed module is enabled and a Google API key is entered; on demand and on the daily schedule you configure.
* Endpoint: https://www.googleapis.com/pagespeedonline/v5/runPagespeed
* Terms: https://developers.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Chrome UX Report API =

Used by the Speed module to read real-user Core Web Vitals (field data) for your site and pages.

* What is sent: your site origin and the public URLs being looked up, and your Google API key.
* When: only after the Speed module is enabled and a Google API key is entered; on demand and on the daily schedule you configure.
* Endpoint: https://chromeuxreport.googleapis.com/v1/records:queryRecord
* Terms: https://developers.google.com/terms
* Privacy policy: https://policies.google.com/privacy

SEO Console also sends requests to your own site (health checks after a change and the technical audit crawl). These never leave your site's own host.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/seo-console/` or install it from the Plugins screen.
2. Activate it.
3. Open SEO Console → Settings, choose the modules you want, and (optionally) enter your Claude and Google API keys.

API keys can also be set in wp-config.php:

`define( 'SEOC_CLAUDE_API_KEY', 'sk-ant-...' );`
`define( 'SEOC_GOOGLE_API_KEY', 'AIza...' );`

== Frequently Asked Questions ==

= Does SEO Console replace Rank Math or Yoast SEO? =

No. It works alongside them and writes SEO titles and descriptions through their own fields.

= Will AI change my site without asking? =

No. AI output only ever becomes a suggestion. A person has to approve it, and every applied change can be undone from the Change log.

= Something looks wrong on my site. What do I do? =

Turn on Safe Mode in SEO Console → Settings, or add `define( 'SEOC_SAFE_MODE', true );` to wp-config.php. SEO Console then adds nothing to public pages. You can also undo any change from the Change log.

= Why does SEO Console call the Anthropic API directly instead of the WordPress AI Client? =

SEO Console supports WordPress 6.5 and newer, while the WordPress AI Client (`wp_ai_client_prompt()`) arrived in WordPress 7.0. Calling Anthropic directly also gives us structured (JSON schema) answers and a per-site monthly budget. Using the WordPress AI Client when it is available is on our roadmap.

= What happens to my data when I delete the plugin? =

All SEO Console tables, options and data are removed, unless you turned on "Keep data on uninstall" in Settings.

== Changelog ==

= 0.1.0 =
* First release: core (settings, encrypted API keys, change log with undo, approval queue, background jobs, Safe Mode), Rank Math / Yoast and cache plugin integrations.
