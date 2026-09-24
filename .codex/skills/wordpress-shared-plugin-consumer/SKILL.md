---
name: wordpress-shared-plugin-consumer
description: Import a reviewed first-party WordPress plugin and selected safe settings from the Manacost source repository into the separate kolodahearthstone.com repository and staging site.
---

# Shared plugin consumer

Use for requests to reuse a Manacost plugin or admin settings on Koloda. The two projects have separate production WordPress installations, databases and staging sites. Koloda uses Blocksy; the source uses Newspaper. Source code may be shared, runtime options are never shared automatically.

## Prepare

1. Read this project's `AGENTS.md`, `kolodahearthstone-project`, `wordpress-admin-ui`, `wordpress-plugin-dev` and change-impact route. Locate the Manacost source repository from the shared-plugin lock or task context, then inspect the source plugin and its exact reviewed commit.
2. Record the plugin slug, source commit, entrypoint, required WordPress version, capabilities, screens, hooks, assets, option names, dependencies, and any Newspaper-specific behavior. Require a theme-neutral core or a tested Blocksy adapter. Prefer native Settings API controls and screen-scoped assets.
3. List exact option names, types, sanitizers, defaults, portability decision and target transformation. Compare with `config/plugin-settings-policy.json`. Redact secrets, licenses, tokens, cookies, personal data, counters, cache state, site-specific URLs and opaque serialized values. A permitted prefix does not authorize every value.

## Import and verify

1. Copy only the reviewed plugin source to `wordpress/plugins/<slug>` in this repository. Do not copy `/var/www`, the source database, uploads, S3, caches or runtime plugin settings.
2. Add the plugin to `config/shared-plugin-lock.json` with its exact source commit and tree SHA256. `ops/verify-shared-plugin.py` checks every locked plugin; run it before and after any adaptation. If adaptation changes source bytes, keep it in a separate site adapter or record an independently reviewed source commit instead of claiming the copy is identical.
3. Update the active plugin inventory only when activation is part of the planned release. Run `make check`, the project security check and relevant integration/visual checks. Verify the plugin on isolated `test.kolodahearthstone.com` with the intended role, validation failures, save/readback, keyboard/mobile and performance samples.
4. Export a redacted option-name and transformation manifest first. Back up only the approved target options in a protected location, dry-run the explicit allowlist, then import values on staging and verify stored values without exposing them in logs or Git. New defaults in code are preferable to importing configuration values.
5. Promote only the target staging-verified SHA through Koloda's workflow. Verify canonical `.com`, `.ru` redirect, origin and regional proxies. Record source/target SHAs, lock digest, option allowlist, functional checks and rollback.

When no concrete plugin or option values are supplied, prepare the manifest and verification path without claiming deployment or data transfer.
