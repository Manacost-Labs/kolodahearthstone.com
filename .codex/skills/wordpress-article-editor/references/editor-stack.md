# Editorial stack map

Use this map to find the owner before changing the article editor.

| Component | Project location | Responsibility | Main risk |
|---|---|---|---|
| Classic Editor | `wordpress/plugins/classic-editor` | Primary classic editing mode and editor selection | Changing mode can alter markup and operator workflow |
| Advanced Editor Tools | `wordpress/plugins/tinymce-advanced` | TinyMCE toolbar and editing capabilities | Button duplication and serialization differences |
| Editor workspace | Blocksy/WordPress admin plus project MU-plugins | Scoped screen cleanup, editor controls and HS Tooltip tabs | Verify the current admin role and avoid widening changes silently |
| AIOSEO add-on | Project MU/plugin configuration when present | Editorial SEO metadata | Save-order, capability and duplicate-field conflicts |
| SEO plugin | Active production SEO plugin (inspect before changes) | SEO panel and metadata | Heavy panel and metadata integrity |
| Tooltip tooling | `wordpress/plugins/hs-tooltip` | Article-specific tooltip controls | Existing metadata and shortcode compatibility |
| Separator quick insert | `wordpress/plugins/separator-placeholder-quick-insert` | Classic and block editor separator insertion | Nonce/REST and dual-editor behavior |
| Deck content | `wordpress/plugins/wp-kolodahearthstone-decks` | Deck import, attachment and shortcode rendering | Remote data, media import and delayed-script exclusions |
| Shortcodes | `wordpress/plugins/wp-kolodahearthstone-shortcodes`, MU compatibility | Article shortcodes and legacy rendering | Compatibility regressions in old articles |
| Blocksy | `wordpress/themes/blocksy`, `wordpress/themes/blocksy-child` | Article templates and layout integration | Parent-theme coupling and upgrade safety |
| Admin load trim | Project MU-plugins (inspect before changes) | Removes unnecessary editor/admin work | Removing a required dependency by mistake |
| Media pipeline | `hs-local-image-optimizer.php`, `manacost-media-unique-filenames.php`, S3/runtime configuration | Upload, optimize and uniquely name media | Missing variants, overwrite, or inaccessible object |
| Cache purge | `wordpress/mu-plugins/manacost-cache-purge.php` | Invalidate updated content | Stale preview/frontend or excessive purge |

## Discovery checklist

1. Record `get_current_screen()`, post type and editor mode.
2. Identify roles/capabilities and any user-ID rollout condition.
3. Trace enqueue, meta-box, TinyMCE, REST/AJAX and save hooks.
4. Check whether an owning plugin can be inactive or unavailable.
5. Inspect the saved article and metadata before changing presentation.

Do not assume every author sees the same editor. Verify an administrator and a representative editorial role.
