#!/bin/bash
set -e

echo "==> Starting EspoCRM Production Deployment Entrypoint..."

# Wait for database if configured
if [ -n "$ESPOCRM_DATABASE_HOST" ]; then
    echo "Waiting for database at $ESPOCRM_DATABASE_HOST..."
    until nc -z -v -w30 "$ESPOCRM_DATABASE_HOST" "${ESPOCRM_DATABASE_PORT:-3306}"; do
      echo "Waiting for database connection..."
      sleep 2
    done
fi

# Run rebuild cache on startup
if [ -f "/var/www/html/command.php" ]; then
    echo "Rebuilding EspoCRM metadata and cache..."
    php /var/www/html/command.php rebuild || true
fi

exec "$@"
