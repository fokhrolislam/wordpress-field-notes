# WordPress field notes

Things that cost me a debugging round on live client sites, written down so they cost nobody a second one. Each note says where it was measured, what actually happens, and what to do. Small drop-in fixes live in `mu-plugins/` and `scripts/`.

## Contents

- [WP Super Cache serves one guest's WooCommerce basket to everyone](#wp-super-cache-serves-one-guests-woocommerce-basket-to-everyone)
- [`wp_update_post` silently renames your page to `slug-2`](#wp_update_post-silently-renames-your-page-to-slug-2)
- [WPCode snippets from WP-CLI: three traps](#wpcode-snippets-from-wp-cli-three-traps)
- [Elementor `should_render` fires after the children are already rendered](#elementor-should_render-fires-after-the-children-are-already-rendered)
- [Elementor 4.x gotchas](#elementor-4x-gotchas)
- [Duplicating a post double-serializes page-builder meta](#duplicating-a-post-double-serializes-page-builder-meta)
- [Hosting WAFs reject small raw PUT bodies](#hosting-wafs-reject-small-raw-put-bodies)
- [Tawk.to widget: what you can and cannot restyle](#tawkto-widget-what-you-can-and-cannot-restyle)
- [Auto-loaded PHP folders need a crash guard](#auto-loaded-php-folders-need-a-crash-guard)
- [Chrome PDFs show grey boxes outside Chrome](#chrome-pdfs-show-grey-boxes-outside-chrome)
- [A missing doctype freezes Lenis smooth scroll](#a-missing-doctype-freezes-lenis-smooth-scroll)

---

## WP Super Cache serves one guest's WooCommerce basket to everyone

Measured on a live jewellery shop (WP Super Cache 3.1.3, WooCommerce 11, WoodMart). WP Super Cache only recognises the `wordpress_logged_in`, `comment_author` and `wp-postpass` cookies. A **guest** with a basket who hits an uncached page gets that page stored for everyone, header basket count, subtotal and their session-tied nonce included. Strangers saw "1 item, £1,199.99" for a few seconds until cart fragments corrected it. WooCommerce only sets `DONOTCACHEPAGE` on cart, checkout and account.

**Fix:** `mu-plugins/wpsc-woo-basket-guard.php`.

1. Register the state cookies with `wpsc_add_cookie()` so visitors who carry them are not served the shared copy.
2. On `wp_cache_ob_callback_filter`, define `DONOTCACHEPAGE` when any of those cookies arrived. Read `$_COOKIE` at plugin load, not inside the callback: WooCommerce unsets a stale basket cookie mid-request.
3. Set `$wp_cache_no_cache_for_get = 1`.

Test: guest with a basket opens an uncached page, then a cookieless `curl` of the same page must show count 0. Also note WPSC's own guards skip 404 and fatal errors but not other non-200 pages.

## `wp_update_post` silently renames your page to `slug-2`

`wp_update_post(['ID' => .., 'post_content' => ..])` re-runs `wp_unique_post_slug`. If an **attachment** (or another page under the same parent) already uses the page's slug, WordPress renames the page to `slug-2`. The old URL 404s; with Rank Math's 404-to-home redirect on, it looks like a redirect rather than an error, and it went unnoticed for a day.

**Fix:** `scripts/slug-guard.php`. Compare `post_name` before and after every content update and fail loudly if it changed. For content-only edits use `$wpdb->update` on `post_content` plus `clean_post_cache()`, which skips the slug check. When verifying, request the page's own URL with redirects off, not only `get_permalink()`.

## WPCode snippets from WP-CLI: three traps

1. `wp_insert_post` fails with "Content, title, and excerpt are empty" although both are set. WPCode hooks `wp_insert_post_empty_content` and blocks writes when the current user lacks `wpcode_edit_snippets`; WP-CLI runs as user 0. Pass `--user=<admin>`. Do not disable the filter, it is a real guard.
2. Type and location are **taxonomies** (`wpcode_type`: css, js, html, php; `wpcode_location`: site_wide_header, everywhere, …), not meta. Active = `publish`, disabled = `draft`.
3. The front end reads only the `wpcode_snippets` option and never rebuilds it. `wpcode()->cache->delete_cache()` writes an empty array, which the front end reads as "no snippets", and every snippet stops outputting. After any change call `wpcode()->cache->cache_all_loaded_snippets()` and confirm `get_option('wpcode_snippets')` lists everything.

Verify by curling the page and grepping for a string from each snippet, never by trusting the admin screen. `scripts/wpcode-create-snippet.php` does all three.

## Elementor `should_render` fires after the children are already rendered

Measured on Elementor 4.2.4. `elementor/frontend/container/should_render` runs inside `print_element()` only after the container's content has been rendered into an output buffer. A map built there and read by `elementor/widget/render_content` for a child button was always empty.

**Rule:** to hide a container, `should_render` is fine. To change anything inside it (a child's link, text, settings), prepare it on `elementor/frontend/container/before_render`, which fires before the children print. Related: phone-only posters need `hide_desktop` **and** `hide_tablet`; Elementor's tablet range is only 768 to 1024.

## Elementor 4.x gotchas

- **Loop Grid post count:** `post_query_posts_per_page` is inert; the live key is the widget-level `posts_per_page` (defaults to 6).
- **`li::after` belongs to Elementor.** `widget-icon-list.min.css` uses `.elementor-icon-list-item::after` with `inset-inline-start:0`. A custom rule setting `right` + `width` but not `left` loses and the pseudo-element pins to the left edge. Make the element a real flex sibling instead.
- **Nested tabs title alignment:** the text is painted by `.e-n-tab-title-text` through `--n-tabs-title-text-align`, which defaults to `center` when Align Title is unset. Set `title_alignment: "start"`.
- **Image widget `width: 0%` in loop templates** renders letterboxed; the wrapping `<a>` is inline-block, so `width:100%` collapses. Add `a{display:block}`.
- **Template `custom_css` silently overrides native controls.** When a native setting is ignored, grep the template's `custom_css` first.
- **`selector > .elementor-element` breaks both ways:** boxed containers insert `.e-con-inner`, full-width ones do not.
- **Containers use `_margin`, not `margin`.** The wrong key silently does nothing.
- **Closed nav panels are not `display:none`** but `display:flex; height:0; overflow:visible`; absolutely-positioned pseudo-elements escape. Gate them on `.e-active`.
- **Background lazy-load guard blanks sections** when the IntersectionObserver does not fire (3 of 4 loads). Add `css_classes: e-no-lazyload` to the container.
- **`_elementor_element_cache` is load-bearing.** Writing `_elementor_data` and regenerating CSS is not enough; delete that meta row or the front end serves old markup.

## Duplicating a post double-serializes page-builder meta

A German consulting site threw "There has been a critical error" on every publish. The fatal: `L_Plus_Generator::plus_dependency_widgets(): Argument #1 ($elements) must be of type array, string given`. The Plus Addons stores a widget list in `tp_widgets` post meta; **duplicating a post serializes it again**, so the copy holds a string, and a copy of a copy holds three layers. 9 of 345 posts were broken, nearly all named "(copy)".

**Fix:** unwrap the layers back to an array for the affected rows, then a small mu-plugin on `added_post_meta` / `updated_post_meta` that repairs the value on write, falling back to an empty array (the addon rebuilds it on `wp_footer`). Since the page builder turned out to be unused on that site, it was removed afterwards, which removed the bug's source for good.

## Hosting WAFs reject small raw PUT bodies

On a Hostinger-hosted site, raw `curl -X PUT --data-binary @file` uploads returned 403 for every file under about 50 KB and 200 for larger ones. The firewall inspects small bodies and rejects raw binary. Upload as multipart (`-F "file=@path;type=image/jpeg"`) and verify the md5 server side. Headless Playwright on the same host gets the "Checking your browser" page; verify with a real browser profile.

## Tawk.to widget: what you can and cannot restyle

- Tawk picks phone vs desktop offsets by **user agent**, not width; iPads report a Mac UA and land on the theme's sticky toolbar. Give both keys the same offset and lift by screen width yourself.
- Every iframe property is inline `!important` (inset, size, transform, shadow, radius), so stylesheets cannot move it. The individual **`translate`** property is not locked: `#min-widget iframe { translate: Xpx Ypx }` moves the launcher from CSS.
- The iframes are same-origin: the parent can inject a `<style>` into `#min-widget iframe`'s document. Tawk rewrites styles on state changes, so re-apply from a MutationObserver.
- Tawk does not render for a `HeadlessChrome` UA; test with a normal UA.

## Auto-loaded PHP folders need a crash guard

Remote-operation connectors that auto-load every file in a sandbox folder usually have a crash guard: one fatal writes a `.crashed` marker and from then on **no** sandbox file loads. Nothing errors on the front end; features just vanish together. When "a feature stopped although its file is untouched", look for the marker first, remove the offending file, lint every remaining file, move the marker out. Never drop one-off test scripts (CLI-only constants like `STDERR` are fatal under the web SAPI) into an auto-loaded folder.

## Chrome PDFs show grey boxes outside Chrome

A brand guide printed from headless Chrome looked right in Chrome and came back from a client as "corrupted" on an iPhone. `box-shadow`, gradients and filters print as shading objects, and viewers that handle them differently draw grey or black boxes. pdf.js showed the same fault, so it works as a local check. Run [`scripts/print-flat.js`](scripts/print-flat.js) in the page before printing: shadows become a thin outline with no layout shift, gradients become their first colour. All 14 pages then rendered in pdf.js with no warnings. Not re-checked on a physical iPhone, so ask the person who reported it to confirm.

## A missing doctype freezes Lenis smooth scroll

Adding Lenis to a hand-written page stopped the wheel from scrolling at all. The page had no `<!doctype html>`, so the browser ran it in quirks mode, where `<body>` is the scroller, while Lenis scrolls `<html>`. Add the doctype, then diff a screenshot, because leaving quirks mode can shift the layout (5px in this case). Also set `html.lenis { scroll-behavior: auto !important; }` and put `data-lenis-prevent` on inner scrollers.

## wptexturize breaks a link with an inline SVG in its style

An `<a style="--icon:url('data:image/svg+xml;utf8,<svg ...>')">` looks fine in the database and comes out as visible text on the page. The `<` inside the attribute makes `wptexturize` treat the tag as finished, so it escapes the rest of it and turns `--` into an en dash. Put the icon in a file and point the style at its URL, so no `<` sits inside the attribute.

## Rank Math custom term variable can come back empty

`%customterm(my-taxonomy)%` in a post type title template rendered empty on a custom post type here, and Rank Math then dropped the text around it without any warning. Test a template variable on a live post before relying on it. A small `rank_math/frontend/title` filter that reads the term and skips posts with a custom title did the job and also covers posts added later.

## License

MIT
