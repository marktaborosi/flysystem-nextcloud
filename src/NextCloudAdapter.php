<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud;

use InvalidArgumentException;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\Visibility;
use Marktaborosi\FlysystemNextcloud\Internal\ServerLayout;
use Marktaborosi\FlysystemNextcloud\Internal\ShareApi;
use RuntimeException;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientHttpException;
use Throwable;

/**
 * Flysystem v3 adapter for Nextcloud.
 *
 * Files are handled over WebDAV (PROPFIND, GET, PUT, DELETE, MKCOL, MOVE, COPY),
 * public links over the OCS sharing API. On top of plain WebDAV it supports:
 *
 * - chunked uploads for large files (Nextcloud's upload API),
 * - checksums (MD5 and SHA1 are stored with every upload),
 * - public URLs (read-only public link shares),
 * - optionally, visibility backed by public link shares ({@see VisibilityMode::PublicLinks}),
 * - optionally, deep listings with a single SEARCH request.
 *
 * HTTP errors are always reported as the matching Flysystem exception; the underlying
 * {@see UnexpectedStatusCode} (or transport error) is attached as "previous".
 */
class NextCloudAdapter implements FilesystemAdapter, PublicUrlGenerator, ChecksumProvider
{
    use CalculateChecksumFromStream;

    /** Files larger than this are uploaded in chunks by default (10 MiB). */
    public const DEFAULT_CHUNK_SIZE = 10 * 1024 * 1024;

    private const PROP_RESOURCE_TYPE = '{DAV:}resourcetype';
    private const PROP_CONTENT_LENGTH = '{DAV:}getcontentlength';
    private const PROP_CONTENT_TYPE = '{DAV:}getcontenttype';
    private const PROP_LAST_MODIFIED = '{DAV:}getlastmodified';
    private const PROP_CHECKSUMS = '{http://owncloud.org/ns}checksums';

    private const LISTING_PROPERTIES = [
        self::PROP_RESOURCE_TYPE,
        self::PROP_CONTENT_LENGTH,
        self::PROP_CONTENT_TYPE,
        self::PROP_LAST_MODIFIED,
    ];

    private const UNKNOWN_MIME_TYPE = 'application/octet-stream';

    /** Nextcloud accepts chunk numbers 1 to 10000. */
    private const MAX_CHUNKS = 10000;

    /** A SEARCH answer with this many results may be truncated, so we fall back to PROPFIND. */
    private const SEARCH_LIMIT = 10000;

    private const READ_BUFFER = 1024 * 1024;

    private readonly Client $client;

    /** Decoded URL path of the base URI, always with a trailing slash (e.g. "/remote.php/dav/files/admin/"). */
    private readonly string $basePath;

    private readonly ?ServerLayout $layout;

    private ?ShareApi $shareApi = null;

    /** @var array<string, string> In-memory visibility per normalized path ({@see VisibilityMode::InMemory}). */
    private array $visibilities = [];

    /**
     * @param Client|array{baseUri: string, userName?: string, password?: string, authType?: int, proxy?: string, encoding?: int} $client
     *        Either a configured Sabre DAV client, or the settings array passed to {@see Client::__construct()}.
     *        The base URI should point at the user's files root (or a folder in it), e.g.
     *        "https://cloud.example.com/remote.php/dav/files/{userId}/".
     * @param VisibilityMode $visibilityMode How public/private visibility is handled.
     * @param int|null $chunkSize Files larger than this many bytes are uploaded in chunks of this size.
     *        null disables chunked uploads. Keep it at 5 MiB or more if Nextcloud uses S3 as primary storage.
     * @param bool $searchDeepListing Use a single SEARCH request for deep listings (much faster for big trees).
     *        SEARCH reads Nextcloud's file index, which can lag behind external storages that are changed
     *        outside of Nextcloud. Falls back to PROPFIND automatically when SEARCH is unavailable.
     */
    public function __construct(
        Client|array $client,
        private readonly VisibilityMode $visibilityMode = VisibilityMode::InMemory,
        private readonly ?int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        private readonly bool $searchDeepListing = false,
    ) {
        if ($chunkSize !== null && $chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be a positive number of bytes, or null to disable chunked uploads.');
        }

        if (is_array($client)) {
            $client = new Client(self::normalizeSettings($client));
        }

        $this->client = $client;

        $baseUri = $this->client->getAbsoluteUrl('');
        $basePath = parse_url($baseUri, PHP_URL_PATH);
        $this->basePath = rtrim(rawurldecode(is_string($basePath) ? $basePath : ''), '/') . '/';
        $this->layout = ServerLayout::fromBaseUri($baseUri);
    }

    /**
     * Convenience constructor: builds the WebDAV URL from the server URL and the user.
     *
     *     NextCloudAdapter::fromCredentials('https://cloud.example.com', 'alice', 'app-password', folder: 'Projects')
     *
     * @param string $serverUrl The Nextcloud URL as you open it in the browser (including a sub-path, if any).
     * @param string $folder Optional folder inside the user's files; all paths become relative to it.
     * @param string|null $userId The user id, when it differs from the login name (LDAP, e-mail logins, ...).
     */
    public static function fromCredentials(
        string $serverUrl,
        string $username,
        string $password,
        string $folder = '',
        ?string $userId = null,
        VisibilityMode $visibilityMode = VisibilityMode::InMemory,
        ?int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        bool $searchDeepListing = false,
    ): self {
        $segments = array_filter(explode('/', trim($folder, '/')), static fn (string $s): bool => $s !== '');
        $baseUri = rtrim($serverUrl, '/') . '/remote.php/dav/files/' . rawurlencode($userId ?? $username) . '/'
            . implode('/', array_map(rawurlencode(...), $segments));

        return new self(
            ['baseUri' => $baseUri, 'userName' => $username, 'password' => $password],
            $visibilityMode,
            $chunkSize,
            $searchDeepListing,
        );
    }

    public function fileExists(string $path): bool
    {
        try {
            $properties = $this->propFind($path, [self::PROP_RESOURCE_TYPE]);
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }

        return $properties !== null && !self::isDirectory($properties);
    }

    public function directoryExists(string $path): bool
    {
        try {
            $properties = $this->propFind($path, [self::PROP_RESOURCE_TYPE]);
        } catch (Throwable $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }

        return $properties !== null && self::isDirectory($properties);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'The contents must be a valid stream resource.');
        }

        $this->upload($path, $contents, $config);
    }

    public function read(string $path): string
    {
        try {
            $response = $this->request('GET', $path);
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }

        if ($response['statusCode'] !== 200) {
            $exception = UnexpectedStatusCode::fromResponse('GET', $response);

            throw UnableToReadFile::fromLocation(
                $path,
                $response['statusCode'] === 404 ? 'File does not exist.' : $exception->getMessage(),
                $exception,
            );
        }

        return $response['body'];
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        $contents = $this->read($path);

        // php://temp keeps small files in memory and spills large ones to disk.
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false || fwrite($stream, $contents) === false || !rewind($stream)) {
            throw UnableToReadFile::fromLocation($path, 'Unable to create a stream for the file contents.');
        }

        return $stream;
    }

    public function delete(string $path): void
    {
        try {
            $response = $this->request('DELETE', $path);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        // Deleting a file that does not exist is not an error in Flysystem.
        if (!self::isSuccessful($response['statusCode']) && $response['statusCode'] !== 404) {
            $exception = UnexpectedStatusCode::fromResponse('DELETE', $response);

            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }

        unset($this->visibilities[self::normalize($path)]);
    }

    public function deleteDirectory(string $path): void
    {
        $normalized = self::normalize($path);

        // Never wipe the user's whole Nextcloud storage by accident.
        if ($normalized === '') {
            throw UnableToDeleteDirectory::atLocation($path, 'Refusing to delete the root directory.');
        }

        try {
            $response = $this->request('DELETE', $normalized . '/');
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }

        if (!self::isSuccessful($response['statusCode']) && $response['statusCode'] !== 404) {
            $exception = UnexpectedStatusCode::fromResponse('DELETE', $response);

            throw UnableToDeleteDirectory::atLocation($path, $exception->getMessage(), $exception);
        }

        foreach (array_keys($this->visibilities) as $key) {
            if (str_starts_with($key, $normalized . '/')) {
                unset($this->visibilities[$key]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $normalized = self::normalize($path);

        if ($normalized === '') {
            return;
        }

        try {
            $this->ensureParentDirectoryExists($normalized);
            $response = $this->request('MKCOL', $normalized . '/');

            if (self::isSuccessful($response['statusCode'])) {
                return;
            }

            // 405 Method Not Allowed: something already exists at this location.
            // That is fine (createDirectory is idempotent) as long as it is a directory.
            if ($response['statusCode'] === 405 && $this->directoryExists($normalized)) {
                return;
            }
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
        }

        $exception = UnexpectedStatusCode::fromResponse('MKCOL', $response);

        throw UnableToCreateDirectory::atLocation($path, $exception->getMessage(), $exception);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (!in_array($visibility, [Visibility::PUBLIC, Visibility::PRIVATE], true)) {
            throw UnableToSetVisibility::atLocation($path, sprintf('Unknown visibility "%s".', $visibility));
        }

        if ($this->visibilityMode === VisibilityMode::PublicLinks) {
            $this->setVisibilityWithShares($path, $visibility);

            return;
        }

        try {
            $exists = $this->fileExists($path);
        } catch (FilesystemOperationFailed $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }

        if (!$exists) {
            throw UnableToSetVisibility::atLocation($path, 'File does not exist.');
        }

        $this->visibilities[self::normalize($path)] = $visibility;
    }

    public function visibility(string $path): FileAttributes
    {
        $normalized = self::normalize($path);

        if ($this->visibilityMode === VisibilityMode::PublicLinks) {
            try {
                $shares = $this->shareApi()->linkShares($normalized);
            } catch (Throwable $exception) {
                throw UnableToRetrieveMetadata::visibility($path, $exception->getMessage(), $exception);
            }

            if ($shares === null) {
                throw UnableToRetrieveMetadata::visibility($path, 'File does not exist.');
            }

            return new FileAttributes($normalized, visibility: $shares === [] ? Visibility::PRIVATE : Visibility::PUBLIC);
        }

        try {
            $exists = $this->fileExists($path);
        } catch (FilesystemOperationFailed $exception) {
            throw UnableToRetrieveMetadata::visibility($path, $exception->getMessage(), $exception);
        }

        if (!$exists) {
            throw UnableToRetrieveMetadata::visibility($path, 'File does not exist.');
        }

        return new FileAttributes($normalized, visibility: $this->visibilities[$normalized] ?? Visibility::PRIVATE);
    }

    public function mimeType(string $path): FileAttributes
    {
        $properties = $this->fileProperties($path, [self::PROP_CONTENT_TYPE], UnableToRetrieveMetadata::mimeType(...));
        $mimeType = self::mimeTypeFrom($properties);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Missing or unknown content type.');
        }

        return new FileAttributes(self::normalize($path), mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $properties = $this->fileProperties($path, [self::PROP_LAST_MODIFIED], UnableToRetrieveMetadata::lastModified(...));
        $lastModified = self::lastModifiedFrom($properties);

        if ($lastModified === null) {
            throw UnableToRetrieveMetadata::lastModified($path, 'Missing or invalid last modified date.');
        }

        return new FileAttributes(self::normalize($path), lastModified: $lastModified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $properties = $this->fileProperties($path, [self::PROP_CONTENT_LENGTH], UnableToRetrieveMetadata::fileSize(...));
        $fileSize = self::fileSizeFrom($properties);

        if ($fileSize === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Missing content length.');
        }

        return new FileAttributes(self::normalize($path), $fileSize);
    }

    /**
     * Returns the checksum Nextcloud stored with the file (MD5 and SHA1 are sent with every upload
     * made by this adapter). For other algorithms, or files uploaded by other clients, the file is
     * downloaded and hashed. Use the "checksum_algo" config option to pick the algorithm (default md5).
     */
    public function checksum(string $path, Config $config): string
    {
        $algorithm = $config->get('checksum_algo', 'md5');
        $algorithm = strtolower(is_string($algorithm) ? $algorithm : 'md5');

        try {
            $properties = $this->propFind($path, [self::PROP_CHECKSUMS, self::PROP_RESOURCE_TYPE]);
        } catch (Throwable $exception) {
            throw new UnableToProvideChecksum($exception->getMessage(), $path, $exception);
        }

        if ($properties === null) {
            throw new UnableToProvideChecksum('File does not exist.', $path);
        }

        if (self::isDirectory($properties)) {
            throw new UnableToProvideChecksum('Path is a directory.', $path);
        }

        $stored = self::checksumsFrom($properties);

        return $stored[$algorithm] ?? $this->calculateChecksumFromStream($path, $config);
    }

    /**
     * Returns a direct download URL backed by a read-only public link share.
     *
     * An existing public link of the file (without password and not expired) is reused,
     * otherwise one is created. Note that this makes the file publicly accessible.
     */
    public function publicUrl(string $path, Config $config): string
    {
        try {
            $api = $this->shareApi();
            $shares = $api->linkShares($path);

            if ($shares === null) {
                throw new UnableToGeneratePublicUrl('File does not exist.', $path);
            }

            $url = null;

            foreach ($shares as $share) {
                $expired = $share['expiration'] !== null && ($time = strtotime($share['expiration'])) !== false && $time <= time();

                if (!$share['hasPassword'] && !$expired && $share['url'] !== '') {
                    $url = $share['url'];
                    break;
                }
            }

            $url ??= $api->createLinkShare($path);
        } catch (UnableToGeneratePublicUrl $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToGeneratePublicUrl::dueToError($path, $exception);
        }

        return rtrim($url, '/') . '/download';
    }

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = self::normalize($path);

        if ($deep && $this->searchDeepListing) {
            $items = $this->searchListing($directory);

            if ($items !== null) {
                foreach ($items as $item) {
                    yield $item;
                }

                return;
            }
        }

        try {
            /** @var array<string, array<string, mixed>> $response */
            $response = $this->client->propFind($this->encodePath($directory === '' ? '' : $directory . '/'), self::LISTING_PROPERTIES, 1);
        } catch (ClientHttpException $exception) {
            // Like the local adapter: listing a directory that does not exist yields nothing.
            if ($exception->getHttpStatus() === 404) {
                return;
            }

            throw UnableToListContents::atLocation($path, $deep, $exception);
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($path, $deep, $exception);
        }

        foreach ($response as $href => $properties) {
            $itemPath = $this->pathFromHref((string) $href);

            // The first entry of a Depth: 1 PROPFIND is the directory itself.
            if ($itemPath === null || $itemPath === $directory) {
                continue;
            }

            yield $this->attributesFor($itemPath, $properties);

            if ($deep && self::isDirectory($properties)) {
                // Not "yield from": that would reuse the child generator's keys and
                // make iterator_to_array() overwrite entries.
                foreach ($this->listContents($itemPath, true) as $child) {
                    yield $child;
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (self::normalize($source) === self::normalize($destination)) {
            return;
        }

        try {
            if (!$this->fileExists($source)) {
                throw UnableToMoveFile::because('Source file does not exist.', $source, $destination);
            }

            $this->ensureParentDirectoryExists($destination);
            $response = $this->request('MOVE', $source, null, [
                'Destination' => $this->client->getAbsoluteUrl($this->encodePath($destination)),
                'Overwrite' => 'T',
            ]);
        } catch (UnableToMoveFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }

        if (!self::isSuccessful($response['statusCode'])) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, UnexpectedStatusCode::fromResponse('MOVE', $response));
        }

        $this->transferVisibility($source, $destination, $config, move: true);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if (self::normalize($source) === self::normalize($destination)) {
            return;
        }

        try {
            if (!$this->fileExists($source)) {
                throw UnableToCopyFile::because('Source file does not exist.', $source, $destination);
            }

            $this->ensureParentDirectoryExists($destination);
            $response = $this->request('COPY', $source, null, [
                'Destination' => $this->client->getAbsoluteUrl($this->encodePath($destination)),
                'Overwrite' => 'T',
            ]);
        } catch (UnableToCopyFile $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }

        if (!self::isSuccessful($response['statusCode'])) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, UnexpectedStatusCode::fromResponse('COPY', $response));
        }

        $this->transferVisibility($source, $destination, $config, move: false);
    }

    /**
     * @param string|resource $contents
     */
    private function upload(string $path, $contents, Config $config): void
    {
        $spooled = null;

        try {
            $this->ensureParentDirectoryExists($path);
            [$body, $size, $checksums, $spooled] = self::prepareUpload($contents);

            $uploadsUrl = $this->layout?->uploadsUrl();

            if ($this->chunkSize !== null && $size > $this->chunkSize && $uploadsUrl !== null) {
                $this->uploadInChunks($uploadsUrl, $path, $body, $size, $checksums);
            } else {
                $response = $this->request('PUT', $path, $body, ['OC-Checksum' => $checksums]);
                self::expectSuccess('PUT', $response);
            }
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        } finally {
            if (is_resource($spooled)) {
                fclose($spooled);
            }
        }

        $visibility = $config->get(Config::OPTION_VISIBILITY);

        if ($visibility !== Visibility::PUBLIC && $visibility !== Visibility::PRIVATE) {
            return;
        }

        if ($this->visibilityMode === VisibilityMode::PublicLinks) {
            $this->setVisibilityWithShares($path, $visibility);
        } else {
            $this->visibilities[self::normalize($path)] = $visibility;
        }
    }

    /**
     * Makes the upload body replayable with a known size and computes the checksums in the same pass.
     * Seekable streams at offset 0 are used as is; anything else is spooled to php://temp.
     *
     * @param string|resource $contents
     *
     * @return array{0: string|resource, 1: int, 2: string, 3: resource|null}
     */
    private static function prepareUpload($contents): array
    {
        if (is_string($contents)) {
            return [$contents, strlen($contents), self::checksumHeader(md5($contents), sha1($contents)), null];
        }

        $md5 = hash_init('md5');
        $sha1 = hash_init('sha1');
        $size = 0;
        // "seekable" is reported for every user-space stream wrapper, even ones that cannot seek
        // (e.g. PSR-7 bodies wrapped by Guzzle), so actually try it before relying on it.
        $useAsIs = stream_get_meta_data($contents)['seekable']
            && ftell($contents) === 0
            && fseek($contents, 0) === 0;
        $spooled = null;

        if (!$useAsIs) {
            $spooled = fopen('php://temp', 'w+b');

            if ($spooled === false) {
                throw new RuntimeException('Unable to buffer the provided stream.');
            }
        }

        while (!feof($contents)) {
            $buffer = fread($contents, self::READ_BUFFER);

            if ($buffer === false) {
                throw new RuntimeException('Unable to read from the provided stream.');
            }

            if ($buffer === '') {
                continue;
            }

            hash_update($md5, $buffer);
            hash_update($sha1, $buffer);
            $size += strlen($buffer);

            if ($spooled !== null && fwrite($spooled, $buffer) === false) {
                throw new RuntimeException('Unable to buffer the provided stream.');
            }
        }

        $body = $spooled ?? $contents;

        if (!rewind($body)) {
            throw new RuntimeException('Unable to rewind the upload stream.');
        }

        return [$body, $size, self::checksumHeader(hash_final($md5), hash_final($sha1)), $spooled];
    }

    /**
     * Nextcloud's chunked upload: MKCOL an upload folder, PUT numbered chunks, MOVE ".file" to the target.
     * Works with chunking v2 (Redis/Memcached on the server) and the older assembly fallback.
     *
     * @param string|resource $body
     */
    private function uploadInChunks(string $uploadsUrl, string $path, $body, int $size, string $checksums): void
    {
        assert($this->chunkSize !== null);

        $chunkSize = max($this->chunkSize, (int) ceil($size / self::MAX_CHUNKS));
        $uploadUrl = $uploadsUrl . 'flysystem-' . bin2hex(random_bytes(16));
        $headers = [
            'Destination' => $this->client->getAbsoluteUrl($this->encodePath($path)),
            'OC-Total-Length' => (string) $size,
        ];

        try {
            self::expectSuccess('MKCOL', $this->requestUrl('MKCOL', $uploadUrl, null, $headers));

            for ($offset = 0, $number = 1; $offset < $size; ++$number) {
                $chunk = is_string($body) ? substr($body, $offset, $chunkSize) : stream_get_contents($body, $chunkSize);

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException(sprintf('The stream ended after %d of %d bytes.', $offset, $size));
                }

                self::expectSuccess('PUT', $this->requestUrl('PUT', sprintf('%s/%05d', $uploadUrl, $number), $chunk, $headers));
                $offset += strlen($chunk);
            }

            self::expectSuccess('MOVE', $this->requestUrl('MOVE', $uploadUrl . '/.file', null, $headers + [
                'OC-Checksum' => $checksums,
                'Overwrite' => 'T',
            ]));
        } catch (Throwable $exception) {
            try {
                $this->client->request('DELETE', $uploadUrl);
            } catch (Throwable) {
                // Best effort: Nextcloud also cleans up stale uploads itself.
            }

            throw $exception;
        }
    }

    /**
     * Deep listing in one request. Returns null when SEARCH is unavailable or the result may be incomplete.
     *
     * @return list<StorageAttributes>|null
     */
    private function searchListing(string $directory): ?array
    {
        $layout = $this->layout;
        $scope = $layout?->searchScope($directory);

        if ($layout === null || $scope === null) {
            return null;
        }

        $select = '';

        foreach (self::LISTING_PROPERTIES as $property) {
            [$namespace, $name] = explode('}', ltrim($property, '{'), 2);
            $select .= sprintf('<%s xmlns="%s"/>', $name, $namespace);
        }

        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<d:searchrequest xmlns:d="DAV:"><d:basicsearch>'
            . '<d:select><d:prop>' . $select . '</d:prop></d:select>'
            . '<d:from><d:scope><d:href>' . htmlspecialchars($scope, ENT_XML1) . '</d:href><d:depth>infinity</d:depth></d:scope></d:from>'
            . '<d:where><d:like><d:prop><d:displayname/></d:prop><d:literal>%</d:literal></d:like></d:where>'
            . '<d:orderby/>'
            . '<d:limit><d:nresults>' . self::SEARCH_LIMIT . '</d:nresults></d:limit>'
            . '</d:basicsearch></d:searchrequest>';

        try {
            $response = $this->requestUrl('SEARCH', $layout->davUrl(), $body, ['Content-Type' => 'text/xml; charset=utf-8']);

            if ($response['statusCode'] !== 207) {
                return null;
            }

            /** @var array<string, array<int, array<string, mixed>>> $result */
            $result = $this->client->parseMultiStatus($response['body']);
        } catch (Throwable) {
            return null;
        }

        if (count($result) >= self::SEARCH_LIMIT) {
            return null;
        }

        $prefix = $directory === '' ? '' : $directory . '/';
        $items = [];

        foreach ($result as $href => $statuses) {
            $itemPath = $this->pathFromHref((string) $href);

            if ($itemPath === null || $itemPath === '' || $itemPath === $directory || !str_starts_with($itemPath, $prefix)) {
                continue;
            }

            $items[$itemPath] = $this->attributesFor($itemPath, $statuses[200] ?? []);
        }

        // Parents before children, like a PROPFIND walk.
        ksort($items, SORT_STRING);

        return array_values($items);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function attributesFor(string $path, array $properties): StorageAttributes
    {
        if (self::isDirectory($properties)) {
            return new DirectoryAttributes($path, lastModified: self::lastModifiedFrom($properties));
        }

        return new FileAttributes(
            $path,
            fileSize: self::fileSizeFrom($properties),
            visibility: $this->visibilityMode === VisibilityMode::InMemory ? ($this->visibilities[$path] ?? null) : null,
            lastModified: self::lastModifiedFrom($properties),
            mimeType: self::mimeTypeFrom($properties),
        );
    }

    private function setVisibilityWithShares(string $path, string $visibility): void
    {
        try {
            $api = $this->shareApi();
            $shares = $api->linkShares($path);

            if ($shares === null) {
                throw UnableToSetVisibility::atLocation($path, 'File does not exist.');
            }

            if ($visibility === Visibility::PUBLIC && $shares === []) {
                $api->createLinkShare($path);
            }

            if ($visibility === Visibility::PRIVATE) {
                foreach ($shares as $share) {
                    $api->deleteShare($share['id']);
                }
            }
        } catch (UnableToSetVisibility $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToSetVisibility::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    private function shareApi(): ShareApi
    {
        if ($this->layout === null) {
            throw new RuntimeException('Cannot derive the Nextcloud server URL from the base URI; expected ".../remote.php/dav/files/{userId}/".');
        }

        return $this->shareApi ??= new ShareApi($this->client, $this->layout);
    }

    /**
     * @param string|resource|null $body
     * @param array<string, string> $headers
     *
     * @return array{body: string, statusCode: int, headers: array<string, mixed>}
     */
    private function request(string $method, string $path, $body = null, array $headers = []): array
    {
        /** @var array{body: string, statusCode: int, headers: array<string, mixed>} */
        return $this->client->request($method, $this->encodePath($path), $body, $headers);
    }

    /**
     * Request to an absolute URL (upload folders, SEARCH endpoint).
     *
     * @param string|resource|null $body
     * @param array<string, string> $headers
     *
     * @return array{body: string, statusCode: int, headers: array<string, mixed>}
     */
    private function requestUrl(string $method, string $url, $body = null, array $headers = []): array
    {
        /** @var array{body: string, statusCode: int, headers: array<string, mixed>} */
        return $this->client->request($method, $url, $body, $headers);
    }

    /**
     * @param array{body: string, statusCode: int, headers: array<string, mixed>} $response
     */
    private static function expectSuccess(string $method, array $response): void
    {
        if (!self::isSuccessful($response['statusCode'])) {
            throw UnexpectedStatusCode::fromResponse($method, $response);
        }
    }

    /**
     * Depth 0 PROPFIND. Returns null when the resource does not exist.
     *
     * @param list<string> $properties
     *
     * @return array<string, mixed>|null
     */
    private function propFind(string $path, array $properties): ?array
    {
        try {
            /** @var array<string, mixed> */
            return $this->client->propFind($this->encodePath($path), $properties);
        } catch (ClientHttpException $exception) {
            if ($exception->getHttpStatus() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * PROPFIND for a metadata call: fails when the path is missing or a directory.
     *
     * @param list<string> $properties
     * @param callable(string, string, ?Throwable): UnableToRetrieveMetadata $fail
     *
     * @return array<string, mixed>
     */
    private function fileProperties(string $path, array $properties, callable $fail): array
    {
        try {
            $result = $this->propFind($path, [...$properties, self::PROP_RESOURCE_TYPE]);
        } catch (Throwable $exception) {
            throw $fail($path, $exception->getMessage(), $exception);
        }

        if ($result === null) {
            throw $fail($path, 'File does not exist.', null);
        }

        if (self::isDirectory($result)) {
            throw $fail($path, 'Path is a directory.', null);
        }

        return $result;
    }

    private function ensureParentDirectoryExists(string $path): void
    {
        $parent = dirname(self::normalize($path));

        if ($parent === '.' || $parent === '' || $parent === '/') {
            return;
        }

        if (!$this->directoryExists($parent)) {
            $this->createDirectory($parent, new Config());
        }
    }

    private function transferVisibility(string $source, string $destination, Config $config, bool $move): void
    {
        $source = self::normalize($source);
        $destination = self::normalize($destination);
        $explicit = $config->get(Config::OPTION_VISIBILITY);

        if ($this->visibilityMode === VisibilityMode::PublicLinks) {
            // Shares belong to the file, so they follow a MOVE on their own. A COPY is a new file.
            $visibility = $explicit;

            if ($visibility === null && !$move && $config->get(Config::OPTION_RETAIN_VISIBILITY, true)) {
                $visibility = $this->visibility($source)->visibility();
            }

            if ($visibility === Visibility::PUBLIC || $visibility === Visibility::PRIVATE) {
                $this->setVisibilityWithShares($destination, $visibility);
            }

            return;
        }

        $visibility = $explicit ?? $this->visibilities[$source] ?? null;

        if ($move) {
            unset($this->visibilities[$source]);
        }

        if (is_string($visibility)) {
            $this->visibilities[$destination] = $visibility;
        } else {
            unset($this->visibilities[$destination]);
        }
    }

    /**
     * Turns an href from a PROPFIND/SEARCH response into a path relative to the base URI.
     */
    private function pathFromHref(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);

        if (!is_string($path)) {
            return null;
        }

        $path = rawurldecode($path);

        if (str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        } elseif (rtrim($path, '/') . '/' === $this->basePath) {
            $path = '';
        } else {
            return null;
        }

        return self::normalize($path);
    }

    /**
     * Encodes every path segment, keeping the slashes.
     */
    private function encodePath(string $path): string
    {
        $trailingSlash = str_ends_with($path, '/');
        $path = self::normalize($path);

        if ($path === '') {
            return '';
        }

        return implode('/', array_map(rawurlencode(...), explode('/', $path))) . ($trailingSlash ? '/' : '');
    }

    private static function normalize(string $path): string
    {
        return trim($path, '/');
    }

    private static function isSuccessful(int $statusCode): bool
    {
        return $statusCode >= 200 && $statusCode < 300;
    }

    private static function checksumHeader(string $md5, string $sha1): string
    {
        return 'MD5:' . $md5 . ' SHA1:' . $sha1;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, string> lower-case algorithm => lower-case hex digest
     */
    private static function checksumsFrom(array $properties): array
    {
        $raw = $properties[self::PROP_CHECKSUMS] ?? null;
        $values = [];

        if (is_string($raw)) {
            $values = preg_split('/\s+/', trim($raw)) ?: [];
        } elseif (is_array($raw)) {
            foreach ($raw as $element) {
                $value = is_array($element) ? ($element['value'] ?? null) : $element;

                if (is_string($value)) {
                    array_push($values, ...(preg_split('/\s+/', trim($value)) ?: []));
                }
            }
        }

        $checksums = [];

        foreach ($values as $value) {
            if (preg_match('/^([A-Za-z0-9]+):([0-9A-Fa-f]+)$/', $value, $m) === 1) {
                $checksums[strtolower($m[1])] = strtolower($m[2]);
            }
        }

        return $checksums;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function isDirectory(array $properties): bool
    {
        $resourceType = $properties[self::PROP_RESOURCE_TYPE] ?? null;

        return $resourceType instanceof ResourceType && $resourceType->is('{DAV:}collection');
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function fileSizeFrom(array $properties): ?int
    {
        $size = $properties[self::PROP_CONTENT_LENGTH] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function lastModifiedFrom(array $properties): ?int
    {
        $date = $properties[self::PROP_LAST_MODIFIED] ?? null;

        if (!is_string($date)) {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function mimeTypeFrom(array $properties): ?string
    {
        $mimeType = $properties[self::PROP_CONTENT_TYPE] ?? null;

        if (!is_string($mimeType)) {
            return null;
        }

        // Strip parameters such as "; charset=utf-8".
        $mimeType = trim(explode(';', $mimeType, 2)[0]);

        return $mimeType === '' || $mimeType === self::UNKNOWN_MIME_TYPE ? null : $mimeType;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private static function normalizeSettings(array $settings): array
    {
        if (!isset($settings['baseUri']) || !is_string($settings['baseUri']) || trim($settings['baseUri']) === '') {
            throw new InvalidArgumentException('The "baseUri" setting is required, e.g. "https://cloud.example.com/remote.php/dav/files/{userId}/".');
        }

        // Without a trailing slash, relative paths resolve against the *parent* directory
        // ("…/files/admin" + "a.txt" => "…/files/a.txt").
        $settings['baseUri'] = rtrim($settings['baseUri'], '/') . '/';

        // Nextcloud supports Basic auth (ideally with an app password). Sending it
        // preemptively avoids the 401 challenge round-trip cURL makes when it has to
        // negotiate between Basic and Digest, which halves the number of requests.
        if (isset($settings['userName']) && !isset($settings['authType'])) {
            $settings['authType'] = Client::AUTH_BASIC;
        }

        return $settings;
    }
}
