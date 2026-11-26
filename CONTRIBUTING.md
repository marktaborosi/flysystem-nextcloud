# Contributing

Thanks for helping out! Bug reports, fixes and new features are all welcome.

## Workflow

1. Fork the repository and create a branch from `main`.
2. `composer install`
3. Make your change, with tests:
   - **Unit tests** (`tests/Unit`) use `FakeDavClient`, which replaces only the network layer of the Sabre
     client. Prefer them for error handling and edge cases.
   - **Integration tests** (`tests/Integration`) run against a real Nextcloud
     (`docker compose up -d --wait && composer test:integration`): the official Flysystem adapter suite
     (`NextCloudAdapterTest`, `AllFeaturesAdapterTest`) and real-world scenarios (`NextcloudScenariosTest`).
     Use `RecordingDavClient` to assert which requests were made.
4. Run the checks:
   ```bash
   composer cs:fix
   composer analyse
   composer test
   ```
5. Add a line to the `[Unreleased]` section of `CHANGELOG.md`.
6. Open a pull request.

## Reporting bugs

Please include your PHP version, your Nextcloud version, the code that fails and the full exception, including
`getPrevious()` (it carries the HTTP status and Nextcloud's error message).
