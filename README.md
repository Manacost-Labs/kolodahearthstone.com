# KolodaHearthstone WordPress

Source and safe configuration for the Blocksy-based WordPress site `kolodahearthstone.com`.

## Repository boundaries

- `wordpress/plugins/` — first-party plugin source tracked for this site.
- `wordpress/themes/blocksy-child/` — update-safe theme extensions.
- `config/` — redacted runtime, plugin, theme, contract, and shared-plugin manifests.
- `.agents/skills/` — canonical AI instructions; `.claude/skills/` and `.codex/skills/` are synchronized copies.
- Runtime WordPress core, commercial packages, uploads, database, cache, logs, and secrets are provisioned outside Git.

## Domains

`kolodahearthstone.com` is canonical. `kolodahearthstone.ru` redirects to it. `test.kolodahearthstone.com` is isolated staging and must remain `noindex`.

## Checks

```bash
make check
make skills-sync-check
make blocksy-audit
```

Production promotion is a separate, manually authorized step after staging verification.
