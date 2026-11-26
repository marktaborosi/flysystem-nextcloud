<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Unit;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Marktaborosi\FlysystemNextcloud\Internal\ServerLayout;
use Marktaborosi\FlysystemNextcloud\Internal\ShareApi;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\Tests\Support\FakeDavClient;
use Marktaborosi\FlysystemNextcloud\Tests\Support\NonSeekableStream;
use Marktaborosi\FlysystemNextcloud\UnexpectedStatusCode;
use Marktaborosi\FlysystemNextcloud\VisibilityMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Sabre\DAV\Client;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;

#[CoversClass(NextCloudAdapter::class)]
#[CoversClass(ServerLayout::class)]
#[CoversClass(ShareApi::class)]
final class NextcloudFeaturesTest extends TestCase
{
    private const BASE = 'https://cloud.example.com/remote.php/dav/files/admin/';
    private const SHARES = 'https://cloud.example.com/ocs/v2.php/apps/files_sharing/api/v1/shares';

    /**
     * @return iterable<string, array{string, ?string, ?string, string, string}>
     */
    public static function layouts(): iterable
    {
        yield 'standard' => ['https://cloud.example.com/remote.php/dav/files/alice/', 'https://cloud.example.com', 'https://cloud.example.com/remote.php/dav/uploads/alice/', '/files/alice/x', '/x'];
        yield 'sub-path install and port' => ['http://host:8443/nextcloud/remote.php/dav/files/bob/', 'http://host:8443/nextcloud', 'http://host:8443/nextcloud/remote.php/dav/uploads/bob/', '/files/bob/x', '/x'];
        yield 'scoped to a folder' => ['https://c.example/remote.php/dav/files/alice/My%20Docs/Sub/', 'https://c.example', 'https://c.example/remote.php/dav/uploads/alice/', '/files/alice/My Docs/Sub/x', '/My Docs/Sub/x'];
        yield 'encoded user id' => ['https://c.example/remote.php/dav/files/john%40example.com/', 'https://c.example', 'https://c.example/remote.php/dav/uploads/john%40example.com/', '/files/john@example.com/x', '/x'];
        yield 'legacy webdav endpoint' => ['https://c.example/remote.php/webdav/Docs/', 'https://c.example', null, '', '/Docs/x'];
    }

    #[Test]
    #[DataProvider('layouts')]
    public function the_server_layout_is_derived_from_the_base_uri(string $baseUri, ?string $server, ?string $uploads, string $scope, string $userPath): void
    {
        $layout = ServerLayout::fromBaseUri($baseUri);

        self::assertNotNull($layout);
        self::assertSame($server, $layout->serverUrl);
        self::assertSame($uploads, $layout->uploadsUrl());
        self::assertSame($scope === '' ? null : $scope, $layout->searchScope('x'));
        self::assertSame($userPath, $layout->userPath('x'));
        self::assertSame($server . '/ocs/v2.php/apps/files_sharing/api/v1/shares', $layout->sharesUrl());
    }

    #[Test]
    public function unknown_urls_have_no_layout(): void
    {
        self::assertNull(ServerLayout::fromBaseUri('https://dav.example.com/some/webdav/'));
        self::assertNull(ServerLayout::fromBaseUri('not a url'));
    }

    #[Test]
    public function from_credentials_builds_the_webdav_url(): void
    {
        $adapter = NextCloudAdapter::fromCredentials('https://cloud.example.com/nc/', 'john@example.com', 'secret', folder: '/My Docs/2026/');
        $client = (new ReflectionProperty(NextCloudAdapter::class, 'client'))->getValue($adapter);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://cloud.example.com/nc/remote.php/dav/files/john%40example.com/My%20Docs/2026/', $client->getAbsoluteUrl(''));

        $withUserId = NextCloudAdapter::fromCredentials('https://cloud.example.com', 'login', 'secret', userId: 'uid-42');
        $client = (new ReflectionProperty(NextCloudAdapter::class, 'client'))->getValue($withUserId);
        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://cloud.example.com/remote.php/dav/files/uid-42/', $client->getAbsoluteUrl(''));
    }

    #[Test]
    public function the_chunk_size_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NextCloudAdapter(new FakeDavClient(fn () => FakeDavClient::response(500)), chunkSize: 0);
    }

    #[Test]
    public function uploads_send_md5_and_sha1_checksums(): void
    {
        $client = $this->client(fn () => FakeDavClient::response(201));

        (new NextCloudAdapter($client))->write('a.txt', 'foobar', new Config());

        self::assertSame(['MD5:' . md5('foobar') . ' SHA1:' . sha1('foobar')], $client->requests[0]['headers']['OC-Checksum']);
    }

    #[Test]
    public function large_uploads_use_the_chunked_upload_api(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'PROPFIND'
            ? FakeDavClient::multiStatus(['/remote.php/dav/files/admin/dir/' => ['dir' => true]])
            : FakeDavClient::response(201));
        $contents = str_repeat('a', 10) . str_repeat('b', 10) . 'cc';

        (new NextCloudAdapter($client, chunkSize: 10))->write('dir/big file.bin', $contents, new Config());

        $requests = array_map(
            static fn (array $r): string => $r['method'] . ' ' . preg_replace('~flysystem-[0-9a-f]{32}~', '{id}', $r['url']),
            $client->requests,
        );

        self::assertSame([
            'PROPFIND ' . self::BASE . 'dir',
            'MKCOL https://cloud.example.com/remote.php/dav/uploads/admin/{id}',
            'PUT https://cloud.example.com/remote.php/dav/uploads/admin/{id}/00001',
            'PUT https://cloud.example.com/remote.php/dav/uploads/admin/{id}/00002',
            'PUT https://cloud.example.com/remote.php/dav/uploads/admin/{id}/00003',
            'MOVE https://cloud.example.com/remote.php/dav/uploads/admin/{id}/.file',
        ], $requests);

        self::assertSame(['aaaaaaaaaa', 'bbbbbbbbbb', 'cc'], array_column(array_slice($client->requests, 2, 3), 'body'));

        foreach (array_slice($client->requests, 1) as $request) {
            self::assertSame([self::BASE . 'dir/big%20file.bin'], $request['headers']['Destination']);
            self::assertSame(['22'], $request['headers']['OC-Total-Length']);
        }

        $move = $client->requests[5]['headers'];
        self::assertSame(['MD5:' . md5($contents) . ' SHA1:' . sha1($contents)], $move['OC-Checksum']);
    }

    #[Test]
    public function a_failed_chunked_upload_is_cleaned_up_and_reported(): void
    {
        $client = $this->client(fn (RequestInterface $r) => match (true) {
            $r->getMethod() === 'PUT' && str_ends_with($r->getUrl(), '/00002') => FakeDavClient::sabreError(507, 'Insufficient Storage'),
            default => FakeDavClient::response(201),
        });

        try {
            (new NextCloudAdapter($client, chunkSize: 4))->write('x.bin', '123456789', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $cause = $exception->getPrevious();
            self::assertInstanceOf(UnexpectedStatusCode::class, $cause);
            self::assertSame(507, $cause->statusCode);
        }

        $last = end($client->requests);
        self::assertNotFalse($last);
        self::assertSame('DELETE', $last['method']);
        self::assertMatchesRegularExpression('~/remote\.php/dav/uploads/admin/flysystem-[0-9a-f]{32}$~', $last['url']);
    }

    #[Test]
    public function non_seekable_streams_are_spooled_and_chunked(): void
    {
        $client = $this->client(fn () => FakeDavClient::response(201));
        $size = 50_000;

        (new NextCloudAdapter($client, chunkSize: 16_384))->writeStream('s.bin', NonSeekableStream::open($size, 'u'), new Config());

        $chunks = array_filter($client->requests, static fn (array $r): bool => $r['method'] === 'PUT');
        self::assertCount(4, $chunks);
        self::assertSame(NonSeekableStream::expectedMd5($size, 'u'), md5(implode('', array_column($chunks, 'body'))));
    }

    #[Test]
    public function the_legacy_endpoint_uploads_without_chunking(): void
    {
        $client = new FakeDavClient(fn () => FakeDavClient::response(201), 'https://c.example/remote.php/webdav/');

        (new NextCloudAdapter($client, chunkSize: 2))->write('a.txt', 'abcdef', new Config());

        self::assertSame(['PUT'], array_column($client->requests, 'method'));
    }

    #[Test]
    public function stored_checksums_are_used_without_downloading(): void
    {
        $client = $this->client(fn () => self::checksumResponse('<oc:checksum>MD5:ABC123</oc:checksum><oc:checksum>SHA1:def456</oc:checksum>'));
        $adapter = new NextCloudAdapter($client);

        self::assertSame('abc123', $adapter->checksum('f.txt', new Config()));
        self::assertSame('def456', $adapter->checksum('f.txt', new Config(['checksum_algo' => 'SHA1'])));
        self::assertSame(['PROPFIND', 'PROPFIND'], array_column($client->requests, 'method'));
    }

    #[Test]
    public function missing_checksums_are_computed_from_the_contents(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'GET'
            ? FakeDavClient::response(200, 'foobar')
            : self::checksumResponse(null));

        self::assertSame(md5('foobar'), (new NextCloudAdapter($client))->checksum('f.txt', new Config()));
        self::assertSame(['PROPFIND', 'GET'], array_column($client->requests, 'method'));
    }

    #[Test]
    public function checksums_of_directories_and_missing_files_fail(): void
    {
        $directory = new NextCloudAdapter($this->client(fn () => FakeDavClient::multiStatus(['/remote.php/dav/files/admin/d/' => ['dir' => true]])));
        $missing = new NextCloudAdapter($this->client(fn () => FakeDavClient::response(404)));

        foreach (['directory' => $directory, 'missing' => $missing] as $label => $adapter) {
            try {
                $adapter->checksum('d', new Config());
                self::fail("Expected UnableToProvideChecksum for {$label}");
            } catch (UnableToProvideChecksum) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function an_existing_usable_public_link_is_reused(): void
    {
        $client = $this->client(fn () => self::ocs(200, [
            ['id' => '1', 'share_type' => 0, 'url' => 'https://user-share'],
            ['id' => '2', 'share_type' => 3, 'url' => 'https://cloud.example.com/s/Protected', 'password' => 'hash'],
            ['id' => '3', 'share_type' => 3, 'url' => 'https://cloud.example.com/s/Expired', 'expiration' => '2001-01-01 00:00:00'],
            ['id' => '4', 'share_type' => 3, 'url' => 'https://cloud.example.com/s/Good'],
        ]));

        self::assertSame('https://cloud.example.com/s/Good/download', (new NextCloudAdapter($client))->publicUrl('docs/a.txt', new Config()));

        self::assertCount(1, $client->requests);
        self::assertSame(self::SHARES . '?format=json&path=%2Fdocs%2Fa.txt&reshares=false', $client->requests[0]['url']);
        self::assertSame(['true'], $client->requests[0]['headers']['OCS-APIRequest']);
    }

    #[Test]
    public function a_public_link_is_created_when_needed(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'POST'
            ? self::ocs(200, ['id' => '9', 'share_type' => 3, 'url' => 'https://cloud.example.com/s/New'])
            : self::ocs(200, []));

        self::assertSame('https://cloud.example.com/s/New/download', (new NextCloudAdapter($client))->publicUrl('a b.txt', new Config()));

        $create = $client->requests[1];
        self::assertSame('POST', $create['method']);
        parse_str($create['body'], $fields);
        self::assertSame(['path' => '/a b.txt', 'shareType' => '3', 'permissions' => '1'], $fields);
    }

    #[Test]
    public function sharing_errors_explain_what_went_wrong(): void
    {
        $missing = new NextCloudAdapter($this->client(fn () => self::ocs(404, [], 'Wrong path, file/folder does not exist')));
        $disabled = new NextCloudAdapter($this->client(fn (RequestInterface $r) => $r->getMethod() === 'POST'
            ? self::ocs(403, [], 'Public link sharing is disabled by the administrator')
            : self::ocs(200, [])));

        try {
            $missing->publicUrl('nope.txt', new Config());
            self::fail('Expected UnableToGeneratePublicUrl');
        } catch (UnableToGeneratePublicUrl $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }

        $this->expectException(UnableToGeneratePublicUrl::class);
        $this->expectExceptionMessage('Public link sharing is disabled by the administrator');
        $disabled->publicUrl('a.txt', new Config());
    }

    #[Test]
    public function public_links_visibility_creates_and_deletes_shares(): void
    {
        $shares = [];
        $client = $this->client(function (RequestInterface $r) use (&$shares) {
            return match ($r->getMethod()) {
                'GET' => self::ocs(200, $shares),
                'POST' => self::ocs(200, $shares[] = ['id' => (string) (count($shares) + 1), 'share_type' => 3, 'url' => 'https://x/s/T']),
                'DELETE' => self::ocs(200, null),
                default => FakeDavClient::response(500),
            };
        });
        $adapter = new NextCloudAdapter($client, visibilityMode: VisibilityMode::PublicLinks);

        self::assertSame(Visibility::PRIVATE, $adapter->visibility('a.txt')->visibility());

        $adapter->setVisibility('a.txt', Visibility::PUBLIC);
        $adapter->setVisibility('a.txt', Visibility::PUBLIC);
        self::assertCount(1, $shares, 'Only one link is created');
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('a.txt')->visibility());

        $shares[] = ['id' => '7', 'share_type' => 3, 'url' => 'https://x/s/Second'];
        $adapter->setVisibility('a.txt', Visibility::PRIVATE);

        $deleted = array_values(array_filter($client->requests, static fn (array $r): bool => $r['method'] === 'DELETE'));
        self::assertSame([self::SHARES . '/1?format=json', self::SHARES . '/7?format=json'], array_column($deleted, 'url'));
    }

    #[Test]
    public function public_links_visibility_of_a_missing_file_fails(): void
    {
        $adapter = new NextCloudAdapter($this->client(fn () => self::ocs(404, [])), visibilityMode: VisibilityMode::PublicLinks);

        $this->expectException(UnableToSetVisibility::class);
        $adapter->setVisibility('nope.txt', Visibility::PUBLIC);
    }

    #[Test]
    public function in_memory_visibility_never_touches_shares(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'PROPFIND'
            ? FakeDavClient::multiStatus(['/remote.php/dav/files/admin/a.txt' => ['size' => 1]])
            : FakeDavClient::response(201));
        $adapter = new NextCloudAdapter($client);

        $adapter->write('a.txt', 'x', new Config([Config::OPTION_VISIBILITY => Visibility::PUBLIC]));
        $adapter->setVisibility('a.txt', Visibility::PRIVATE);

        self::assertSame([], array_filter($client->requests, static fn (array $r): bool => str_contains($r['url'], '/ocs/')));
    }

    #[Test]
    public function a_deep_listing_can_use_a_single_search_request(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'SEARCH'
            ? FakeDavClient::multiStatus([
                '/remote.php/dav/files/admin/docs/b/' => ['dir' => true],
                '/remote.php/dav/files/admin/docs/b/2.txt' => ['size' => 2],
                '/remote.php/dav/files/admin/docs/a.txt' => ['size' => 1],
            ])
            : FakeDavClient::response(500));

        $paths = array_map(
            static fn ($item) => $item->path(),
            iterator_to_array((new NextCloudAdapter($client, searchDeepListing: true))->listContents('docs', true), false),
        );

        self::assertSame(['docs/a.txt', 'docs/b', 'docs/b/2.txt'], $paths, 'Sorted, parents before children');
        self::assertSame(['SEARCH'], array_column($client->requests, 'method'));
        self::assertSame('https://cloud.example.com/remote.php/dav/', $client->requests[0]['url']);
        self::assertStringContainsString('<d:href>/files/admin/docs</d:href>', $client->requests[0]['body']);
        self::assertStringContainsString('<d:depth>infinity</d:depth>', $client->requests[0]['body']);
    }

    #[Test]
    public function search_falls_back_to_propfind_when_unavailable(): void
    {
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'SEARCH'
            ? FakeDavClient::response(501)
            : FakeDavClient::multiStatus([
                '/remote.php/dav/files/admin/docs/' => ['dir' => true],
                '/remote.php/dav/files/admin/docs/a.txt' => ['size' => 1],
            ]));

        $items = iterator_to_array((new NextCloudAdapter($client, searchDeepListing: true))->listContents('docs', true), false);

        self::assertCount(1, $items);
        self::assertSame(['SEARCH', 'PROPFIND'], array_column($client->requests, 'method'));
    }

    #[Test]
    public function search_falls_back_to_propfind_when_the_result_may_be_truncated(): void
    {
        $entries = [];

        for ($i = 0; $i < 10_000; ++$i) {
            $entries["/remote.php/dav/files/admin/many/{$i}.txt"] = ['size' => 1];
        }

        $search = FakeDavClient::multiStatus($entries);
        $client = $this->client(fn (RequestInterface $r) => $r->getMethod() === 'SEARCH'
            ? $search
            : FakeDavClient::multiStatus(['/remote.php/dav/files/admin/many/' => ['dir' => true]]));

        iterator_to_array((new NextCloudAdapter($client, searchDeepListing: true))->listContents('many', true));

        self::assertSame(['SEARCH', 'PROPFIND'], array_column($client->requests, 'method'));
    }

    private function client(callable $handler): FakeDavClient
    {
        return new FakeDavClient($handler, self::BASE);
    }

    private static function checksumResponse(?string $checksums): Response
    {
        $prop = $checksums === null ? '' : '<oc:checksums>' . $checksums . '</oc:checksums>';
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
            . '<d:href>/remote.php/dav/files/admin/f.txt</d:href>'
            . '<d:propstat><d:prop><d:resourcetype/>' . $prop . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
            . '</d:response></d:multistatus>';

        return FakeDavClient::response(207, $xml);
    }

    private static function ocs(int $status, mixed $data, string $message = 'OK'): Response
    {
        $body = json_encode(['ocs' => ['meta' => ['status' => $status === 200 ? 'ok' : 'failure', 'statuscode' => $status, 'message' => $message], 'data' => $data]]);

        return FakeDavClient::response($status, (string) $body, ['Content-Type' => 'application/json']);
    }
}
