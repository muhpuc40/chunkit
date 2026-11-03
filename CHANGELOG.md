# Changelog

All notable changes to `muhpuc40/chunkit` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project follows
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-03

First release.

### Added

- Chunked, resumable uploads for Laravel 11 and 12 over four JSON endpoints:
  `POST /initiate`, `POST /{uploadId}/chunk`, `GET /{uploadId}/status` and
  `POST /{uploadId}/complete`. Routes live under a configurable prefix
  (default `chunkit`) and use the `web` middleware group by default.
- Server-authoritative resume point. The client sends the start byte of each
  chunk in an `Upload-Offset` header. A mismatch answers `409` with the server's
  real resume point (the first gap in the received chunks) in the `Upload-Offset`
  response header.
- Concurrency safety: every chunk write runs inside
  `Cache::lock("chunkit:{uploadId}")`, and duplicate or retried chunks are
  idempotent no-ops.
- Queued merge (`MergeChunksJob`) that streams the chunks into the final file in
  constant memory. It is idempotent and retry-safe (`$tries = 3`,
  `$backoff = [10, 60, 300]`, `$timeout = 600`), and runs on the host app's
  default queue unless `queue_connection` / `queue_name` are configured.
- Defence in depth for the finished file: the file name is sanitised, blocked
  extensions (including hidden double extensions) are refused, and the merged
  file's real magic bytes are checked against `allowed_mimes` before the upload
  is marked completed. A rejected file is deleted and the upload is marked
  `failed` with a `failure_reason`, which `/status` returns.
- `chunkit:prune` command (with `--dry-run`) that deletes abandoned uploads and
  their chunk directories. It does not register a schedule itself.
- `ChunkStorage` contract with a `LocalChunkStorage` implementation, so other
  drivers can be added without changing the public API.
- Publishable config (`chunkit-config`) and migration (`chunkit-migrations`).
  The migration is never loaded automatically, and the package ships no
  migration for the queue tables.
- Pest test suite on Orchestra Testbench 9 and 10, a Pint check and a GitHub
  Actions matrix (PHP 8.2 / 8.3 / 8.4 on Laravel 11 / 12).

### Known limits

- Local-driver disks only, for both chunk storage and the final file.
- Uploads are not tied to a user. Add an auth middleware to `chunkit.middleware`
  if only signed-in users may upload.

[0.1.0]: https://github.com/muhpuc40/chunkit/releases/tag/v0.1.0
