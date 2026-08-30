# Host policy

| Host | Content | Canonical | Robots/indexing |
|---|---|---|---|
| `kolodahearthstone.com` | production | self-referencing `.ru` | indexable according to WordPress/SEO settings |
| `kolodahearthstone.ru` | one-hop redirect to `.com` | redirect target is `.com` | redirect response; no HTML |
| `test.kolodahearthstone.com` | isolated staging content/database | staging-safe policy | fully noindex and access protected |

The legacy redirect must preserve the final `.com` article rendering, images and view behavior without becoming a second SEO competitor. Staging content must not enter production automatically. Compare host-dependent metadata separately from body equivalence.

After content or domain changes, verify canonical, robots, sitemap exposure, Open Graph URL, redirects and cache separately on the primary, mirror and staging hosts.
