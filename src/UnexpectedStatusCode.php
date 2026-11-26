<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud;

use RuntimeException;

/**
 * Raised internally when Nextcloud answers a WebDAV request with an unexpected HTTP status.
 *
 * The adapter never lets this exception escape on its own: it is always attached as the
 * "previous" exception of the corresponding Flysystem exception (UnableToWriteFile, ...),
 * so callers can inspect the status code and the server's error message when needed:
 *
 *     catch (UnableToWriteFile $e) {
 *         $status = $e->getPrevious() instanceof UnexpectedStatusCode
 *             ? $e->getPrevious()->statusCode
 *             : null;
 *     }
 */
final class UnexpectedStatusCode extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly int $statusCode,
        public readonly ?string $serverMessage = null,
    ) {
        $message = sprintf('%s request failed with HTTP status %d', $method, $statusCode);

        if ($serverMessage !== null && $serverMessage !== '') {
            $message .= ': ' . $serverMessage;
        }

        parent::__construct($message, $statusCode);
    }

    /**
     * @param array{body: string, statusCode: int, headers: array<string, mixed>} $response
     */
    public static function fromResponse(string $method, array $response): self
    {
        return new self($method, $response['statusCode'], self::extractServerMessage($response['body']));
    }

    /**
     * Nextcloud (like every Sabre based server) returns errors as
     * <d:error><s:exception>...</s:exception><s:message>...</s:message></d:error>.
     */
    private static function extractServerMessage(string $body): ?string
    {
        if ($body === '' || !str_contains($body, 'message>')) {
            return null;
        }

        if (preg_match('~<(?:[a-z]+:)?message>(.*?)</(?:[a-z]+:)?message>~si', $body, $matches) === 1) {
            $message = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_XML1));

            return $message === '' ? null : $message;
        }

        return null;
    }
}
