#!/bin/bash
# Railway start script for Crater
# Ensures PORT is properly handled as integer

set -e

# Set PORT from environment or default to 8000
PORT=${PORT:-8000}

# Ensure PORT is treated as integer
PORT=$((PORT))

echo "============================================"
echo "Crater Startup Script"
echo "============================================"

# Check if AUTO_SETUP is enabled
if [ "$AUTO_SETUP" = "true" ] || [ "$AUTO_SETUP" = "1" ]; then
    echo "AUTO_SETUP enabled - checking if setup is needed..."
    
    # Force setup if requested (useful for fixing partial installations)
    if [ "$FORCE_SETUP" = "true" ] || [ "$FORCE_SETUP" = "1" ]; then
        echo "FORCE_SETUP enabled - removing marker file..."
        rm -f storage/app/database_created
    fi
    
    # Check if already set up by looking for the marker file
    if [ ! -f "storage/app/database_created" ]; then
        echo "Running automatic setup..."
        
        # Set defaults from environment variables
        ADMIN_EMAIL="${ADMIN_EMAIL:-admin@crater.app}"
        ADMIN_PASSWORD="${ADMIN_PASSWORD:-password123}"
        ADMIN_NAME="${ADMIN_NAME:-Admin}"
        COMPANY_NAME="${COMPANY_NAME:-My Company}"
        
        # Run the setup command
        php artisan crater:setup \
            --email="$ADMIN_EMAIL" \
            --password="$ADMIN_PASSWORD" \
            --name="$ADMIN_NAME" \
            --company="$COMPANY_NAME" \
            --force || echo "Setup encountered issues but continuing..."
        
        echo "Auto-setup completed!"
    else
        echo "Crater already installed, skipping auto-setup"
    fi
fi

# Always try to run pending migrations
echo "Checking for pending migrations..."
php artisan migrate --force 2>/dev/null || echo "Migration check completed"

# Clear config cache to pick up any .env changes
php artisan config:clear 2>/dev/null || true

echo "Starting Laravel server on port: $PORT"
echo "============================================"

# Start Laravel server
exec php artisan serve --host=0.0.0.0 --port=$PORT

