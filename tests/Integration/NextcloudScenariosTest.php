<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Integration;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\Tests\Support\NonSeekableStream;
use Marktaborosi\FlysystemNextcloud\Tests\Support\RecordingDavClient;
use Marktaborosi\FlysystemNextcloud\UnexpectedStatusCode;
use Marktaborosi\FlysystemNextcloud\VisibilityMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Client;

/**
 * Real-world scenarios against a real Nextcloud: large and non-seekable uploads, chunking,
 * checksums, odd file names, deep listings, public links, quota and authentication errors.
 *
 * Every test works in its own folder (the adapters are scoped to it through the base URI),
 * which also exercises sub-folder base URIs everywhere.
 */
final class NextcloudScenariosTest extends TestCase
{
    use RequiresNextcloud;

    private const MiB = 1024 * 1024;

    private string $folder;

    private RecordingDavClient $client;

    protected function setUp(): void
    {
        $this->skipOrFailWithoutNextcloud();

        $this->folder = 'flysystem-scenarios/' . bin2hex(random_bytes(4));
        (new NextCloudAdapter(self::settings()))->createDirectory($this->folder, new Config());
        $this->client = $this->recordingClient();
    }

    protected function tearDown(): void
    {
        if (isset($this->folder) && self::nextcloudIsAvailable()) {
            (new NextCloudAdapter(self::settings()))->deleteDirectory('flysystem-scenarios');
        }
    }

    #[Test]
    public function a_large_non_seekable_stream_is_uploaded_in_chunks_and_arrives_intact(): void
    {
        $size = 25 * self::MiB + 12345;
        $adapter = new NextCloudAdapter($this->client);
        $filesystem = new Filesystem($adapter);

        $filesystem->writeStream('big/stream.bin', NonSeekableStream::open($size, 'big'));

        $methods = array_column($this->client->log, 'method');
        self::assertContains('MKCOL', $methods);
        self::assertSame(3, $this->countRequests('PUT', '/remote.php/dav/uploads/'), '25 MiB in 10 MiB chunks');
        self::assertSame(1, $this->countRequests('MOVE', '/.file'));

        self::assertSame($size, $filesystem->fileSize('big/stream.bin'));

        // The checksum comes from Nextcloud's metadata, without downloading the file.
        $this->client->reset();
        self::assertSame(NonSeekableStream::expectedMd5($size, 'big'), $filesystem->checksum('big/stream.bin'));
        self::assertSame(['PROPFIND' => 1], $this->client->methodCounts());

        // And the bytes really are the same.
        $stream = $filesystem->readStream('big/stream.bin');
        $context = hash_init('md5');
        hash_update_stream($context, $stream);
        self::assertSame(NonSeekableStream::expectedMd5($size, 'big'), hash_final($context));
    }

    #[Test]
    public function a_large_local_file_is_chunked_and_can_be_overwritten_by_a_small_one(): void
    {
        $file = tmpfile();
        self::assertIsResource($file);
        $contents = random_bytes(12 * self::MiB);
        fwrite($file, $contents);
        rewind($file);

        $filesystem = new Filesystem(new NextCloudAdapter($this->client));
        $filesystem->writeStream('local.bin', $file);
        fclose($file);

        self::assertSame(2, $this->countRequests('PUT', '/remote.php/dav/uploads/'));
        self::assertSame(md5($contents), $filesystem->checksum('local.bin'));
        self::assertSame(sha1($contents), $filesystem->checksum('local.bin', ['checksum_algo' => 'sha1']));
        self::assertTrue($filesystem->read('local.bin') === $contents, 'Downloaded bytes differ');

        $this->client->reset();
        $filesystem->write('local.bin', 'small now');

        self::assertSame(0, $this->countRequests('PUT', '/remote.php/dav/uploads/'), 'Small files use a single PUT');
        self::assertSame('small now', $filesystem->read('local.bin'));
        self::assertSame(md5('small now'), $filesystem->checksum('local.bin'));
        self::assertSame(9, $filesystem->fileSize('local.bin'));
    }

    #[Test]
    public function the_chunk_size_is_configurable_and_strings_are_chunked_too(): void
    {
        $contents = random_bytes((int) (2.5 * self::MiB));
        $adapter = new NextCloudAdapter($this->client, chunkSize: self::MiB);

        $adapter->write('string.bin', $contents, new Config());

        self::assertSame(3, $this->countRequests('PUT', '/remote.php/dav/uploads/'));
        self::assertTrue($adapter->read('string.bin') === $contents);
    }

    #[Test]
    public function chunking_can_be_disabled(): void
    {
        $adapter = new NextCloudAdapter($this->client, chunkSize: null);

        $adapter->write('single.bin', str_repeat('x', 11 * self::MiB), new Config());

        self::assertSame(0, $this->countRequests('PUT', '/remote.php/dav/uploads/'));
        self::assertSame(11 * self::MiB, $adapter->fileSize('single.bin')->fileSize());
    }

    #[Test]
    public function a_stream_is_uploaded_from_its_current_position(): void
    {
        $adapter = new NextCloudAdapter($this->client);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'HEADER|payload');
        fseek($stream, 7);

        $adapter->writeStream('position.txt', $stream, new Config());

        self::assertSame('payload', $adapter->read('position.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardPaths(): iterable
    {
        yield 'hungarian accents' => ['ékezetek/árvíztűrő tükörfúrógép.txt'];
        yield 'japanese' => ['日本語/ファイル名.txt'];
        yield 'emoji' => ['emoji 🚀/rocket 🚀.txt'];
        yield 'url syntax' => ['url/a+b&c=d#e?f;g.txt'];
        yield 'percent signs' => ['percent/100% %20 not encoded.txt'];
        yield 'quotes' => ["quotes/it's \"quoted\".txt"];
        yield 'brackets and spaces' => ['brackets/[1] {2} (3)  two  spaces.txt'];
        yield 'leading dot' => ['dots/.hidden.txt'];
        yield 'zero' => ['0/0'];
        yield 'deep' => ['a/b/c/d/e/f/g/h/deep.txt'];
    }

    #[Test]
    #[DataProvider('awkwardPaths')]
    public function awkward_file_names_survive_every_operation(string $path): void
    {
        $filesystem = new Filesystem(new NextCloudAdapter($this->client));
        $directory = dirname($path);

        $filesystem->write($path, "contents of {$path}");

        self::assertTrue($filesystem->fileExists($path));
        self::assertTrue($filesystem->directoryExists($directory));
        self::assertSame("contents of {$path}", $filesystem->read($path));
        self::assertSame(md5("contents of {$path}"), $filesystem->checksum($path));

        $listed = $filesystem->listContents($directory)->map(fn (StorageAttributes $item) => $item->path())->toArray();
        self::assertSame([$path], $listed);

        $copy = $directory . '/copy of ' . basename($path);
        $moved = $directory . '/moved ' . basename($path);
        $filesystem->copy($path, $copy);
        $filesystem->move($copy, $moved);

        self::assertFalse($filesystem->fileExists($copy));
        self::assertSame("contents of {$path}", $filesystem->read($moved));

        $filesystem->delete($path);
        $filesystem->delete($moved);
        self::assertFalse($filesystem->fileExists($path));
        self::assertSame([], $filesystem->listContents($directory)->toArray());
    }

    #[Test]
    public function search_and_propfind_deep_listings_return_the_same_tree(): void
    {
        $writer = new NextCloudAdapter(self::scopedSettings($this->folder));
        $expected = [];

        foreach (['alpha', 'béta', 'gamma ray'] as $top) {
            foreach (['one', 'two', 'drei 3'] as $sub) {
                for ($i = 1; $i <= 4; ++$i) {
                    $path = "tree/{$top}/{$sub}/file {$i}.txt";
                    $writer->write($path, str_repeat('x', $i), new Config());
                    $expected[$path] = "file:{$i}";
                }

                $expected["tree/{$top}/{$sub}"] = 'dir';
            }

            $expected["tree/{$top}"] = 'dir';
        }

        $writer->createDirectory('tree/empty', new Config());
        $expected['tree/empty'] = 'dir';
        ksort($expected);

        $describe = static function (iterable $listing): array {
            /** @var iterable<StorageAttributes> $listing */
            $result = [];

            foreach ($listing as $item) {
                $result[$item->path()] = $item instanceof FileAttributes ? 'file:' . $item->fileSize() : 'dir';
            }

            ksort($result);

            return $result;
        };

        $propfindClient = $this->recordingClient($this->folder);
        $propfind = $describe((new NextCloudAdapter($propfindClient))->listContents('tree', true));

        $searchClient = $this->recordingClient($this->folder);
        $search = $describe((new NextCloudAdapter($searchClient, searchDeepListing: true))->listContents('tree', true));

        self::assertSame($expected, $propfind);
        self::assertSame($expected, $search);
        self::assertSame(['PROPFIND' => 14], $propfindClient->methodCounts(), 'One PROPFIND per directory');
        self::assertSame(['SEARCH' => 1], $searchClient->methodCounts(), 'A single SEARCH request');

        // Shallow listings do not use SEARCH.
        $searchClient->reset();
        $shallow = $describe((new NextCloudAdapter($searchClient, searchDeepListing: true))->listContents('tree', false));
        self::assertSame(['tree/alpha', 'tree/béta', 'tree/empty', 'tree/gamma ray'], array_keys($shallow));
        self::assertSame(['PROPFIND' => 1], $searchClient->methodCounts());
    }

    #[Test]
    public function a_public_url_downloads_the_file_anonymously_and_is_reused(): void
    {
        $adapter = new NextCloudAdapter($this->client);
        $adapter->write('public/report.txt', 'public contents', new Config());

        $url = $adapter->publicUrl('public/report.txt', new Config());

        self::assertSame([200, 'public contents'], self::anonymousGet($url));
        self::assertSame($url, $adapter->publicUrl('public/report.txt', new Config()), 'The existing link is reused');
        self::assertSame($url, (new Filesystem($adapter))->publicUrl('public/report.txt'));
    }

    #[Test]
    public function visibility_can_be_backed_by_public_links(): void
    {
        $adapter = new NextCloudAdapter($this->client, visibilityMode: VisibilityMode::PublicLinks);
        $filesystem = new Filesystem($adapter);

        $filesystem->write('vis/doc.txt', 'shared', ['visibility' => Visibility::PUBLIC]);
        self::assertSame(Visibility::PUBLIC, $filesystem->visibility('vis/doc.txt'));

        $url = $filesystem->publicUrl('vis/doc.txt');
        self::assertSame([200, 'shared'], self::anonymousGet($url));

        // A new adapter instance (think: next HTTP request) sees the same visibility.
        $fresh = new Filesystem(new NextCloudAdapter(self::scopedSettings($this->folder), visibilityMode: VisibilityMode::PublicLinks));
        self::assertSame(Visibility::PUBLIC, $fresh->visibility('vis/doc.txt'));

        // Copies keep the visibility by default, unless told otherwise.
        $filesystem->copy('vis/doc.txt', 'vis/copy.txt');
        $filesystem->copy('vis/doc.txt', 'vis/private-copy.txt', ['retain_visibility' => false]);
        self::assertSame(Visibility::PUBLIC, $filesystem->visibility('vis/copy.txt'));
        self::assertSame(Visibility::PRIVATE, $filesystem->visibility('vis/private-copy.txt'));

        // Shares follow a move.
        $filesystem->move('vis/doc.txt', 'vis/renamed.txt');
        self::assertSame(Visibility::PUBLIC, $filesystem->visibility('vis/renamed.txt'));
        self::assertSame([200, 'shared'], self::anonymousGet($url));

        // Private removes the link: the old URL stops working.
        $filesystem->setVisibility('vis/renamed.txt', Visibility::PRIVATE);
        self::assertSame(Visibility::PRIVATE, $filesystem->visibility('vis/renamed.txt'));
        self::assertSame(404, self::anonymousGet($url)[0]);

        // Setting the same visibility twice is harmless.
        $filesystem->setVisibility('vis/renamed.txt', Visibility::PRIVATE);
        $filesystem->setVisibility('vis/copy.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $filesystem->visibility('vis/copy.txt'));
    }

    #[Test]
    public function checksums_fall_back_to_hashing_when_nextcloud_has_none(): void
    {
        $adapter = new NextCloudAdapter($this->client);

        // Uploaded without an OC-Checksum header, like the web UI or another client might.
        $raw = new Client(self::scopedSettings($this->folder) + ['authType' => Client::AUTH_BASIC]);
        self::assertSame(201, $raw->request('PUT', 'no-checksum.txt', 'foobar')['statusCode']);

        $this->client->reset();
        self::assertSame(md5('foobar'), $adapter->checksum('no-checksum.txt', new Config()));
        self::assertSame(['GET' => 1, 'PROPFIND' => 1], $this->client->methodCounts());

        // Algorithms Nextcloud does not store are computed as well.
        $adapter->write('sha256.txt', 'foobar', new Config());
        self::assertSame(hash('sha256', 'foobar'), $adapter->checksum('sha256.txt', new Config(['checksum_algo' => 'sha256'])));
        self::assertSame(hash('crc32b', 'foobar'), (new Filesystem($adapter))->checksum('sha256.txt', ['checksum_algo' => 'crc32b']));
    }

    #[Test]
    public function the_convenience_constructor_scopes_to_a_folder(): void
    {
        $settings = self::settings();
        (new NextCloudAdapter(self::settings()))->createDirectory($this->folder . '/via credentials', new Config());
        $adapter = NextCloudAdapter::fromCredentials(self::serverUrl(), $settings['userName'], $settings['password'], folder: $this->folder . '/via credentials');

        $adapter->write('hello.txt', 'hi', new Config());

        $root = new NextCloudAdapter(self::settings());
        self::assertSame('hi', $root->read($this->folder . '/via credentials/hello.txt'));
    }

    #[Test]
    public function a_wrong_password_is_reported_not_swallowed(): void
    {
        $adapter = new NextCloudAdapter(['password' => 'definitely-wrong'] + self::scopedSettings($this->folder));

        try {
            $adapter->write('nope.txt', 'x', new Config());
            self::fail('Expected UnableToWriteFile');
        } catch (UnableToWriteFile $exception) {
            $cause = $exception->getPrevious();

            while ($cause !== null && !$cause instanceof UnexpectedStatusCode && !$cause instanceof \Sabre\HTTP\ClientHttpException) {
                $cause = $cause->getPrevious();
            }

            self::assertNotNull($cause, 'The HTTP error is attached');
        }

        $this->expectException(UnableToCheckFileExistence::class);
        $adapter->fileExists('nope.txt');
    }

    #[Test]
    public function reading_a_directory_or_missing_file_fails_cleanly(): void
    {
        $adapter = new NextCloudAdapter($this->client);
        $adapter->createDirectory('dir', new Config());

        self::assertSame([], iterator_to_array($adapter->listContents('does/not/exist', true)));

        $this->expectException(UnableToReadFile::class);
        $adapter->read('missing.txt');
    }

    #[Test]
    public function exceeding_the_quota_is_reported_with_status_507(): void
    {
        $user = $this->createUser();

        try {
            $adapter = NextCloudAdapter::fromCredentials(self::serverUrl(), $user['id'], $user['password'], chunkSize: 512 * 1024);

            // The first login copies Nextcloud's skeleton files (several MB on the official image),
            // which count towards the quota: leave exactly 1 MiB free on top of them.
            $adapter->directoryExists('');
            $this->setQuota($user['id'], $this->usedSpace($user['id']) + self::MiB);

            $adapter->write('fits.txt', 'small', new Config());

            foreach (['single PUT' => null, 'chunked' => 512 * 1024] as $label => $chunkSize) {
                $adapter = NextCloudAdapter::fromCredentials(self::serverUrl(), $user['id'], $user['password'], chunkSize: $chunkSize);

                try {
                    $adapter->write("too-big-{$label}.bin", random_bytes(2 * self::MiB), new Config());
                    self::fail("Expected the {$label} upload to fail");
                } catch (UnableToWriteFile $exception) {
                    $cause = $exception->getPrevious();
                    self::assertInstanceOf(UnexpectedStatusCode::class, $cause, $label);
                    self::assertSame(507, $cause->statusCode, $label . ': ' . $cause->getMessage());
                }

                self::assertFalse($adapter->fileExists("too-big-{$label}.bin"), $label);
            }

            self::assertSame('small', $adapter->read('fits.txt'));
        } finally {
            $this->deleteUser($user['id']);
        }
    }

    #[Test]
    public function user_ids_with_spaces_and_at_signs_work(): void
    {
        $user = $this->createUser();

        try {
            $adapter = NextCloudAdapter::fromCredentials(self::serverUrl(), $user['id'], $user['password'], searchDeepListing: true);
            $filesystem = new Filesystem($adapter);

            $filesystem->write('nested/dir/file.txt', 'hello');
            $filesystem->createDirectory('empty dir');

            // New users also get Nextcloud's skeleton files; we only care about ours.
            $deep = $filesystem->listContents('', true)
                ->map(fn (StorageAttributes $item) => $item->path() . ($item instanceof DirectoryAttributes ? '/' : ''))
                ->toArray();
            sort($deep);

            self::assertContains('nested/dir/file.txt', $deep);
            self::assertContains('nested/', $deep);
            self::assertContains('empty dir/', $deep);
            self::assertSame('hello', $filesystem->read('nested/dir/file.txt'));
            self::assertSame([200, 'hello'], self::anonymousGet($filesystem->publicUrl('nested/dir/file.txt')));
        } finally {
            $this->deleteUser($user['id']);
        }
    }

    private function countRequests(string $method, string $urlContains): int
    {
        return count(array_filter(
            $this->client->log,
            static fn (array $entry): bool => $entry['method'] === $method && str_contains($entry['url'], $urlContains),
        ));
    }

    private function recordingClient(?string $folder = null): RecordingDavClient
    {
        $settings = self::scopedSettings($folder ?? $this->folder);

        return new RecordingDavClient($settings + ['authType' => Client::AUTH_BASIC]);
    }

    /**
     * @return array{baseUri: string, userName: string, password: string}
     */
    private static function scopedSettings(string $folder): array
    {
        $settings = self::settings();
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $folder)));
        $settings['baseUri'] = rtrim($settings['baseUri'], '/') . '/' . $encoded . '/';

        return $settings;
    }

    /**
     * @return array{0: int, 1: string} status code and body
     */
    private static function anonymousGet(string $url): array
    {
        $handle = curl_init($url);
        self::assertNotFalse($handle);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return [(int) $status, is_string($body) ? $body : ''];
    }

    /**
     * Creates a throw-away user through the provisioning API (needs admin credentials).
     *
     * @return array{id: string, password: string}
     */
    private function createUser(?string $quota = null): array
    {
        $id = 'fly tester ' . bin2hex(random_bytes(3)) . '@example.com';
        $password = 'Pw-' . bin2hex(random_bytes(12)) . '-!X';
        $fields = ['userid' => $id, 'password' => $password] + ($quota !== null ? ['quota' => $quota] : []);

        [$status, $body] = $this->ocs('POST', '/ocs/v2.php/cloud/users', $fields);

        if ($status !== 200) {
            self::markTestSkipped('Cannot create a test user (admin credentials needed): ' . $body);
        }

        return ['id' => $id, 'password' => $password];
    }

    private function usedSpace(string $id): int
    {
        [$status, $body] = $this->ocs('GET', '/ocs/v2.php/cloud/users/' . rawurlencode($id));
        $used = json_decode($body, true);

        foreach (['ocs', 'data', 'quota', 'used'] as $key) {
            $used = is_array($used) ? ($used[$key] ?? null) : null;
        }

        self::assertSame(200, $status, $body);
        self::assertIsNumeric($used, $body);

        return (int) $used;
    }

    private function setQuota(string $id, int $bytes): void
    {
        [$status, $body] = $this->ocs('PUT', '/ocs/v2.php/cloud/users/' . rawurlencode($id), ['key' => 'quota', 'value' => (string) $bytes]);

        self::assertSame(200, $status, $body);
    }

    private function deleteUser(string $id): void
    {
        $this->ocs('DELETE', '/ocs/v2.php/cloud/users/' . rawurlencode($id));
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $fields
     *
     * @return array{0: int, 1: string}
     */
    private function ocs(string $method, string $path, array $fields = []): array
    {
        $settings = self::settings();
        $handle = curl_init(self::serverUrl() . $path . '?format=json');
        self::assertNotFalse($handle);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $settings['userName'] . ':' . $settings['password'],
            CURLOPT_HTTPHEADER => ['OCS-APIRequest: true'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return [(int) $status, is_string($body) ? $body : ''];
    }
}
