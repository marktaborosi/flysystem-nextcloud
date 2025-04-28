#!/bin/bash
set -e

# Change working directory to Nextcloud web root
cd /var/www/html

# Copy Nextcloud source files if they do not already exist
if [ ! -f index.php ]; then
    echo "No Nextcloud installation detected, copying application files..."
    cp -R /usr/src/nextcloud/* /var/www/html/
fi

# Ensure proper file ownership for the web server (www-data)
chown -R www-data:www-data /var/www/html

# Wait until the database service is reachable
until mysqladmin ping -h "$MYSQL_HOST" --silent; do
    echo "Waiting for the database service to become available..."
    sleep 2
done

# Proceed with installation only if Nextcloud is not already configured
if [ ! -f config/config.php ]; then
    echo "No existing Nextcloud configuration found. Starting installation..."

    # Run the installation as the web server user (www-data) to ensure correct permissions
    su -s /bin/bash www-data -c "php occ maintenance:install \
      --admin-user \"$NEXTCLOUD_ADMIN_USER\" \
      --admin-pass \"$NEXTCLOUD_ADMIN_PASSWORD\" \
      --database \"mysql\" \
      --database-name \"$MYSQL_DATABASE\" \
      --database-user \"$MYSQL_USER\" \
      --database-pass \"$MYSQL_PASSWORD\" \
      --database-host \"$MYSQL_HOST\""
else
    echo "Nextcloud is already configured. Skipping installation."
fi

# Launch the Apache HTTP server in the foreground
exec apache2-foreground
