#!/bin/bash
# Railway start script for Crater
# Ensures PORT is properly handled as integer

# Set PORT from environment or default to 8000
PORT=${PORT:-8000}

# Ensure PORT is treated as integer
PORT=$((PORT))

# Debug: Log the port being used
echo "Starting Laravel server on port: $PORT"

# Start Laravel server
exec php artisan serve --host=0.0.0.0 --port=$PORT

