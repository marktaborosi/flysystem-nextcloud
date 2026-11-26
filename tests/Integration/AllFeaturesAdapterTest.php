<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud\Tests\Integration;

use League\Flysystem\FilesystemAdapter;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use Marktaborosi\FlysystemNextcloud\VisibilityMode;

/**
 * The official Flysystem adapter test suite again, with every optional feature switched on:
 * visibility backed by public link shares, SEARCH based deep listings and a tiny chunk size,
 * so even the suite's small test files go through Nextcloud's chunked upload API.
 */
final class AllFeaturesAdapterTest extends NextcloudTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new NextCloudAdapter(
            self::settings(),
            visibilityMode: VisibilityMode::PublicLinks,
            chunkSize: 7,
            searchDeepListing: true,
        );
    }
}
