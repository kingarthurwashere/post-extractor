=== NewsBEPA Post Extractor for 263 Media ===
Contributors: kingarthurwashere
Tags: rest api, custom post types, acf, gutenberg, json
Requires at least: 5.5
Tested up to: 6.7
Stable tag: 1.5.4
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Extracts all post types with sections, custom fields, featured images, taxonomies, and Gutenberg blocks via REST API.

== Description ==

Extends the WordPress REST API with a cross-type posts feed, Gutenberg section data, ACF support, and CPT discovery — shaped for the NewsBEPA Flutter app (`WordPressService`).

= What this plugin can do =

* Serve a unified, app-friendly content API across WordPress post types.
* Return embedded author/media/taxonomy fields plus Gutenberg `sections`.
* Expose taxonomy and CPT discovery endpoints for dynamic app layouts.
* Provide site branding payloads via `GET /site-identity`.
* Enforce API-key access and request-rate limiting.
* Accept contributor applications and citizen story submissions.
* Support editorial session login and moderation list/action endpoints.
* Provide operational health metadata via `GET /health`.
* Provide ad placement configuration payloads for app monetization UI.
* Ingest analytics events and expose editorial analytics summaries.
* Track contributor earnings and expose payout ledgers by application id.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/post-extractor` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the settings via Settings -> Post Extractor.

== Frequently Asked Questions ==

= Does this require ACF? =

No, ACF is optional. If installed, the ACF fields will be extracted automatically.

== Changelog ==

= 1.5.4 =
* Initial public release
