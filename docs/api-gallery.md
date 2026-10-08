# Native API galleries on Koloda

Shared source: Manacost commit `99c7b6e7730265210d42172dda868fca6925c9bf`.
Gallery 1.0.4 and read-only API client 1.0.0 are separate regular plugins.
Their exact source-file mapping and SHA256 tree digests are pinned in
`config/shared-plugin-lock.json`. No source-site settings or data are imported.

## Compatibility contract

| Surface | Koloda behavior |
| --- | --- |
| WordPress/PHP | 6.9.7 / 8.4, plugin minimum PHP 8.2 |
| Editor | Classic Editor post/page media button; upload_files and edit_post |
| API | Public api.kolodahearthstone.com data; bounded, cached server requests |
| Images | New native attachments; immutable originals, native media/S3 hooks |
| Gallery | Standard `[gallery]`; order, captions, size, link and 1–9 columns |
| Ratings | Optional `hs_ratings="1"`, five stars below each image, own local table |
| Blocksy | Separate `koloda-native-gallery` MU layout adapter |
| Gutenberg | Existing gallery blocks retain core behavior; API picker is Classic only |
| Newspaper | No vendor dependency is required on Koloda |

The Blocksy adapter loads only on singular articles/pages containing a gallery
shortcode and only with Blocksy as the parent theme. It lays out ordinary native
galleries inside `.entry-content`, including galleries made without the API
picker. Rated galleries keep the shared plugin's compact responsive grid. No
background is added. Columns shrink when the article cannot fit 160 px cells;
rated touch layouts retain the shared 44 px star targets. Widget galleries and
Gutenberg gallery blocks are outside the adapter's selectors. No content is
rewritten, and frontend rendering does not call the API.

## Data and activation

Portable option allowlist: **empty**. Do not copy options, transients, attachments,
votes, cookies, accounts, caches or a database from Manacost. Activate the
`manacost-koloda-api` dependency before `hs-api-gallery`. The first authorized
admin request creates the target site's own `{prefix}hs_gallery_votes` table and
`hs_api_gallery_schema=1`, plus a daily owned retention event. This is additive;
existing WordPress tables and media are preserved. Source-site ratings are not
merged into the new site's database.

Votes require published, password-free articles, persisted gallery membership,
nonce and same-origin checks. Guest identity cookies appear only after voting;
account privacy export/erasure and one-year anonymization remain supported.
Provider reads can be disabled with `MANACOST_KOLODA_API_ENABLED=false`; the
gallery UI can be disabled with `HS_API_GALLERY_ENABLED=false`. Existing local
images remain usable when the provider is unavailable.

## Verification and rollback

Run `make check` and the staged secret scanner. The read-only staging fixture
`tests/api-gallery/render-layout.php` uses six existing attachments to produce
actual core plain/rated gallery markup. Run it with `KOLODA_GALLERY_SOURCE` set
to this reviewed source directory. Save output as
`.artifacts/api-gallery/markup.json` and the installed Blocksy main bundle as
`.artifacts/api-gallery/blocksy.css`. `node tests/api-gallery/layout.mjs` checks
column settings, mobile widths, touch star targets, transparent backgrounds,
widget isolation and block-gallery isolation. Optional `PLAYWRIGHT_PACKAGE`
locates an existing pinned Node toolchain. `KOLODA_GALLERY_PHASE=before` excludes
the adapter to reproduce the original vertical layout.

Release only after staging editor/import/save/preview/revision/vote checks and
regional delivery checks. Keep production promotion separate. Roll back by
deactivating the two newly installed regular plugins and restoring the previous
MU layout file set. Do not delete attachments, vote tables or stored shortcodes;
core still renders the native galleries. The table/schema/event are owned runtime
state, not portable configuration. Never drop them automatically on rollback.
