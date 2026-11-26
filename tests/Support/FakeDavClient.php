<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Support;

use Closure;
use Sabre\DAV\Client;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

/**
 * Sabre DAV client with the network layer replaced by a callback.
 *
 * Everything above the transport (URL resolution, PROPFIND XML parsing,
 * exception handling) is the real Sabre code.
 */
final class FakeDavClient extends Client
{
    /** @var list<array{method: string, url: string, headers: array<mixed>, body: string}> */
    public array $requests = [];

    /** @var Closure(RequestInterface): ResponseInterface */
    private Closure $handler;

    /**
     * @param callable(RequestInterface): ResponseInterface $handler
     */
    public function __construct(callable $handler, string $baseUri = 'https://cloud.example.com/remote.php/dav/files/admin/')
    {
        parent::__construct(['baseUri' => $baseUri]);
        $this->handler = Closure::fromCallable($handler);
    }

    protected function doRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => $request->getUrl(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBodyAsString(),
        ];

        return ($this->handler)($request);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function response(int $status, string $body = '', array $headers = []): Response
    {
        $response = new Response($status, $headers);
        $response->setBody($body);

        return $response;
    }

    /**
     * Builds a 207 Multi-Status PROPFIND response.
     *
     * @param array<string, array{dir?: bool, size?: int, type?: string, modified?: string}> $entries href => properties
     */
    public static function multiStatus(array $entries): Response
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">';

        foreach ($entries as $href => $entry) {
            $props = '<d:resourcetype>' . (($entry['dir'] ?? false) ? '<d:collection/>' : '') . '</d:resourcetype>';

            if (isset($entry['size'])) {
                $props .= '<d:getcontentlength>' . $entry['size'] . '</d:getcontentlength>';
            }

            if (isset($entry['type'])) {
                $props .= '<d:getcontenttype>' . htmlspecialchars($entry['type']) . '</d:getcontenttype>';
            }

            $props .= '<d:getlastmodified>' . ($entry['modified'] ?? 'Tue, 01 Sep 2026 10:00:00 GMT') . '</d:getlastmodified>';

            $xml .= '<d:response><d:href>' . htmlspecialchars($href) . '</d:href>'
                . '<d:propstat><d:prop>' . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
                . '</d:response>';
        }

        return self::response(207, $xml . '</d:multistatus>', ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function sabreError(int $status, string $message): Response
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns">'
            . '<s:exception>Sabre\DAV\Exception</s:exception><s:message>' . htmlspecialchars($message) . '</s:message>'
            . '</d:error>';

        return self::response($status, $xml, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
