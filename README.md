# Jacker CSS Merges Manager

> Turn 20+ WordPress CSS files into a single request. Same bytes, fraction of the latency.

[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-21759b.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![Version](https://img.shields.io/badge/version-1.0.0-brightgreen.svg)](https://github.com/JackerArchitect/jacker-css-merges-manager/releases)

WordPress performance is not lost to big files. It is lost to the number of round trips. JCSSMM combines every enqueued CSS file on a page into one request. Icon fonts are auto-excluded, each page gets its own cache, and JavaScript is never touched.

**Free. Open source. GPL-2.0+. No API. No server changes. No premium version.**

---

## The problem

A typical WordPress site loads 20+ CSS files. Each one is a separate HTTP request. Each request pays a fixed cost: DNS, TCP, TLS, HTTP headers, server response. Those costs multiply by request count.

20 files × 300 ms average = 6 seconds of pure round-trip waiting.

The real bottleneck is **request count**, not file size. Merging is the direct fix.

## What JCSSMM does

- Combines every enqueued local CSS file into one merged file per page.
- Preserves the original load order and media queries.
- Rewrites every `url()` inside merged CSS to an absolute path.
- Auto-excludes icon font libraries to prevent missing glyphs.
- Generates a separate cache file for each page URL.
- Auto-purges on post save, theme switch, plugin update, menu change, and widget update.
- Adds an admin bar menu with **Purge This Page** and **Purge All**.
- Auto-detects Jacker OneWebP and rewrites background URLs to `.jo.webp` when a matching file exists.

## What JCSSMM does not do

- It does **not** touch JavaScript. No defer, no async, no lazy.
- It does **not** modify CSS content. Only reorganizes `<link>` tags.
- It does **not** add inline styles or Critical CSS.
- It does **not** call external APIs.
- It does **not** require server configuration changes.

## Before and after

Before:

```html
<link rel="stylesheet" href="theme.css">
<link rel="stylesheet" href="elementor.css">
<link rel="stylesheet" href="widget-heading.css">
<link rel="stylesheet" href="post-212.css">
<!-- ...16 more -->
```

After:

```html
<link rel="stylesheet" href="jcssmm/merged-eb79f7557b91.css">
```

Same CSS bytes. One network round trip instead of twenty.

## Requirements

| | |
|---|---|
| WordPress | 6.1 or higher |
| PHP | 7.4 or higher |
| Dependencies | None |

## Installation

1. Download the latest `jacker-css-merges-manager.zip` from Releases.
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Select the ZIP, install, and activate.
4. Merging starts automatically on the next page load.

## Usage

### Settings

**Settings → JCSSMM** shows:

- Merged file count and total size
- Number of tracked pages
- Auto-excluded icon font sources
- Plugin version
- OneWebP integration status

### Per-page cache

Each page gets its own merged file, hashed by the exact CSS it loads. The settings page lists every cached page with a **Purge this page** button. Purging one page never affects another.

### Admin bar

When logged in as an Administrator, a **JCSSMM** menu appears in the WordPress toolbar on every page — frontend and backend:

- **Purge This Page** — regenerates only the current page's merged file
- **Purge All** — clears every merged file and the page map
- **Settings** — jumps to the settings screen

### Excluding pages

In **Settings → JCSSMM → Exclude URL patterns**, add one substring per line. Any page whose URL contains that substring is skipped entirely. Useful for:

```
/checkout/
/cart/
/my-account/
```

## How it works

1. On `template_redirect`, output buffering starts (priority 99, after all styles are enqueued).
2. On the final HTML, the plugin walks `wp_styles()->queue`.
3. Each local, non-excluded CSS file is read, its `url()` values are rewritten to absolute paths, and the contents are concatenated in original order.
4. A hash is computed from the file list, their modification times, the plugin version, and (if OneWebP is active) the OneWebP version and a `.jo.webp` fingerprint.
5. The merged file is written to `wp-content/uploads/jcssmm/merged-<hash>.css`.
6. The first local `<link rel="stylesheet">` in the HTML is replaced with the merged file. Subsequent local links are removed. Icon font links and external stylesheets (Google Fonts) are left untouched.
7. The page URL is recorded in `jcssmm_page_map` for per-page management.

## Cache invalidation

The plugin auto-purges on:

| Hook | When it fires |
|------|---------------|
| `save_post` | Post or page saved |
| `switch_theme` | Theme switched |
| `customize_save_after` | Customizer saved |
| `upgrader_process_complete` | Plugin or theme updated |
| `activated_plugin` | Plugin activated |
| `deactivated_plugin` | Plugin deactivated |
| `wp_update_nav_menu` | Menu saved |
| `widget_update_callback` | Widget saved |

Manual edits via FTP are the only case that requires a manual purge from the settings page or admin bar.

## OneWebP integration

If Jacker OneWebP is active, JCSSMM:

- Rewrites background-image URLs inside merged CSS to `.jo.webp` when a matching file exists on disk.
- Includes the OneWebP version and a `.jo.webp` fingerprint in the merge hash, so merged files regenerate automatically when WebP files change.

To disable this behavior, add a filter:

```php
add_filter( 'jcssmm_rewrite_bg_to_webp', '__return_false' );
```

## FAQ

**Will this break my site?**

No. The plugin only reorganizes `<link>` tags. It never modifies CSS content, never touches JavaScript, never adds inline styles. Icon fonts are auto-excluded. If something looks wrong, deactivate and everything returns to normal instantly.

**Why not use Critical CSS instead?**

Critical CSS solves "the first screen only needs part of the stylesheet." Your real problem is different: many small files, each adding a network round trip. Merging directly reduces request count, which is where the seconds are actually lost.

**Will it cause a flash of unstyled content?**

No. The merged CSS loads the same way as before — blocking, in `<head>`. The browser waits for one file instead of twenty, then paints once. No FOUC, no layout shift.

**Does it work with Elementor, WooCommerce, Gutenberg?**

Yes. All three are fully supported.

**Does it work with caching plugins?**

Yes. It runs before any page cache is written. Clear your page cache once after activation.

**What about icon fonts?**

Font Awesome, Elementor Icons, Dashicons, IcoMoon, Ionicons, and other icon libraries are automatically excluded. They load as separate files exactly as before.

## File structure

```
jacker-css-merges-manager/
└── jacker-css-merges-manager.php
```

The plugin is intentionally a single PHP file. No build step, no Composer, no npm.

## Contributing

Issues and pull requests are welcome at github.com/JackerArchitect/jacker-css-merges-manager.

Please follow WordPress Coding Standards:

- Tabs for indentation
- Yoda conditions in PHP
- `esc_html__()`, `esc_attr()`, `esc_url()` on all output
- Nonce verification on every admin action
- Prefix everything with `jcssmm_`

## License

GPL-2.0-or-later. See LICENSE.

## Related projects

- Jacker OneWebP — Local WebP conversion and lazy loading for WordPress.

## Support

If JCSSMM saves you hours, consider supporting the project:

**Solana:** `EHHPsci6pKbfL71t73KNCXrtanM1TWPrWYJZ1ik1b5FH`

Thank you for keeping open source alive.
