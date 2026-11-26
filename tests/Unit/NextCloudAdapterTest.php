<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Unit;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\Tests\Support\FakeDavClient;
use Marktaborosi\FlysystemNextcloud\UnexpectedStatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sabre\DAV\Client;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

#[CoversClass(NextCloudAdapter::class)]
#[CoversClass(UnexpectedStatusCode::class)]
final class NextCloudAdapterTest extends TestCase
{
    private const BASE = 'https://cloud.example.com/remote.php/dav/files/admin/';

    #[Test]
    public function a_failed_upload_throws_instead_of_failing_silently(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'PUT' => FakeDavClient::sabreError(507, 'Insufficient storage'),
            default => FakeDavClient::response(500),
        });

        try {
            (new NextCloudAdapter($client))->write('report.pdf', 'data', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(UnexpectedStatusCode::class, $previous);
            self::assertSame(507, $previous->statusCode);
            self::assertSame('Insufficient storage', $previous->serverMessage);
            self::assertStringContainsString('Insufficient storage', $exception->getMessage());
        }
    }

    #[Test]
    public function file_exists_is_false_only_for_404(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => FakeDavClient::response(404)));

        self::assertFalse($adapter->fileExists('missing.txt'));
    }

    #[Test]
    public function file_exists_reports_authentication_errors(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => FakeDavClient::sabreError(401, 'No public access to this resource.')));

        $this->expectException(UnableToCheckFileExistence::class);
        $adapter->fileExists('file.txt');
    }

    #[Test]
    public function reading_never_returns_an_error_page_as_file_contents(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => FakeDavClient::sabreError(403, 'Access denied')));

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('Access denied');
        $adapter->read('secret.txt');
    }

    #[Test]
    public function deleting_a_missing_file_is_not_an_error(): void
    {
        $client = $this->client(fn () => FakeDavClient::response(404));
        (new NextCloudAdapter($client))->delete('gone.txt');

        self::assertSame('DELETE', $client->requests[0]['method']);
    }

    #[Test]
    public function a_failed_delete_throws(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => FakeDavClient::sabreError(423, 'File is locked')));

        $this->expectException(UnableToDeleteFile::class);
        $adapter->delete('locked.txt');
    }

    #[Test]
    public function the_root_directory_is_never_deleted(): void
    {
        $client = $this->client(fn () => FakeDavClient::response(204));

        try {
            (new NextCloudAdapter($client))->deleteDirectory('/');
            self::fail('Expected UnableToDeleteDirectory');
        } catch (UnableToDeleteDirectory) {
            self::assertSame([], $client->requests);
        }
    }

    #[Test]
    public function creating_an_existing_directory_is_idempotent(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'MKCOL' => FakeDavClient::response(405),
            'PROPFIND' => FakeDavClient::multiStatus(['/remote.php/dav/files/admin/docs/' => ['dir' => true]]),
            default => FakeDavClient::response(500),
        });

        (new NextCloudAdapter($client))->createDirectory('docs', new Config());

        self::assertSame(['MKCOL', 'PROPFIND'], array_column($client->requests, 'method'));
    }

    #[Test]
    public function creating_a_directory_where_a_file_exists_fails(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'MKCOL' => FakeDavClient::response(405),
            'PROPFIND' => FakeDavClient::multiStatus(['/remote.php/dav/files/admin/docs' => ['size' => 3]]),
            default => FakeDavClient::response(500),
        });

        $this->expectException(UnableToCreateDirectory::class);
        (new NextCloudAdapter($client))->createDirectory('docs', new Config());
    }

    #[Test]
    public function listing_decodes_paths_relative_to_an_encoded_base_uri(): void
    {
        // Nextcloud user ids are often e-mail addresses, which are percent-encoded in the URL.
        $base = 'https://cloud.example.com/remote.php/dav/files/john%40example.com/';
        $client = $this->client(fn () => FakeDavClient::multiStatus([
            '/remote.php/dav/files/john%40example.com/My%20Docs/' => ['dir' => true],
            '/remote.php/dav/files/john%40example.com/My%20Docs/a%2Bb%20c.txt' => ['size' => 12, 'type' => 'text/plain; charset=utf-8'],
            '/remote.php/dav/files/john%40example.com/My%20Docs/Sub/' => ['dir' => true],
            '/remote.php/dav/files/john%40example.com/My%20Docs/blob.bin' => ['size' => 1, 'type' => 'application/octet-stream'],
        ]), $base);

        $items = iterator_to_array((new NextCloudAdapter($client))->listContents('My Docs', false), false);

        self::assertSame(
            'https://cloud.example.com/remote.php/dav/files/john%40example.com/My%20Docs/',
            $client->requests[0]['url'],
        );
        self::assertCount(3, $items);

        self::assertInstanceOf(FileAttributes::class, $items[0]);
        self::assertSame('My Docs/a+b c.txt', $items[0]->path());
        self::assertSame(12, $items[0]->fileSize());
        self::assertSame('text/plain', $items[0]->mimeType());
        self::assertSame(strtotime('Tue, 01 Sep 2026 10:00:00 GMT'), $items[0]->lastModified());

        self::assertInstanceOf(DirectoryAttributes::class, $items[1]);
        self::assertSame('My Docs/Sub', $items[1]->path());

        self::assertInstanceOf(FileAttributes::class, $items[2]);
        self::assertNull($items[2]->mimeType(), 'application/octet-stream means "unknown"');
    }

    #[Test]
    public function listing_a_missing_directory_yields_nothing(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => FakeDavClient::response(404)));

        self::assertSame([], iterator_to_array($adapter->listContents('nope', true), false));
    }

    #[Test]
    public function deep_listing_keeps_every_entry(): void
    {
        $client = $this->client(fn (RequestInterface $r) => str_ends_with($r->getUrl(), '/a/')
            ? FakeDavClient::multiStatus([
                '/remote.php/dav/files/admin/a/' => ['dir' => true],
                '/remote.php/dav/files/admin/a/1.txt' => ['size' => 1],
                '/remote.php/dav/files/admin/a/b/' => ['dir' => true],
            ])
            : FakeDavClient::multiStatus([
                '/remote.php/dav/files/admin/a/b/' => ['dir' => true],
                '/remote.php/dav/files/admin/a/b/2.txt' => ['size' => 2],
            ]));

        // iterator_to_array() with keys preserved is what users typically call.
        $paths = array_map(fn ($item) => $item->path(), iterator_to_array((new NextCloudAdapter($client))->listContents('a', true)));

        self::assertSame(['a/1.txt', 'a/b', 'a/b/2.txt'], array_values($paths));
    }

    #[Test]
    public function streams_are_uploaded_from_their_current_position(): void
    {
        $client = $this->client(fn (RequestInterface $r) => FakeDavClient::response($r->getMethod() === 'PUT' ? 201 : 404));
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'skip:payload');
        fseek($stream, 5);

        (new NextCloudAdapter($client))->writeStream('upload.txt', $stream, new Config());

        self::assertSame('PUT', $client->requests[0]['method']);
        self::assertSame('payload', $client->requests[0]['body']);
        self::assertSame('stream', get_resource_type($stream), 'The caller owns the stream; the adapter must not close it.');
    }

    #[Test]
    public function writing_creates_missing_parent_directories(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'PROPFIND' => FakeDavClient::response(404),
            'MKCOL', 'PUT' => FakeDavClient::response(201),
            default => FakeDavClient::response(500),
        });

        (new NextCloudAdapter($client))->write('a/b/file.txt', 'x', new Config());

        self::assertSame(
            ['PROPFIND a/b', 'PROPFIND a', 'MKCOL a/', 'MKCOL a/b/', 'PUT a/b/file.txt'],
            array_map(fn (array $r) => $r['method'] . ' ' . substr($r['url'], strlen(self::BASE)), $client->requests),
        );
    }

    #[Test]
    public function moving_to_the_same_path_is_a_no_op(): void
    {
        $client = $this->client(fn () => FakeDavClient::response(500));

        (new NextCloudAdapter($client))->move('a.txt', '/a.txt', new Config());

        self::assertSame([], $client->requests);
    }

    #[Test]
    public function a_failed_move_throws(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'PROPFIND' => FakeDavClient::multiStatus(['/remote.php/dav/files/admin/a.txt' => ['size' => 1]]),
            'MOVE' => FakeDavClient::sabreError(403, 'Destination is not writable'),
            default => FakeDavClient::response(500),
        });

        $this->expectException(UnableToMoveFile::class);
        (new NextCloudAdapter($client))->move('a.txt', 'b.txt', new Config());
    }

    #[Test]
    public function move_sends_an_encoded_absolute_destination(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'PROPFIND' => FakeDavClient::multiStatus(['/remote.php/dav/files/admin/a.txt' => ['size' => 1]]),
            default => FakeDavClient::response(201),
        });

        (new NextCloudAdapter($client))->move('a.txt', 'new name.txt', new Config());

        $move = $client->requests[1];
        self::assertSame('MOVE', $move['method']);
        self::assertSame([self::BASE . 'new%20name.txt'], $move['headers']['Destination']);
        self::assertSame(['T'], $move['headers']['Overwrite']);
    }

    #[Test]
    public function visibility_follows_moves_and_is_forgotten_on_delete(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match ($r->getMethod()) {
            'PROPFIND' => FakeDavClient::multiStatus([(string) parse_url($r->getUrl(), PHP_URL_PATH) => ['size' => 1]]),
            default => FakeDavClient::response(201),
        });
        $adapter = new NextCloudAdapter($client);

        $adapter->write('a.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $adapter->move('a.txt', 'b.txt', new Config());
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('b.txt')->visibility());
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('a.txt')->visibility());

        $adapter->delete('b.txt');
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('b.txt')->visibility());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function baseUris(): iterable
    {
        yield 'with trailing slash' => ['https://cloud.example.com/remote.php/dav/files/admin/'];
        yield 'without trailing slash' => ['https://cloud.example.com/remote.php/dav/files/admin'];
    }

    #[Test]
    #[DataProvider('baseUris')]
    public function settings_are_normalized(string $baseUri): void
    {
        $adapter = new NextCloudAdapter(['baseUri' => $baseUri, 'userName' => 'admin', 'password' => 'secret']);
        $client = (new ReflectionProperty(NextCloudAdapter::class, 'client'))->getValue($adapter);
        self::assertInstanceOf(Client::class, $client);

        // Relative paths must resolve *inside* the user's folder.
        self::assertSame(self::BASE . 'a.txt', $client->getAbsoluteUrl('a.txt'));

        // Basic auth is sent preemptively (no extra 401 round-trip per request).
        $curlSettings = (new ReflectionProperty(\Sabre\HTTP\Client::class, 'curlSettings'))->getValue($client);
        self::assertIsArray($curlSettings);
        self::assertSame(CURLAUTH_BASIC, $curlSettings[CURLOPT_HTTPAUTH]);
    }

    #[Test]
    public function an_explicit_auth_type_is_respected(): void
    {
        $adapter = new NextCloudAdapter(['baseUri' => self::BASE, 'userName' => 'a', 'password' => 'b', 'authType' => Client::AUTH_DIGEST]);
        $client = (new ReflectionProperty(NextCloudAdapter::class, 'client'))->getValue($adapter);
        self::assertInstanceOf(Client::class, $client);
        $curlSettings = (new ReflectionProperty(\Sabre\HTTP\Client::class, 'curlSettings'))->getValue($client);

        self::assertIsArray($curlSettings);
        self::assertSame(CURLAUTH_DIGEST, $curlSettings[CURLOPT_HTTPAUTH]);
    }

    #[Test]
    public function a_base_uri_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NextCloudAdapter(['userName' => 'admin']); // @phpstan-ignore argument.type
    }

    /**
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    private function client(callable $handler, string $baseUri = self::BASE): FakeDavClient
    {
        return new FakeDavClient($handler, $baseUri);
    }
}
