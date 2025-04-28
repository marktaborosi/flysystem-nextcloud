# Flysystem Nextcloud Adapter

[![Author](https://img.shields.io/badge/author-@marktaborosi-blue.svg)](https://www.linkedin.com/in/mark-taborosi/)
[![Latest Version](https://img.shields.io/github/release/marktaborosi/flysystem-nextcloud.svg?style=flat-square)](https://github.com/marktaborosi/flysystem-nextcloud/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/marktaborosi/flysystem-nextcloud.svg?style=flat-square)](https://packagist.org/packages/marktaborosi/flysystem-nextcloud)
![PHP 8.2+](https://img.shields.io/badge/php-8.2+-red.svg?style=flat-square)
[![CI](https://github.com/marktaborosi/flysystem-nextcloud/actions/workflows/ci.yml/badge.svg)](https://github.com/marktaborosi/flysystem-nextcloud/actions)

---

Flysystem adapter for [Nextcloud WebDAV](https://docs.nextcloud.com/server/latest/developer_manual/api/WebDAV/index.html) integration.

Compatible with **PHP 8.2+** and **Flysystem v3.29+**.

## Features

- 📂 Nextcloud storage adapter for [League\Flysystem](https://github.com/thephpleague/flysystem)
- 🏗️ Fully tested with Flysystem adapter utilities + additional tests

## Requirements

- PHP 8.2 or higher
- Flysystem v3.29+
- Nextcloud instance (with WebDAV access)
- Docker (for local testing)

## Installation

```bash
composer require marktaborosi/flysystem-nextcloud
```

## Usage

```php
use League\Flysystem\Filesystem;
use Marktaborosi\FlysystemNextcloud\NextCloudAdapter;

$adapter = new NextCloudAdapter([
    'baseUri' => 'http://localhost:8080/remote.php/dav/files/admin/',
    'userName' => 'admin',
    'password' => 'admin',
]);

$filesystem = new Filesystem($adapter);

$filesystem->write('example.txt', 'Hello Nextcloud!');
```

## Testing

This adapter includes a full test suite using [league/flysystem-adapter-test-utilities](https://github.com/thephpleague/flysystem-adapter-test-utilities).

### Running tests locally

1. Start the Docker containers:

```bash
docker-compose up -d
```

2. Run PHPUnit:

```bash
vendor/bin/phpunit
```

> **Important:** Ensure the Nextcloud container is running and fully initialized before executing the tests.

## Docker Setup

Provided `docker-compose.yml` includes:

- **MariaDB** database
- **Nextcloud** app

Configuration expects:

- Admin user: `admin`
- Admin password: `admin`
- DB credentials: see `docker-compose.yml` or `.env`.

> **Note:** You can override port mappings easily via the `.env` file.  
> Example `.env` variables:
> ```env
> NEXTCLOUD_HTTP_PORT=8080
> MARIADB_PORT=3306
> ```

### Accessing the Nextcloud Web Interface

Once the Docker containers are up, you can access the Nextcloud web interface locally:

```
http://localhost:[NEXTCLOUD_HTTP_PORT]
```

Default credentials:

- **Username:** `${NEXTCLOUD_ADMIN_USER}` (default: `admin`)
- **Password:** `${NEXTCLOUD_ADMIN_PASSWORD}` (default: `admin`)

You can login and interact directly with your running Nextcloud instance.

## Suggested Packages

- `sabre/dav`: Required for WebDAV communication
- `larapack/dd`: Optional, for debugging purposes

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Acknowledgements

Big thanks to:

- [The PHP League](https://thephpleague.com/) for creating and maintaining [Flysystem](https://flysystem.thephpleague.com/v3/docs/).
- [Nextcloud](https://nextcloud.com/) for providing an excellent WebDAV API.

This adapter would not be possible without these open-source projects. 🙏

---

> Made with ❤️ by [Mark Taborosi](https://github.com/marktaborosi)