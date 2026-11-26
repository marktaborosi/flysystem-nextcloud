<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Internal;

/**
 * Where things live on the Nextcloud server, derived from the WebDAV base URI.
 *
 *   https://cloud.example.com/nextcloud/remote.php/dav/files/alice/Projects/
 *   serverUrl: https://cloud.example.com/nextcloud
 *   userId:    alice (URL-encoded, as in the URI)
 *   folder:    Projects (decoded, relative to the user's files root)
 *
 * @internal
 */
final class ServerLayout
{
    private function __construct(
        public readonly string $serverUrl,
        public readonly ?string $encodedUserId,
        public readonly string $folder,
    ) {
    }

    public static function fromBaseUri(string $baseUri): ?self
    {
        $parts = parse_url($baseUri);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';

        if (preg_match('~^(?<prefix>.*?)/remote\.php/dav/files/(?<user>[^/]+)(?<rest>/.*)?$~', $path, $m) === 1) {
            return new self($origin . $m['prefix'], $m['user'], trim(rawurldecode($m['rest'] ?? ''), '/'));
        }

        // Legacy endpoint: no user id in the URL, so no chunked uploads / SEARCH.
        if (preg_match('~^(?<prefix>.*?)/remote\.php/webdav(?<rest>/.*)?$~', $path, $m) === 1) {
            return new self($origin . $m['prefix'], null, trim(rawurldecode($m['rest'] ?? ''), '/'));
        }

        return null;
    }

    /**
     * Path as Nextcloud's OCS API expects it: relative to the user's files root, with a leading slash.
     */
    public function userPath(string $path): string
    {
        return '/' . ltrim(trim($this->folder . '/' . trim($path, '/'), '/'), '/');
    }

    /**
     * SEARCH scope (relative to /remote.php/dav), or null when the user id is unknown.
     */
    public function searchScope(string $path): ?string
    {
        if ($this->encodedUserId === null) {
            return null;
        }

        return '/files/' . rawurldecode($this->encodedUserId) . $this->userPath($path);
    }

    public function uploadsUrl(): ?string
    {
        return $this->encodedUserId === null ? null : $this->serverUrl . '/remote.php/dav/uploads/' . $this->encodedUserId . '/';
    }

    public function davUrl(): string
    {
        return $this->serverUrl . '/remote.php/dav/';
    }

    public function sharesUrl(): string
    {
        return $this->serverUrl . '/ocs/v2.php/apps/files_sharing/api/v1/shares';
    }
}
