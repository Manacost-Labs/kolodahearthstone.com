# Host policy

## Contract matrix

| Surface | `kolodahearthstone.com` | `kolodahearthstone.ru` | `test.kolodahearthstone.com` |
|---|---|---|---|
| Purpose | Primary production and SEO source | Legacy-domain redirect | Isolated integration/staging |
| Indexing | Indexable unless page-level editorial policy says otherwise | Redirect response; never an indexable document | Always `noindex, nofollow, noarchive` |
| Canonical | Matching `.com` URL | Not applicable (redirect target is `.com`) | Must never become a production canonical |
| Sitemap | Production sitemap on `.com` | Redirect to `.com`; never advertise a mirror sitemap | Do not advertise an indexable sitemap |
| Internal public links | Prefer `.com` | Redirects to `.com` | Stay inside staging during tests |
| Structured-data URLs | `.com` | Not emitted by redirect | Never leak into production output |

## Audit rules

1. Inspect status and `Location` for the `.ru` redirect, and inspect HTTP `X-Robots-Tag` plus HTML robots meta on `.com` and staging. A protective header is mandatory for staging even if cached HTML is malformed.
2. Require exactly one self-canonical `.com` URL on normal production HTML documents. The `.ru` response must be a single permanent redirect with no HTML body contract.
3. Compare cold and warm responses because WP Rocket, Redis, Cloudflare, and RU proxy caches can preserve different metadata.
4. Check redirects separately. A redirect response must not escape to staging or create a `.ru`/`.com` loop.
5. Check robots and sitemap endpoints separately from page HTML. The legacy redirect must not expose a sitemap; production sitemap URLs must use `.com`.
6. Inspect rendered JSON-LD as JSON. Entity URLs, image URLs, author and dates must agree with visible content and the canonical host.

## Failure severity

- Block release: `.ru` stops redirecting in one hop; staging becomes indexable; `.com` canonical points away; multiple canonicals; staging URL leaks into production schema/sitemap.
- Fix before editorial publication: missing title/description, invalid JSON-LD, missing representative image, broken internal link, image without meaningful alt where the image conveys content.
- Advisory: title length, keyword density, automated SEO score, or non-critical alt text on decorative images.
