<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Integration;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\FilesystemAdapter;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;

/**
 * Runs the official Flysystem adapter test suite against a real Nextcloud server.
 * Start one with `docker compose up -d --wait`.
 */
abstract class NextcloudTestCase extends FilesystemAdapterTestCase
{
    use RequiresNextcloud;

    protected function setUp(): void
    {
        $this->skipOrFailWithoutNextcloud();

        parent::setUp();
    }

    public function clearStorage(): void
    {
        // The parent retries (with sleep) on failure, which makes an unreachable server hang the run.
        if (self::nextcloudIsAvailable()) {
            parent::clearStorage();
        }
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new NextCloudAdapter(self::settings());
    }
}
