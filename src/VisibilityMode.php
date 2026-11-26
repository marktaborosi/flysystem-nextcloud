<?php

declare(strict_types=1);

namespace Marktaborosi\FlysystemNextcloud;

/**
 * How Flysystem's public/private visibility is mapped onto Nextcloud.
 */
enum VisibilityMode: string
{
    /**
     * Visibility is remembered in memory by the adapter instance only (default, backwards compatible).
     * Nothing changes on the server.
     */
    case InMemory = 'in_memory';

    /**
     * "public" means the file has a public link share, "private" means it has none.
     * Setting a file to public creates a read-only public link, setting it to private
     * deletes every public link share of that file that the current user created.
     */
    case PublicLinks = 'public_links';
}
