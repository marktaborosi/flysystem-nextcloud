<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Internal;

use Marktaborosi\FlysystemNextcloud\UnexpectedStatusCode;
use RuntimeException;
use Sabre\DAV\Client;

/**
 * Minimal client for Nextcloud's OCS file sharing API (public link shares only).
 *
 * @internal
 */
final class ShareApi
{
    private const SHARE_TYPE_PUBLIC_LINK = 3;
    private const PERMISSION_READ = 1;

    public function __construct(
        private readonly Client $client,
        private readonly ServerLayout $layout,
    ) {
    }

    /**
     * Public link shares of the given path created by the current user.
     *
     * @return list<array{id: string, url: string, hasPassword: bool, expiration: ?string}>|null null when the path does not exist
     */
    public function linkShares(string $path): ?array
    {
        $query = http_build_query(['format' => 'json', 'path' => $this->layout->userPath($path), 'reshares' => 'false']);
        $data = $this->call('GET', $this->layout->sharesUrl() . '?' . $query, allowNotFound: true);

        if ($data === null) {
            return null;
        }

        $shares = [];

        foreach (is_array($data) ? $data : [] as $share) {
            if (!is_array($share) || !is_numeric($share['share_type'] ?? null) || (int) $share['share_type'] !== self::SHARE_TYPE_PUBLIC_LINK) {
                continue;
            }

            $shares[] = [
                'id' => self::stringValue($share['id'] ?? ''),
                'url' => self::stringValue($share['url'] ?? ''),
                'hasPassword' => is_string($share['password'] ?? null) && $share['password'] !== '',
                'expiration' => isset($share['expiration']) && is_string($share['expiration']) ? $share['expiration'] : null,
            ];
        }

        return $shares;
    }

    /**
     * @return string The share URL, e.g. https://cloud.example.com/s/AbCdEf
     */
    public function createLinkShare(string $path): string
    {
        $body = http_build_query([
            'path' => $this->layout->userPath($path),
            'shareType' => self::SHARE_TYPE_PUBLIC_LINK,
            'permissions' => self::PERMISSION_READ,
        ]);

        $data = $this->call('POST', $this->layout->sharesUrl() . '?format=json', $body);
        $url = is_array($data) ? ($data['url'] ?? null) : null;

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Nextcloud did not return a URL for the new public link share.');
        }

        return $url;
    }

    public function deleteShare(string $id): void
    {
        $this->call('DELETE', $this->layout->sharesUrl() . '/' . rawurlencode($id) . '?format=json');
    }

    /**
     * Returns the "data" element of the OCS response, or null for 404 when allowed.
     */
    private function call(string $method, string $url, ?string $body = null, bool $allowNotFound = false): mixed
    {
        $headers = ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        /** @var array{body: string, statusCode: int, headers: array<string, mixed>} $response */
        $response = $this->client->request($method, $url, $body, $headers);
        $payload = json_decode($response['body'], true);
        $meta = is_array($payload) && is_array($payload['ocs'] ?? null) && is_array($payload['ocs']['meta'] ?? null)
            ? $payload['ocs']['meta']
            : null;
        $statusCode = $meta !== null && is_numeric($meta['statuscode'] ?? null) ? (int) $meta['statuscode'] : $response['statusCode'];

        if ($statusCode === 404 && $allowNotFound) {
            return null;
        }

        if ($statusCode < 200 || $statusCode >= 300 || $meta === null) {
            $message = $meta !== null && is_string($meta['message'] ?? null) ? $meta['message'] : null;

            throw new UnexpectedStatusCode('OCS ' . $method, $statusCode, $message);
        }

        /** @var array{ocs: array{data?: mixed}} $payload */
        return $payload['ocs']['data'] ?? null;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
