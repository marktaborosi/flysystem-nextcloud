# Use the official Nextcloud base image
FROM nextcloud:latest

# Install necessary utilities (MariaDB client) for database connectivity checks
RUN apt-get update && apt-get install -y mariadb-client

# Copy the custom entrypoint script into the container
COPY docker-entrypoint.sh /docker-entrypoint.sh

# Ensure the entrypoint script is executable
RUN chmod +x /docker-entrypoint.sh

# Set the custom entrypoint script as the container's startup command
ENTRYPOINT ["/docker-entrypoint.sh"]
