# SM - Auto Internal Linker

[![Licence](https://img.shields.io/badge/LICENSE-GPL2.0+-blue)](./LICENSE)

- **Developed by:** Martin Nestorov  
  – Explore more at [nestorov.dev](https://github.com/mnestorov)
- **Plugin URI:** <https://github.com/mnestorov/smarty-auto-internal-linker>

---

## Overview
**SM – Auto Internal Linker** is a lightweight WordPress plugin that automatically turns the **first occurrences of predefined keywords or phrases inside your posts** into bold, SEO-friendly internal links.  
It was built for high-throughput news and magazine sites where manual linking is too slow, but it works just as well on any blog that wants tighter on-site navigation and better crawlability.

---

## Description
The plugin watches every post/page on render, finds matching keywords in the body (never in headings, quotes, or existing anchors) and injects an `<a>` element that :

* points to the URL you defined,
* inherits the exact capitalisation the reader sees (e.g. *NFT*, *nft* or *Nft*),
* carries an optional **nofollow** attribute,
* is styled with the class **`.smarty-aul-link`** (bold by default).

A simple admin screen lets editors add, edit, or remove keywords and set per-keyword/link limits without touching code.  
A WP-Cron task retro-fits links into older posts every hour, keeping your archive up to date as new keywords are added.

---

## Features
| Category | What you get |
| -------- | ------------ |
| **Smart linking** | Case-insensitive matching with word boundaries, preserves original casing in output. |
| **Per-post safety limits** | Global cap (default **3**) and per-keyword cap (1 – 3) prevent over-linking. |
| **Scope control** | Skips headings `<h1-h6>`, blockquotes, existing `<a>` tags. |
| **Admin UI** | Add / edit / delete keywords, set dofollow / nofollow, max links, all from WP Dashboard. |
| **Cron for old posts** | Hourly routine updates 20 older posts (older than a week) with fresh links. |
| **Bold by default** | Injected links get `font-weight:bold;` via a tiny inline stylesheet (easily override). |
| **Performance** | MySQL table + 24 h transient cache; zero queries on most page loads. |
| **No classes, pure functions** | 100 % procedural PHP with `smarty_aul_` prefix and `function_exists` guards. |
| **I18n-ready** | Text domain `smarty-auto-internal-linker`; ready for translation files in `/languages`. |

---

## Installation
1. **Download the plugin**  
   grab `smarty-auto-internal-linker.php` from the repo’s latest release.

2. **Upload & activate**  
   *Plugins → Add New → Upload Plugin* → choose the file → *Install* → *Activate*.

3. **(First-run)**  
   On activation the plugin will:  
   * create the table `{$wpdb->prefix}smarty_aul_dictionary_keywords`,  
   * schedule the hourly WP-Cron event.

4. **Done.**  
   A new top-level menu **“Auto Links”** appears in the admin sidebar.

---

## Usage
1. **Add a keyword**  
   *Auto Links → Add Keyword* → fill in the phrase, target URL, choose max links/post and rel.

2. **Edit / delete**  
   Click *Edit* next to an entry, update the fields and click *Update Keyword*.  
   Or click *Delete* to remove it entirely.

3. **Styling**  
   Links are bold by default via `.smarty-aul-link`. Override in your theme:  
   ```css
   .smarty-aul-link{font-weight:600;color:#1e90ff;text-decoration:none;}

4. **Testing**
    View any single post containing the phrase – on the first render you’ll see the new link.

## Frequently Asked Questions

**Q:** The plugin isn’t linking anything. What’s wrong?

**A:** Make sure the keyword exists exactly (case doesn’t matter but spaces do). Ensure you haven’t hit the global (3) or per-keyword cap.

**Q:** Can I link inside custom post types?

**A:** Change the early guard in `smarty_aul_filter_content()` to add your CPT condition.

**Q:** How do I disable the bold style?

**A:** Either dequeue the inline style:


```php
add_action( 'wp_enqueue_scripts', function(){
    wp_dequeue_style( 'smarty-aul-inline' );
}, 20 );
```
or override `.smarty-aul-link{font-weight:normal;}` in your theme CSS.

**Q:** Will it slow my site down?

**A:** No – the dictionary is cached for 24 h and the DOM manipulation runs only on single post views.

**Q:** How can I import 500 keywords at once?

**A:** Insert rows directly into `wp_smarty_aul_dictionary_keywords` (or use WP-CLI) and then `delete_transient( 'smarty_aul_dictionary_cache' );`.

## Translation

This plugin is translation-ready. Add translations to the `languages` directory.

## Changelog

For a detailed list of changes and updates made to this project, please refer to our [Changelog](./CHANGELOG.md).

---

## License

This project is released under the [GPL-2.0+ License](http://www.gnu.org/licenses/gpl-2.0.txt).
