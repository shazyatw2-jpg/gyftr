#!/bin/bash
set -e

PORT="${PORT:-10000}"

echo "=================================================="
echo "⚡ Starting GyFTR Automation Suite on Render"
echo "🌐 Port configured: ${PORT}"
echo "=================================================="

# Update Apache listening port to Render's dynamic $PORT
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Ensure sessions directories are present and writable
mkdir -p /var/www/html/sessions/accounts
chmod -R 777 /var/www/html/sessions

# Launch Python Telegram Bot in the background (if RUN_BOT is not disabled)
if [ "${RUN_BOT:-true}" = "true" ]; then
    echo "🤖 Starting Telegram Bot Daemon in background..."
    python3 /var/www/html/tg_bot.py &
fi

# Run Apache in the foreground
echo "🚀 Launching Apache HTTP server..."
exec apache2-foreground
