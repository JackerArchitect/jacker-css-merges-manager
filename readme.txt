=== Jacker CSS Merges Manager ===
Contributors: jackerarchitect
Tags: css, performance, core web vitals, optimization, elementor
Requires at least: 6.1
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Merge every enqueued WordPress CSS file into one request. Icon fonts auto-excluded. Per-page cache. OneWebP-aware. No API, no server changes.

== Description ==

WordPress performance is not lost to big files. It is lost to the number of round trips. Jacker CSS Merges Manager combines every enqueued CSS file on a page into one merged file. Same bytes, fraction of the latency.

A typical WordPress site loads 20+ CSS files. Each one is a separate HTTP request. Each request pays a fixed cost: DNS, TCP, TLS, HTTP headers, server response. Those costs multiply by request count. Merging directly reduces request count, which is where the seconds are actually lost.

**Core features**

* Combines every enqueued local CSS file into one merged file per page.
* Preserves the original load order and media queries.
* Rewrites every url() inside merged CSS to an absolute path.
* Auto-excludes icon font libraries to prevent missing glyphs.
* Generates a separate cache file for each page URL.
* Auto-purges on post save, theme switch, plugin update, menu change, and widget update.
* Adds an admin bar menu with Purge This Page and Purge All.
* Auto-detects Jacker OneWebP and rewrites background URLs to .jo.webp when a matching file exists.

**What it does not do**

* It does not touch JavaScript. No defer, no async, no lazy.
* It does not modify CSS content. Only reorganizes link tags.
* It does not add inline styles or Critical CSS.
* It does not call external APIs.
* It does not require server configuration changes.

**Why not Critical CSS?**

Critical CSS solves "the first screen only needs part of the stylesheet." Your real problem is different: many small files, each adding a network round trip. Merging reduces request count directly.

**Compatibility**

* WordPress 6.1 or higher.
* PHP 7.4 or higher.
* Elementor, WooCommerce, Gutenberg, WPBakery.
* Works alongside caching plugins.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Merging starts automatically on the next page load.

To fine-tune, go to Settings → JCSSMM.

== Frequently Asked Questions ==

= Will this break my site? =

No. The plugin only reorganizes link tags. It never modifies CSS content, never touches JavaScript, never adds inline styles. Icon fonts are auto-excluded. If something looks wrong, deactivate and everything returns to normal instantly.

= Why not use Critical CSS instead? =

Critical CSS solves "the first screen only needs part of the stylesheet." Your real problem is different: many small files, each adding a network round trip. Merging reduces request count directly, which is where the seconds are actually lost.

= Will it cause a flash of unstyled content? =

No. The merged CSS loads the same way as before — blocking, in the head. The browser waits for one file instead of twenty, then paints once. No FOUC, no layout shift.

= Does it work with Elementor, WooCommerce, Gutenberg? =

Yes. All three are fully supported.

= Does it work with caching plugins? =

Yes. It runs before any page cache is written. Clear your page cache once after activation.

= What about icon fonts? =

Font Awesome, Elementor Icons, Dashicons, IcoMoon, Ionicons, and other icon libraries are automatically excluded. They load as separate files exactly as before.

= How does per-page cache work? =

Each URL gets its own merged file, hashed by the exact CSS it loads. Purging one page does not affect any other page. New pages regenerate on first visit. Content updates auto-purge via WordPress hooks.

= Does it work with Jacker OneWebP? =

Yes. JCSSMM auto-detects Jacker OneWebP. When active, it rewrites background-image URLs inside merged CSS to point at the .jo.webp files Jacker OneWebP generated. Both plugins work independently and integrate cleanly.

= Is this really free? =

Yes. GPL-2.0-or-later. No premium version, no API calls, no server requirements. Free forever.

== Screenshots ==

1. Settings page showing merged file stats and OneWebP integration status.
2. Per-page cache table with individual purge buttons.
3. Admin bar menu with Purge This Page, Purge All, and Settings.

== Changelog ==

= 1.0.0 =
* Initial release.
* Merges all enqueued local CSS files into one request per page.
* Icon font libraries auto-excluded (Font Awesome, Elementor Icons, Dashicons, IcoMoon, Ionicons, and more).
* Per-page cache with individual purge from settings page.
* Admin bar menu: Purge This Page, Purge All, Settings.
* Automatic cache invalidation on post save, theme switch, customizer save, plugin/theme update, plugin activation/deactivation, menu update, and widget update.
* URL rewriting: all url() values inside merged CSS are converted to absolute paths.
* Jacker OneWebP integration: background-image URLs rewritten to .jo.webp when a matching file exists.

== Upgrade Notice ==

= 1.0.0 =
Initial release.