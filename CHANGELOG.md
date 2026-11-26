# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-26

### Fixed

- **Failed requests no longer pass silently.** Sabre's `Client::request()` does not throw on HTTP errors, so
  `write()`, `writeStream()`, `delete()`, `deleteDirectory()`, `createDirectory()`, `move()` and `copy()` used
  to "succeed" on `401`, `403`, `423`, `507`, etc. They now throw the matching Flysystem exception.
- `read()` / `readStream()` no longer return the server's XML error page as file contents on non-`404` errors.
- `fileExists()` and `directoryExists()` only return `false` for `404`; authentication, permission and network
  errors now throw `UnableToCheckFileExistence` / `UnableToCheckDirectoryExistence`.
- A `baseUri` without a trailing slash made every path resolve against the *parent* folder
  (`…/files/admin` + `a.txt` → `…/files/a.txt`). The trailing slash is now added automatically.
- Listing returned wrong paths when the base URI contains encoded characters, e.g. user ids that are e-mail
  addresses (`/files/john%40example.com/`). Hrefs are now decoded with `rawurldecode()` and compared against the
  decoded base path.
- `UnableToListContents` and `UnableToCreateDirectory` were created with wrong arguments (the error message was
  passed as the `$deep` flag and as the directory name).
- Moving or copying a file onto itself no longer fails.
- `move()` and `copy()` create missing parent directories of the destination.
- Simulated visibility is now removed on `delete()` / `deleteDirectory()` and carried over on `move()`.
- `writeStream()` stored the visibility twice and ignored the stream's current position.
- Streams from user-space wrappers that claim to be seekable but are not (e.g. PSR-7 bodies wrapped by Guzzle) are
  handled correctly.

### Added

- **Chunked uploads**: files larger than `chunkSize` (default 10 MiB, `null` disables it) are uploaded through
  Nextcloud's chunked upload API (`/remote.php/dav/uploads/`). Works with chunking v2 (Redis/Memcached) and the classic
  assembly. A failed upload is cleaned up and reported.
- **Checksums**: the adapter implements `ChecksumProvider`. MD5 and SHA1 are sent with every upload (`OC-Checksum`),
  so `checksum()` is answered from Nextcloud's metadata; other algorithms and files without a stored checksum are
  hashed from their contents.
- **Public URLs**: the adapter implements `PublicUrlGenerator`. `publicUrl()` returns a direct download link backed by
  a read-only public link share, reusing an existing one when possible.
- **`VisibilityMode::PublicLinks`**: optional visibility backed by public link shares, persisted on the server. The
  default `VisibilityMode::InMemory` keeps the previous behaviour.
- **`searchDeepListing`**: optional deep listings with one `SEARCH` request instead of one PROPFIND per directory
  (about 34× faster on a 130-entry tree), with automatic fallback to PROPFIND.
- `NextCloudAdapter::fromCredentials($serverUrl, $username, $password, folder: ..., userId: ...)` builds the WebDAV
  URL for you.
- The constructor also accepts a pre-configured `Sabre\DAV\Client` (custom timeouts, TLS options, proxies, …).
- `UnexpectedStatusCode` exception, attached as `getPrevious()` of every Flysystem exception caused by an HTTP
  error. It exposes the HTTP method, the status code and Nextcloud's own error message.
- `listContents()` now returns the MIME type of files (Nextcloud sends it in the same PROPFIND response).
- Fast, server-less unit test suite (`composer test:unit`), the official Flysystem suite run twice (default
  configuration and all features on), and a scenario suite against real Nextcloud: 25 MiB non-seekable streams,
  chunking, checksums, Unicode and odd file names, public links, quota (507) and authentication errors, user ids with
  spaces and `@`. Verified against Nextcloud 33 and 35, with and without Redis.
- `docker-compose.redis.yml` to run the local Nextcloud with Redis.
- PHPStan at `level: max` for `src/` and `tests/`, PHP-CS-Fixer configuration (PER-CS), `.editorconfig`,
  Dependabot.

### Changed

- **Basic authentication is now sent preemptively** when no `authType` is configured. Previously cURL negotiated
  between Basic and Digest, so *every* request was sent twice (first answered with `401`). This halves the number
  of HTTP requests. Pass `'authType' => Client::AUTH_DIGEST` (or any other) to keep the old behaviour.
- Writing streams uploads directly from the stream (or a temporary file for non-seekable streams) instead of reading
  it into a string first, so large files are no longer loaded into memory on upload.
- `readStream()` uses `php://temp` instead of `php://memory`, so big downloads spill to disk.
- Listing a directory that does not exist yields an empty result (like Flysystem's local adapter) instead of
  throwing.
- `deleteDirectory('')` refuses to delete the user's root folder.
- `composer.json`: added `"php": "^8.2"`, moved the test namespace to `autoload-dev`, removed the
  `larapack/dd` runtime dependency and the redundant `suggest` section, added keywords and support links.
- PHPUnit configuration migrated to the PHPUnit 11 schema; split into `unit` and `integration` suites; Nextcloud
  connection details come from environment variables.
- Docker setup now uses the official Nextcloud image's auto-installer with SQLite and a healthcheck
  (`docker compose up -d --wait`). The custom `Dockerfile`, entrypoint script and MariaDB container were removed.
- CI: PHP 8.2–8.5 matrix (plus lowest dependencies), PHPStan job, integration tests against the `production`
  (without Redis) and `stable` (with Redis) Nextcloud images, Composer caching, and no more `sleep 60`.
- `.gitattributes` keeps tests and tooling out of the Packagist dist archive.

### Removed

- `larapack/dd` is no longer installed with the package.

## [1.0.0] - 2025-04-28

### Added

- Initial release of `marktaborosi/flysystem-nextcloud`.
- Implemented full Flysystem v3 adapter compatibility for Nextcloud WebDAV.
- Supported operations:
  - Reading and writing files.
  - Creating and deleting directories.
  - Copying and moving files and directories.
  - Retrieving file metadata (size, last modified, MIME type, visibility).
- Provided Docker-based environment for integration testing.
- Full test coverage using `league/flysystem-adapter-test-utilities`.
- Additional integration tests for MIME type detection.

[Unreleased]: https://github.com/marktaborosi/flysystem-nextcloud/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/marktaborosi/flysystem-nextcloud/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/marktaborosi/flysystem-nextcloud/releases/tag/v1.0.0
