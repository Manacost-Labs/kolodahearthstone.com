# Shared plugin synchronization

Media source: Manacost commit `67afe65e4553d08282ec7b1ebd6040bc3627033a`.
The optimizer bootstrap and all eight helper classes, upload accelerator 2.0.0,
and unique filename guard 1.0.1 are copied without site-specific changes.
The optimizer keeps the quality-v1 WebP/AVIF profiles and source images intact.
Missing sources and failed encoders retry instead of recording success.
The upload response supplies thumbnail, medium and medium_large previews;
remaining sizes are generated before the optimizer receives the attachment.
Filename metadata is re-read between uploads within one request.

No runtime options, media, credentials, themes or third-party plugins are imported.
Blocksy remains the active theme. Gallery 1.0.4, Koloda API client 1.0.0
and editor AI 1.1.1 already match Manacost. Tooltip 1.1.15 is pinned in
`config/shared-plugin-lock.json`; its complete source tree is shared unchanged.
It adds non-collectible cards including BE_222t and retains the existing
Koloda API supplement and dictionary failure guards.

The public staging host was retired on 2026-10-13. Release verification therefore
uses a disposable loopback WordPress with its own database and Blocksy, from the
exact committed source archive. Production promotion must use that same archive.
Back up the affected runtime plugin paths before installation; rollback restores
only those paths. Preserve attachments, originals, options and queued jobs.

Verification on disposable WordPress 6.9.10 / Blocksy 2.1.40 / PHP 8.2:

- Five warm native editor-upload samples after one excluded warmup per phase.
  Median TTFB: 693 → 694 ms; editor preview: 863 → 866 ms; peak PHP memory:
  4 → 4 MiB; long tasks: 0 → 0. SQL: 51 → 56 queries because the filename
  guard deliberately re-reads metadata instead of reusing stale query results.
  This is a correctness change with unchanged observed latency, not a SQL
  performance improvement. Production uses PHP 8.4; the shared source also
  retains the upstream compatibility and cross-runtime image-editor tests.
- Six same-name uploads retained unique paths and the original SHA256.
  Deferred sizes finished, six valid WebP sidecars matched source dimensions,
  and a same-request offloaded-name collision received a unique filename.
- Real editor lookup/insertion of BE_222t, draft save/reopen and preview passed.
  Autosave preserved the parent, native revision restore preserved post_content,
  and the disposable article published successfully. The anonymous BE_222t popup
  fetched its real image at 320/390/768/1024/1440 px with touch and mouse,
  without horizontal overflow or JavaScript errors.

The five additional filename queries are an intentional consistency cost.
No performance gate or budget is raised to hide that difference.
