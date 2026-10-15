# Automatic shared-plugin synchronization

The owner authorized both first-party and the six common vendor plugins on
2026-10-15. `config/shared-plugin-sync.json` is the explicit 15-component fleet.
New packages and commercial/theme plugins require a separate admission review.
Site adapters, runtime settings, licenses, credentials, databases, queues and
media are never imported from Manacost.

The timer checks every 15 minutes. It accepts only the latest **successful**
Manacost `Promote production` run and reads the exact deployed SHA from the
successful deploy job log. The workflow's own head SHA is not sufficient because
an operator can promote a different staging-verified SHA. An in-progress/failed
promotion, unavailable log, ambiguous SHA or unexpected live code drift stops
the cycle without installing a package.

One plugin is imported per cycle from Git, with its source SHA, byte digest and
inventory version. The bot creates a short `codex/auto-shared-*` branch and PR,
runs `make check` and the staged secret scan, and tests an isolated loopback
WordPress with native PHP 8.4 and Blocksy. Official core/theme fixture versions
match the live site's public version metadata (verified here: WordPress 6.9.10,
Blocksy 2.1.40). Installation rechecks those versions. The fixture uses synthetic
users/content, no production data or licenses, a private database container and
disabled production Redis/analytics. It checks 320/1440 px tooltip rendering,
native editor draft save/readback, duplicate native uploads and unchanged source
images. It does not run global cron jobs or production analytics.

After all PR checks pass, the bot merges the PR only if main has not changed and
the merged tree is byte-identical to the verified tree. Installation rechecks the
tested package digest, live baseline and site identity. The privileged helper
exports a protected SQL backup and restores it in an unexposed disposable
database, comparing every INSERT row checksum. Then it backs up only the affected
code roots and atomically replaces the package. PHP 8.4 is gracefully reloaded;
only W3 page cache is purged. Origin, Moscow and Novosibirsk must return the
expected canonical `.com` page and legacy `.ru` redirect. Failure restores the
previous code and repeats the probes. Database rollback is deliberately manual
to avoid overwriting concurrent editorial changes; its restore-tested snapshot
is retained outside Git. Runtime tooltip dictionary data is preserved.
An existing Redis-owned object-cache drop-in is refreshed through its public
updater, verified and included in code rollback; foreign drop-ins remain
untouched. Redis settings are retained and the object cache is not flushed.

## Operations

- Installed controller: `/opt/koloda-plugin-sync/current`, a root-owned exact Git
  release. Updating controller code is a separate reviewed deployment.
- Timer: `koloda-shared-plugin-sync.timer`; service runs as `debian`, using its
  existing GitHub credential helper. No new token or license is copied.
- Private status: `/var/lib/koloda-plugin-sync/status.json`; pending PR and tested
  archive metadata remain there until promotion completes.
- Code/SQL rollback snapshots: `/var/backups/koloda-shared-plugin-auto/`.
- Pause: `sudo systemctl stop koloda-shared-plugin-sync.timer`.
- Inspect: `systemctl status koloda-shared-plugin-sync.service` and its journal.
- Run read-only planning: `python3 /opt/koloda-plugin-sync/current/ops/shared_sync.py`.

The cache contains official checksum-verified WordPress core and versioned
Blocksy theme sources matching the target. Loopback ports 18797/18798 and the specifically named staging/restore
containers belong to this controller. Interruption, failed tests/CI, closed PRs,
target main changes or unexpected live edits require inspection; do not discard
evidence or reset the baseline to hide a failure. After review, archive the owned
candidate/pending evidence, remove that owned Git worktree, and retry. Never
prune unrelated containers or reset an operator's source checkout.
