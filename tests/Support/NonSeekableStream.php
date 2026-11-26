<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Support;

/**
 * Stream wrapper that produces deterministic pseudo-random bytes and cannot seek,
 * like a pipe, a socket or an HTTP response body.
 *
 *     $stream = NonSeekableStream::open(25 * 1024 * 1024, seed: 'abc');
 */
final class NonSeekableStream
{
    private const PROTOCOL = 'nonseekable';

    /** @var resource|null */
    public $context;

    private int $remaining = 0;

    private string $seed = '';

    private int $counter = 0;

    /**
     * @return resource
     */
    public static function open(int $size, string $seed = 'seed')
    {
        if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        $stream = fopen(self::PROTOCOL . '://' . $size . '/' . rawurlencode($seed), 'rb');
        assert(is_resource($stream));

        return $stream;
    }

    /**
     * The exact bytes open($size, $seed) produces, for comparison.
     */
    public static function expectedMd5(int $size, string $seed = 'seed'): string
    {
        $stream = self::open($size, $seed);
        $context = hash_init('md5');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $parts = parse_url($path);
        $this->remaining = (int) ($parts['host'] ?? 0);
        $this->seed = rawurldecode(ltrim($parts['path'] ?? '', '/'));

        return true;
    }

    public function stream_read(int $count): string
    {
        // Return less than asked on purpose, like a network stream.
        $count = min($count, $this->remaining, 8192 + ($this->counter % 3) * 1000);
        $data = '';

        while (strlen($data) < $count) {
            $data .= hash('sha256', $this->seed . ':' . $this->counter++, true);
        }

        $data = substr($data, 0, $count);
        $this->remaining -= strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->remaining <= 0;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    public function stream_stat(): false
    {
        return false;
    }
}
