<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Integration;

use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Throwable;

/**
 * Connection settings and "skip or fail when Nextcloud is down" logic for integration tests.
 */
trait RequiresNextcloud
{
    private static ?string $nextcloudUnavailable = null;

    private static bool $nextcloudChecked = false;

    protected static function checkNextcloud(): void
    {
        if (self::$nextcloudChecked) {
            return;
        }

        self::$nextcloudChecked = true;

        try {
            (new NextCloudAdapter(self::settings()))->directoryExists('');
        } catch (Throwable $exception) {
            self::$nextcloudUnavailable = sprintf(
                'Nextcloud is not reachable at %s (%s). Start it with "docker compose up -d --wait".',
                self::env('NEXTCLOUD_BASE_URI'),
                $exception->getPrevious()?->getMessage() ?? $exception->getMessage(),
            );
        }
    }

    protected function skipOrFailWithoutNextcloud(): void
    {
        self::checkNextcloud();

        if (self::$nextcloudUnavailable === null) {
            return;
        }

        if (self::env('NEXTCLOUD_INTEGRATION_REQUIRED') === '1') {
            self::fail(self::$nextcloudUnavailable);
        }

        self::markTestSkipped(self::$nextcloudUnavailable);
    }

    protected static function nextcloudIsAvailable(): bool
    {
        self::checkNextcloud();

        return self::$nextcloudUnavailable === null;
    }

    /**
     * @return array{baseUri: string, userName: string, password: string}
     */
    protected static function settings(): array
    {
        return [
            'baseUri' => self::env('NEXTCLOUD_BASE_URI'),
            'userName' => self::env('NEXTCLOUD_USERNAME'),
            'password' => self::env('NEXTCLOUD_PASSWORD'),
        ];
    }

    /**
     * "https://cloud.example.com" for "https://cloud.example.com/remote.php/dav/files/admin/".
     */
    protected static function serverUrl(): string
    {
        $baseUri = self::env('NEXTCLOUD_BASE_URI');
        $position = strpos($baseUri, '/remote.php/');

        return $position === false ? rtrim($baseUri, '/') : substr($baseUri, 0, $position);
    }

    protected static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}
