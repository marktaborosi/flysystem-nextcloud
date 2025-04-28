# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
