# Flysystem adapter for Nextcloud

[![Latest Version](https://img.shields.io/packagist/v/marktaborosi/flysystem-nextcloud.svg?style=flat-square)](https://packagist.org/packages/marktaborosi/flysystem-nextcloud)
[![PHP Version](https://img.shields.io/packagist/dependency-v/marktaborosi/flysystem-nextcloud/php?style=flat-square)](https://packagist.org/packages/marktaborosi/flysystem-nextcloud)
[![CI](https://img.shields.io/github/actions/workflow/status/marktaborosi/flysystem-nextcloud/ci.yml?branch=main&style=flat-square&label=CI)](https://github.com/marktaborosi/flysystem-nextcloud/actions/workflows/ci.yml)
[![Downloads](https://img.shields.io/packagist/dt/marktaborosi/flysystem-nextcloud.svg?style=flat-square)](https://packagist.org/packages/marktaborosi/flysystem-nextcloud)
[![License](https://img.shields.io/packagist/l/marktaborosi/flysystem-nextcloud.svg?style=flat-square)](LICENSE)

A [Flysystem v3](https://flysystem.thephpleague.com/) adapter for [Nextcloud](https://nextcloud.com/). Use your
Nextcloud like any other Flysystem disk, in plain PHP, Laravel or Symfony, and get the Nextcloud features a generic
WebDAV adapter can't offer: chunked uploads for big files, server-side checksums, public links and fast deep listings.

```php
$filesystem = new Filesystem(
    NextCloudAdapter::fromCredentials('https://cloud.example.com', 'alice', 'app-password')
);

$filesystem->writeStream('backups/db-2026-11-26.sql.gz', fopen('/tmp/db.sql.gz', 'rb')); // chunked, streamed
$url = $filesystem->publicUrl('backups/db-2026-11-26.sql.gz');                          // public link
```

## Features

- **Everything Flysystem does:** read, write, streams, delete, directories, listings (shallow and deep), move, copy,
  file size, MIME type, last modified, visibility.
- **Chunked uploads.** Files above 10 MiB (configurable) go through Nextcloud's chunked upload API, so they get past
  `upload_max_filesize`, proxy body limits and timeouts. Uploads are streamed, so a 5 GB file never sits in PHP memory.
- **Checksums.** MD5 and SHA1 are stored with every upload, so `checksum()` answers with one small request instead of
  downloading the file. Other algorithms work too.
- **Public URLs.** `publicUrl()` returns a direct download link backed by a read-only public link share.
- **Real visibility, if you want it.** Optionally map `public`/`private` to public link shares.
- **Fast deep listings.** Optionally list a whole tree with one `SEARCH` request: about 34× faster on a 130-entry tree
  in our benchmark.
- **Errors are never swallowed.** Every failed request becomes the matching Flysystem exception, with the HTTP status
  and Nextcloud's own error message attached.
- **Tested against real Nextcloud servers:** the official
  [Flysystem adapter test suite](https://github.com/thephpleague/flysystem-adapter-test-utilities) plus more than
  20 scenario tests (25 MiB non-seekable streams, Unicode and odd file names, quotas, user ids with spaces and `@`,
  ...), with and without Redis.

## Requirements

- PHP 8.2 or newer with the `curl` extension
- `league/flysystem` 3.29 or newer
- A Nextcloud server (any [supported version](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule))

## Installation

```bash
composer require marktaborosi/flysystem-nextcloud
```

## Usage

```php
use League\Flysystem\Filesystem;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;

$adapter = NextCloudAdapter::fromCredentials(
    serverUrl: 'https://cloud.example.com',
    username: 'alice',
    password: getenv('NEXTCLOUD_APP_PASSWORD'),
    folder: 'Projects/website',          // optional: all paths are relative to this folder
);

$filesystem = new Filesystem($adapter);

$filesystem->write('hello.txt', 'Hello Nextcloud!');
echo $filesystem->read('hello.txt');

foreach ($filesystem->listContents('assets', deep: true) as $item) {
    echo $item->path(), PHP_EOL;
}
```

`fromCredentials()` builds the WebDAV URL for you. If your user id differs from the login name (LDAP, e-mail logins),
pass `userId:`. The folder must already exist.

### Using the constructor

The constructor takes the WebDAV base URI (or a configured `Sabre\DAV\Client`, see below) plus the options:

```php
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\VisibilityMode;

$adapter = new NextCloudAdapter(
    [
        'baseUri'  => 'https://cloud.example.com/remote.php/dav/files/alice/',
        'userName' => 'alice',
        'password' => getenv('NEXTCLOUD_APP_PASSWORD'),
    ],
    visibilityMode: VisibilityMode::InMemory,             // or VisibilityMode::PublicLinks
    chunkSize: NextCloudAdapter::DEFAULT_CHUNK_SIZE,      // 10 MiB; null disables chunked uploads
    searchDeepListing: false,                             // true: one SEARCH request per deep listing
);
```

| Setting    | Required | Description                                                                                         |
|------------|----------|-----------------------------------------------------------------------------------------------------|
| `baseUri`  | yes      | The user's WebDAV files root, e.g. `https://cloud.example.com/remote.php/dav/files/{userId}/`, or a folder in it. |
| `userName` | no       | Login name.                                                                                         |
| `password` | no       | Password, preferably an [app password](#use-an-app-password).                                       |
| `authType` | no       | `Client::AUTH_BASIC` (default), `Client::AUTH_DIGEST` or `Client::AUTH_NTLM`, combinable with `\|`.  |
| `proxy`    | no       | Proxy URL, e.g. `http://proxy.local:3128`.                                                           |
| `encoding` | no       | Accepted response encodings, e.g. `Client::ENCODING_GZIP`.                                           |

**Finding your `baseUri`:** in the Nextcloud web UI, open *Files → Files settings* (bottom left). The WebDAV URL shown
there is the one to use.

### Use an app password

Create one under *Personal settings → Security → Devices & sessions*. App passwords work with two-factor
authentication, can be revoked one by one and are not affected by password changes.

## Nextcloud features

### Chunked uploads

Every `write()`/`writeStream()` larger than `chunkSize` (10 MiB by default) is sent in chunks through
`/remote.php/dav/uploads/`, then assembled by Nextcloud. Nothing changes in your code. With Redis or Memcached on the
server, Nextcloud uses its faster chunking v2. If Nextcloud stores files on S3 (primary object storage), keep
`chunkSize` at 5 MiB or more.

Streams are uploaded straight from the stream when it is seekable, and spooled to a temporary file first otherwise
(pipes, sockets, PSR-7 bodies). Either way they are never loaded into memory as a whole.

### Checksums

```php
$filesystem->checksum('backup.tar');                              // md5, from Nextcloud's metadata
$filesystem->checksum('backup.tar', ['checksum_algo' => 'sha1']); // sha1, from Nextcloud's metadata
$filesystem->checksum('backup.tar', ['checksum_algo' => 'sha256']); // computed by downloading the file
```

Nextcloud clears the stored checksum when another client changes the file without sending one, so a stored checksum
is never stale. Files without one are downloaded and hashed.

### Public URLs

```php
$url = $filesystem->publicUrl('reports/q4.pdf');
// https://cloud.example.com/s/AbCdEfGhIjKlMnO/download
```

Reuses an existing public link of the file (one without a password that hasn't expired), or creates a read-only one.
This makes the file accessible to anyone with the URL. Errors from Nextcloud ("Public link sharing is disabled",
"Password is required", ...) are passed on in the exception message.

### Visibility

| `visibilityMode`              | `public` means                    | `private` means           | Stored on the server |
|-------------------------------|-----------------------------------|---------------------------|----------------------|
| `VisibilityMode::InMemory` (default) | remembered by this adapter instance | the default               | no                   |
| `VisibilityMode::PublicLinks` | the file has a public link share   | it has no public link     | yes                  |

With `PublicLinks`, `write(..., ['visibility' => 'public'])` or `setVisibility($path, 'public')` creates a read-only
public link, `private` deletes the public links you created for that file, and `visibility()` reflects the server
state, also in the next request or process. Copies keep the source's visibility (unless `retain_visibility` is false),
and moves keep their shares. Directory visibility is not applied.

### Fast deep listings

```php
new NextCloudAdapter($settings, searchDeepListing: true);
```

A deep `listContents()` normally needs one PROPFIND per directory. With `searchDeepListing` it uses a single `SEARCH`
request over Nextcloud's file index: 186 ms instead of 6.3 s for a tree of 130 entries in our benchmark. It falls
back to PROPFIND automatically when SEARCH isn't available or the tree has 10,000 entries or more. It is opt-in because
the file index can lag behind external storages (SMB, S3 mounts, ...) that are changed outside Nextcloud.

## Custom HTTP client options

Pass your own `Sabre\DAV\Client` to set timeouts, TLS options or anything else cURL supports:

```php
use Sabre\DAV\Client;

$client = new Client([
    'baseUri'  => 'https://cloud.example.com/remote.php/dav/files/alice/',
    'userName' => 'alice',
    'password' => getenv('NEXTCLOUD_APP_PASSWORD'),
    'authType' => Client::AUTH_BASIC,
]);
$client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, 5);
$client->addCurlSetting(CURLOPT_TIMEOUT, 300);
$client->addCurlSetting(CURLOPT_CAINFO, '/etc/ssl/certs/internal-ca.pem');

$adapter = new NextCloudAdapter($client);
```

> When you build the client yourself, make sure `baseUri` ends with `/` and set `authType` explicitly. Without it,
> cURL negotiates the auth method and every request is sent twice.

## Error handling

All errors are Flysystem exceptions (`League\Flysystem\FilesystemException`). When the cause was an HTTP error, the
previous exception is an `UnexpectedStatusCode`:

```php
use League\Flysystem\UnableToWriteFile;
use Marktaborosi\FlysystemNextcloud\UnexpectedStatusCode;

try {
    $filesystem->write('big.iso', $contents);
} catch (UnableToWriteFile $e) {
    $http = $e->getPrevious();

    if ($http instanceof UnexpectedStatusCode && $http->statusCode === 507) {
        // Quota exceeded. $http->serverMessage holds Nextcloud's own explanation.
    }
}
```

## Laravel

Register the driver in a service provider:

```php
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\VisibilityMode;

public function boot(): void
{
    Storage::extend('nextcloud', function ($app, array $config) {
        $adapter = NextCloudAdapter::fromCredentials(
            $config['server_url'],
            $config['username'],
            $config['password'],
            folder: $config['folder'] ?? '',
            visibilityMode: VisibilityMode::from($config['visibility_mode'] ?? 'in_memory'),
        );

        return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
    });
}
```

Add a disk to `config/filesystems.php`:

```php
'nextcloud' => [
    'driver'   => 'nextcloud',
    'server_url' => env('NEXTCLOUD_URL'), // not "url": Laravel uses that key as a URL prefix
    'username' => env('NEXTCLOUD_USERNAME'),
    'password' => env('NEXTCLOUD_PASSWORD'),
    'folder'   => env('NEXTCLOUD_FOLDER', ''),
    'throw'    => true,
],
```

```php
Storage::disk('nextcloud')->put('invoices/2026-11.pdf', $pdf);
$link = Storage::disk('nextcloud')->getDriver()->publicUrl('invoices/2026-11.pdf'); // public link
```

## Symfony

With [`league/flysystem-bundle`](https://github.com/thephpleague/flysystem-bundle):

```yaml
# config/services.yaml
services:
    app.nextcloud_adapter:
        class: Marktaborosi\FlysystemNextcloud\NextCloudAdapter
        factory: [Marktaborosi\FlysystemNextcloud\NextCloudAdapter, fromCredentials]
        arguments:
            $serverUrl: '%env(NEXTCLOUD_URL)%'
            $username: '%env(NEXTCLOUD_USERNAME)%'
            $password: '%env(NEXTCLOUD_PASSWORD)%'

# config/packages/flysystem.yaml
flysystem:
    storages:
        nextcloud.storage:
            adapter: 'app.nextcloud_adapter'
```

## Limitations

- **Downloads are buffered.** Sabre's HTTP client reads the whole response before returning it, so `read()` and
  `readStream()` hold the file in memory (`readStream()` spills to a temporary file above 2 MB). Uploads are streamed.
- **No temporary URLs.** Nextcloud share expiry works with whole days only, which would make links live longer than
  asked, so `temporaryUrl()` is not offered.
- **`InMemory` visibility is not persisted.** Use `VisibilityMode::PublicLinks` if visibility should mean something on
  the server.

## Development

```bash
composer install

composer test:unit          # fast, no server needed
composer analyse            # PHPStan (level max)
composer cs:fix             # PHP-CS-Fixer (PER-CS)

docker compose up -d --wait # local Nextcloud on http://localhost:8080 (admin / admin)
composer test:integration   # official Flysystem suite + scenarios against it
docker compose down -v

# The same with Redis (Nextcloud's chunking v2):
docker compose -f docker-compose.yml -f docker-compose.redis.yml up -d --wait
```

The integration tests read `NEXTCLOUD_BASE_URI`, `NEXTCLOUD_USERNAME` and `NEXTCLOUD_PASSWORD` (defaults in
`phpunit.xml.dist`; the user must be an admin for the quota and user id scenarios). They are skipped when the server is
not reachable, unless `NEXTCLOUD_INTEGRATION_REQUIRED=1` is set, as it is in CI. Set `NEXTCLOUD_IMAGE_TAG` in `.env` to
test another Nextcloud version (see `.env.example`).

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

If you find a security issue, please e-mail mark.taborosi@gmail.com instead of opening a public issue.

## License

MIT. See [LICENSE](LICENSE).

## Credits

- [Mark Taborosi](https://github.com/marktaborosi)
- [The PHP League](https://thephpleague.com/) for [Flysystem](https://flysystem.thephpleague.com/)
- [sabre/dav](https://sabre.io/) for the WebDAV client
- [Nextcloud](https://nextcloud.com/)
