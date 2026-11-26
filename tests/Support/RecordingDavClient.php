<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Support;

use Sabre\DAV\Client;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Real Sabre DAV client that records every request it sends (method, URL, status).
 */
final class RecordingDavClient extends Client
{
    /** @var list<array{method: string, url: string, status: int}> */
    public array $log = [];

    public function send(RequestInterface $request): ResponseInterface
    {
        $response = parent::send($request);
        $this->log[] = ['method' => $request->getMethod(), 'url' => $request->getUrl(), 'status' => $response->getStatus()];

        return $response;
    }

    /**
     * @return array<string, int> method => count
     */
    public function methodCounts(): array
    {
        $counts = [];

        foreach ($this->log as $entry) {
            $counts[$entry['method']] = ($counts[$entry['method']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function reset(): void
    {
        $this->log = [];
    }
}
