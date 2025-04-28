<?php

namespace Marktaborosi\FlysystemNextcloud\Tests\Integration;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToRetrieveMetadata;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;
use League\Flysystem\FilesystemAdapter;
use Throwable;

class NextCloudAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        return new NextCloudAdapter([
            'baseUri' => 'http://localhost:8080/remote.php/dav/files/admin/',
            'userName' => 'admin',
            'password' => 'admin',
        ]);

    }

    /**
     * @throws Throwable
     * @throws FilesystemException
     */
    public function test_fetching_mime_type_of_a_json_file(): void
    {
        $this->givenWeHaveAnExistingFile(
            'testfile.json',
            '{"key": "value"}'
        );

        $this->runScenario(function () {
            $attributes = $this->adapter()->mimeType('testfile.json');

            $this->assertInstanceOf(\League\Flysystem\FileAttributes::class, $attributes);
            $this->assertEquals('testfile.json', $attributes->path());
            $this->assertEquals('application/json', $attributes->mimeType());
        });
    }

    /**
     * @throws FilesystemException
     * @throws Throwable
     */
    public function test_fetching_mime_type_of_a_png_image(): void
    {
        $this->givenWeHaveAnExistingFile(
            'image.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=')
        );

        $this->runScenario(function () {
            $attributes = $this->adapter()->mimeType('image.png');

            $this->assertInstanceOf(\League\Flysystem\FileAttributes::class, $attributes);
            $this->assertEquals('image.png', $attributes->path());
            $this->assertEquals('image/png', $attributes->mimeType());
        });
    }

    /**
     * @throws Throwable
     * @throws FilesystemException
     */
    public function test_recursive_listing_returns_all_files(): void
    {
        $this->runScenario(function () {
            $this->adapter()->write('dir1/file1.txt', 'content1', new Config());
            $this->adapter()->write('dir1/dir2/file2.txt', 'content2', new Config());
            $this->adapter()->write('dir1/dir2/dir3/file3.txt', 'content3', new Config());

            $items = iterator_to_array($this->adapter()->listContents('dir1', true));

            $paths = array_map(fn($item) => $item->path(), $items);

            $this->assertContains('dir1/file1.txt', $paths);
            $this->assertContains('dir1/dir2/file2.txt', $paths);
            $this->assertContains('dir1/dir2/dir3/file3.txt', $paths);
        });
    }

    /**
     * @throws FilesystemException
     * @throws Throwable
     */
    public function test_listing_empty_directory_returns_no_items(): void
    {
        $this->runScenario(function () {
            $this->adapter()->createDirectory('emptydir', new Config());

            $items = iterator_to_array($this->adapter()->listContents('emptydir', false));

            $this->assertEmpty($items);
        });
    }

    /**
     * @throws FilesystemException
     * @throws Throwable
     */
    public function test_overwriting_existing_file(): void
    {
        $this->runScenario(function () {
            $this->adapter()->write('overwrite.txt', 'original content', new Config());
            $this->adapter()->write('overwrite.txt', 'new content', new Config());

            $contents = $this->adapter()->read('overwrite.txt');

            $this->assertEquals('new content', $contents);
        });
    }

    /**
     * @throws Throwable
     * @throws FilesystemException
     */
    public function test_moving_file_overwrites_existing_destination(): void
    {
        $this->runScenario(function () {
            $this->adapter()->write('source.txt', 'source content', new Config());
            $this->adapter()->write('destination.txt', 'destination content', new Config());

            $this->adapter()->move('source.txt', 'destination.txt', new Config());

            $this->assertFalse($this->adapter()->fileExists('source.txt'));
            $this->assertTrue($this->adapter()->fileExists('destination.txt'));

            $contents = $this->adapter()->read('destination.txt');
            $this->assertEquals('source content', $contents);
        });
    }

    /**
     * @throws FilesystemException
     * @throws Throwable
     */
    public function test_copying_nonexistent_file_throws_exception(): void
    {
        $this->expectException(\League\Flysystem\UnableToCopyFile::class);

        $this->runScenario(function () {
            $this->adapter()->copy('nonexistent.txt', 'copy.txt', new Config());
        });
    }
}