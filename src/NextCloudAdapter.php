<?php

namespace Marktaborosi\FlysystemNextcloud;

use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use RuntimeException;
use Sabre\DAV\Client;
use Sabre\DAV\Xml\Property\ResourceType;
use Sabre\HTTP\ClientHttpException;
use Throwable;

/**
 * Flysystem v3 adapter for Nextcloud via WebDAV.
 *
 * This adapter allows you to interact with a Nextcloud server using the WebDAV protocol,
 * enabling you to store, retrieve, delete, and manipulate files and directories
 * through the League/Flysystem filesystem abstraction layer.
 *
 * Supported operations:
 * - Listing contents (files, directories)
 * - Reading and writing files
 * - Deleting files and directories
 * - Creating directories
 * - Copying and moving files
 * - Retrieving file metadata (size, last modified, MIME type)
 *
 * Unsupported features:
 * - Setting and retrieving visibility (public/private) - not supported via WebDAV natively
 *
 * @package Marktaborosi\FlysystemNextcloud
 * @author Marktaborosi
 * @license MIT
 */
class NextCloudAdapter implements FilesystemAdapter
{
    private Client $client;
    private string $baseUri;
    private array $visibilities = [];

    public function __construct(array $settings)
    {
        $this->client = new Client($settings);
        $this->baseUri = rtrim(parse_url($settings['baseUri'], PHP_URL_PATH), '/') . '/';
    }

    /**
     * Determine whether a file exists at the given path.
     *
     * @param string $path The file path.
     *
     * @return bool True if the file exists and is not a directory, false otherwise.
     *
     * @throws Throwable If the underlying client throws an unexpected exception.
     */
    public function fileExists(string $path): bool
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind($encodedPath, ['{DAV:}resourcetype']);

            // If no response, the file does not exist.
            if (empty($response)) {
                return false;
            }

            // Check if the path refers to a directory (collection).
            if (
                isset($response['{DAV:}resourcetype']) &&
                $response['{DAV:}resourcetype'] instanceof ResourceType &&
                in_array('{DAV:}collection', $response['{DAV:}resourcetype']->getValue(), true)
            ) {
                return false;
            }

            return true;
        } catch (Throwable) {
            // If any error occurs (e.g., 404 Not Found), treat as "file does not exist".
            return false;
        }
    }

    /**
     * Determine whether a directory exists at the given path.
     *
     * @param string $path The directory path.
     *
     * @return bool True if the directory exists, false otherwise.
     *
     * @throws Throwable If the underlying client throws an unexpected exception.
     */
    public function directoryExists(string $path): bool
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind($encodedPath, ['{DAV:}resourcetype']);

            // If no resource type is available, the directory does not exist.
            if (!isset($response['{DAV:}resourcetype'])) {
                return false;
            }

            $resourceType = $response['{DAV:}resourcetype'];

            // Check if the resource type indicates a collection (directory).
            return $resourceType instanceof ResourceType &&
                in_array('{DAV:}collection', $resourceType->getValue(), true);
        } catch (Throwable) {
            // If any error occurs (e.g., 404 Not Found), treat as "directory does not exist".
            return false;
        }
    }

    /**
     * Write a new file at the given path with the provided contents.
     *
     * @param string $path The path where the file should be written.
     * @param string $contents The file contents to write.
     * @param Config $config Configuration object, potentially containing visibility settings.
     *
     * @throws Throwable If an error occurs during the file upload.
     */
    public function write(string $path, string $contents, Config $config): void
    {
        // Ensure the parent directory exists before writing the file.
        $this->ensureParentDirectoryExists($path);

        // Prepare and encode the path for WebDAV communication.
        $encodedPath = $this->encodePath($path);

        // Store file visibility settings if applicable.
        $this->storeVisibility($config, $path);

        // Upload the file using WebDAV PUT request.
        $this->client->request('PUT', $encodedPath, $contents);
    }

    /**
     * Write a new file at the given path using a stream resource.
     *
     * @param string $path The path where the file should be written.
     * @param resource $contents A readable stream resource containing the file data.
     * @param Config $config Configuration object, potentially containing visibility settings.
     *
     * @throws InvalidArgumentException If the provided contents are not a valid stream resource.
     * @throws RuntimeException If reading from the stream fails.
     * @throws Throwable If an error occurs during the file upload.
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw new InvalidArgumentException('The contents must be a valid stream resource.');
        }

        $data = stream_get_contents($contents);
        if ($data === false) {
            throw new RuntimeException('Failed to read from the provided stream.');
        }

        // Store file visibility settings if applicable.
        $this->storeVisibility($config, $path);

        // Delegate writing to the standard write method.
        $this->write($path, $data, $config);
    }


    /**
     * Read the contents of a file at the given path.
     *
     * @param string $path The path to the file.
     *
     * @return string The file contents.
     *
     * @throws UnableToReadFile If the file does not exist or cannot be read.
     */
    public function read(string $path): string
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->request('GET', $encodedPath);
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        // Validate the response format and ensure a body is present.
        if (empty($response) || !is_array($response) || !isset($response['body'])) {
            throw UnableToReadFile::fromLocation($path, 'No valid response body received.');
        }

        // Check if the response explicitly indicates a missing file.
        if (isset($response['statusCode']) && (int)$response['statusCode'] === 404) {
            throw UnableToReadFile::fromLocation($path, 'File does not exist.');
        }

        return (string) $response['body'];
    }


    /**
     * Read the contents of a file at the given path as a stream.
     *
     * @param string $path The path to the file.
     *
     * @return resource A readable stream resource containing the file contents.
     *
     * @throws UnableToReadFile If the file cannot be read or streamed.
     */
    public function readStream(string $path)
    {
        try {
            $contents = $this->read($path);
        } catch (UnableToReadFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }

        // Create an in-memory stream to hold the file contents.
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to open memory stream.');
        }

        // Write the contents into the stream.
        if (fwrite($stream, $contents) === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to write contents to memory stream.');
        }

        // Rewind the stream to the beginning for reading.
        if (rewind($stream) === false) {
            throw UnableToReadFile::fromLocation($path, 'Unable to rewind memory stream.');
        }

        return $stream;
    }


    /**
     * Delete a file at the given path.
     *
     * @param string $path The path to the file to delete.
     *
     * @throws UnableToDeleteFile If the file cannot be deleted.
     */
    public function delete(string $path): void
    {
        $encodedPath = $this->encodePath($path);

        try {
            $this->client->request('DELETE', $encodedPath);
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Delete a directory at the given path.
     *
     * @param string $path The path to the directory to delete.
     *
     * @throws UnableToDeleteDirectory If the directory cannot be deleted.
     */
    public function deleteDirectory(string $path): void
    {
        try {
            $this->delete($path);
        } catch (UnableToDeleteFile $e) {
            // Re-throw as a directory-specific exception.
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Create a directory at the given path.
     *
     * @param string $path The path where the directory should be created.
     * @param Config $config Configuration object, potentially containing visibility settings.
     *
     * @throws UnableToCreateDirectory If the directory cannot be created.
     * @throws Throwable If a non-standard error occurs.
     */
    public function createDirectory(string $path, Config $config): void
    {
        // Ensure that the parent directory exists before creating the new one.
        $this->ensureParentDirectoryExists($path);

        // Encode the path for WebDAV communication.
        $encodedPath = $this->encodePath($path);

        try {
            // Issue a MKCOL request to create the directory.
            $this->client->request('MKCOL', $encodedPath);
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::dueToFailure($e->getMessage(), $e);
        }
    }


    /**
     * Set the visibility for a file at the given path.
     *
     * Note: Nextcloud WebDAV does not support visibility natively.
     * Visibility is simulated and cached internally within the adapter.
     *
     * @param string $path The path to the file.
     * @param string $visibility Either 'public' or 'private'.
     *
     * @throws UnableToSetVisibility If the file does not exist or visibility cannot be set.
     * @throws Throwable If another unexpected error occurs.
     */
    public function setVisibility(string $path, string $visibility): void
    {
        $path = ltrim($path, '/');

        try {
            if (!$this->fileExists($path)) {
                throw UnableToSetVisibility::atLocation($path, 'File does not exist.');
            }
        } catch (ClientHttpException $e) {
            if ($e->getHttpStatus() === 404) {
                throw UnableToSetVisibility::atLocation($path, 'File does not exist.');
            }
            throw $e;
        }

        // Simulate visibility by caching it internally.
        $this->visibilities[$path] = $visibility;
    }

    /**
     * Retrieve the visibility setting for a file at the given path.
     *
     * Note: Nextcloud WebDAV does not natively support visibility settings.
     * Visibility is simulated and cached internally within the adapter.
     *
     * @param string $path The path to the file.
     *
     * @return FileAttributes The file attributes including the visibility metadata.
     *
     * @throws UnableToRetrieveMetadata|ClientHttpException If the file does not exist or the metadata cannot be retrieved.
     */
    public function visibility(string $path): FileAttributes
    {
        $path = ltrim($path, '/');

        try {
            if (!$this->fileExists($path)) {
                throw UnableToRetrieveMetadata::visibility($path, 'File does not exist.');
            }
        } catch (ClientHttpException $e) {
            if ($e->getHttpStatus() === 404) {
                throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
            }
            throw $e;
        } catch (FilesystemException|Throwable $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }

        // Default to 'private' if visibility has not been explicitly set.
        $visibility = $this->visibilities[$path] ?? Visibility::PRIVATE;

        return new FileAttributes($path, null, $visibility, null);
    }


    /**
     * Retrieve the MIME type for a file at the given path.
     *
     * @param string $path The path to the file.
     *
     * @return FileAttributes The file attributes including MIME type metadata.
     *
     * @throws UnableToRetrieveMetadata If the file is a directory or the MIME type cannot be determined.
     */
    public function mimeType(string $path): FileAttributes
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind($encodedPath, ['{DAV:}getcontenttype', '{DAV:}resourcetype']);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }

        // If the path is a directory, retrieving MIME type is not valid.
        if (isset($response['{DAV:}resourcetype']) &&
            $response['{DAV:}resourcetype'] instanceof ResourceType &&
            in_array('{DAV:}collection', $response['{DAV:}resourcetype']->getValue(), true)
        ) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Path is a directory.');
        }

        // Fetch and validate the MIME type.
        $contentType = $response['{DAV:}getcontenttype'] ?? '';

        if ($contentType === 'application/octet-stream' || trim((string)$contentType) === '') {
            throw UnableToRetrieveMetadata::mimeType($path, 'Missing or unknown content type.');
        }

        return new FileAttributes($path, null, null, null, trim((string) $contentType));
    }



    /**
     * Retrieve the last modified timestamp for a file at the given path.
     *
     * @param string $path The path to the file.
     *
     * @return FileAttributes The file attributes including the last modified timestamp.
     *
     * @throws UnableToRetrieveMetadata If the last modified metadata cannot be determined.
     */
    public function lastModified(string $path): FileAttributes
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind($encodedPath, ['{DAV:}getlastmodified']);

            if (!isset($response['{DAV:}getlastmodified'])) {
                throw UnableToRetrieveMetadata::lastModified($path, 'Last modified property missing.');
            }

            $timestamp = strtotime($response['{DAV:}getlastmodified']);

            if ($timestamp === false) {
                throw UnableToRetrieveMetadata::lastModified($path, 'Invalid last modified date format.');
            }

            return new FileAttributes($path, null, null, $timestamp);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }


    /**
     * Retrieve the file size for a file at the given path.
     *
     * @param string $path The path to the file.
     *
     * @return FileAttributes The file attributes including the file size.
     *
     * @throws UnableToRetrieveMetadata If the path is a directory or the file size cannot be determined.
     */
    public function fileSize(string $path): FileAttributes
    {
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind($encodedPath, ['{DAV:}resourcetype', '{DAV:}getcontentlength']);
        } catch (Throwable $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }

        // If the path is a directory, we cannot retrieve a file size.
        if (isset($response['{DAV:}resourcetype']) &&
            $response['{DAV:}resourcetype'] instanceof ResourceType &&
            in_array('{DAV:}collection', $response['{DAV:}resourcetype']->getValue(), true)
        ) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Path is a directory.');
        }

        // Validate the content length presence.
        if (!isset($response['{DAV:}getcontentlength'])) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Missing content length.');
        }

        return new FileAttributes($path, (int) $response['{DAV:}getcontentlength']);
    }


    /**
     * List the contents of a directory.
     *
     * @param string $path The path to the directory.
     * @param bool $deep Whether to list recursively (deep traversal).
     *
     * @return iterable<StorageAttributes> A list of storage attributes for files and directories.
     *
     * @throws FilesystemException If listing contents fails.
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $path = ltrim($path, '/');
        $encodedPath = $this->encodePath($path);

        try {
            $response = $this->client->propFind(
                $encodedPath,
                [
                    '{DAV:}displayname',
                    '{DAV:}resourcetype',
                    '{DAV:}getcontentlength',
                    '{DAV:}getlastmodified',
                ],
                1
            );
        } catch (Throwable $e) {
            throw UnableToListContents::atLocation($path, $e->getMessage(), $e);
        }

        foreach ($response as $itemPath => $properties) {
            $relativePath = urldecode(parse_url($itemPath, PHP_URL_PATH));


            if (str_starts_with($relativePath, $this->baseUri)) {
                $relativePath = substr($relativePath, strlen($this->baseUri));
            }

            $relativePath = ltrim($relativePath, '/');

            if (rtrim($relativePath, '/') === rtrim($path, '/')) {
                continue;
            }

            $isDirectory = isset($properties['{DAV:}resourcetype']) &&
                $properties['{DAV:}resourcetype'] instanceof ResourceType &&
                in_array('{DAV:}collection', $properties['{DAV:}resourcetype']->getValue(), true);

            if ($isDirectory) {
                yield new DirectoryAttributes(
                    $relativePath,
                    null,
                    isset($properties['{DAV:}getlastmodified']) ? strtotime($properties['{DAV:}getlastmodified']) : null
                );

                if ($deep) {
                    foreach ($this->listContents($relativePath, true) as $child) {
                        yield $child;
                    }
                }
            } else {
                yield new FileAttributes(
                    $relativePath,
                    isset($properties['{DAV:}getcontentlength']) ? (int)$properties['{DAV:}getcontentlength'] : null,
                    null,
                    isset($properties['{DAV:}getlastmodified']) ? strtotime($properties['{DAV:}getlastmodified']) : null
                );
            }
        }
    }

    /**
     * Encode a path by rawurlencoding each segment individually.
     *
     * This method ensures that each part of the path is safely URL-encoded,
     * while preserving the directory structure (slashes are not encoded).
     *
     * @param string $path The path to encode.
     * @return string The URL-encoded path.
     */
    private function encodePath(string $path): string
    {
        $path = ltrim($path, '/');

        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $encodedSegments = array_map('rawurlencode', $segments);

        return implode('/', $encodedSegments);
    }

    /**
     * Move a file or directory to a new location.
     *
     * @param string $source The source path.
     * @param string $destination The destination path.
     * @param Config $config Additional configuration options (currently unused).
     *
     * @throws UnableToMoveFile If the source file does not exist or the move operation fails.
     * @throws Throwable If an unexpected error occurs during the move.
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $encodedSource = $this->encodePath($source);
        $encodedDestination = $this->encodePath($destination);

        try {
            if (!$this->fileExists($source)) {
                throw UnableToMoveFile::fromLocationTo($source, $destination);
            }
        } catch (ClientHttpException $e) {
            if ($e->getHttpStatus() === 404) {
                throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
            }
            throw $e; // Re-throw unexpected HTTP errors
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }

        try {
            $this->client->request(
                'MOVE',
                $encodedSource,
                null,
                [
                    'Destination' => $this->client->getAbsoluteUrl($encodedDestination),
                    'Overwrite' => 'T',
                ]
            );
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }


    /**
     * Copy a file or directory to a new location.
     *
     * @param string $source The source path.
     * @param string $destination The destination path.
     * @param Config $config Additional configuration options (currently unused).
     *
     * @throws UnableToCopyFile If the source file does not exist or the copy operation fails.
     * @throws Throwable If an unexpected error occurs during the copy.
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $encodedSource = $this->encodePath($source);
        $encodedDestination = $this->encodePath($destination);

        try {
            if (!$this->fileExists($source)) {
                throw UnableToCopyFile::fromLocationTo($source, $destination);
            }
        } catch (ClientHttpException $e) {
            if ($e->getHttpStatus() === 404) {
                throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
            }
            throw $e; // Re-throw unexpected HTTP errors
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }

        // Propagate visibility if cached
        if (isset($this->visibilities[$source])) {
            $this->visibilities[$destination] = $this->visibilities[$source];
        }

        try {
            $this->client->request(
                'COPY',
                $encodedSource,
                null,
                [
                    'Destination' => $this->client->getAbsoluteUrl($encodedDestination),
                    'Overwrite' => 'T',
                ]
            );
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Ensures that the parent directory of the given path exists.
     *
     * If the path is directly under the root (e.g., "file.txt"), no action is taken.
     * Otherwise, if the parent directory does not exist, it will be created.
     *
     * @param string $path The file or directory path whose parent directory must exist.
     *
     * @throws FilesystemException If directory creation fails.
     * @throws Throwable If any unexpected error occurs.
     */
    private function ensureParentDirectoryExists(string $path): void
    {
        $dirname = dirname($path);

        // If we're writing directly in root (e.g., "file.txt"), no parent to create
        if ($dirname === '.' || $dirname === '') {
            return;
        }

        if (!$this->directoryExists($dirname)) {
            $this->createDirectory($dirname, new Config());
        }
    }

    /**
     * Stores the visibility setting for a given path.
     *
     * Only "public" and "private" visibilities are stored explicitly.
     * If no visibility is configured, the file remains with default (private) visibility.
     *
     * @param Config $config Configuration object possibly containing visibility settings.
     * @param string $path The path to associate with the visibility setting.
     */
    private function storeVisibility(Config $config, string $path): void

    {
        if ($config->get('visibility') === 'public') {
            $this->visibilities[$path] = Visibility::PUBLIC;
        } elseif ($config->get('visibility') === 'private') {
            $this->visibilities[$path] = Visibility::PRIVATE;
        }


    }
}
