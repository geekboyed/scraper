#!/bin/bash
# Fix file permissions so both user1 (cron) and www-data (web server) can read/write
# Run with: sudo ./fix_permissions.sh

set -e

PROJECT_DIR="/var/www/html/scraper"
LOG_DIR="/var/log/scraper"

echo "Fixing permissions for scraper project..."

# --- Log directories ---
# Both user1 (cron) and www-data (web server) need write access
chown -R user1:www-data "$LOG_DIR"
chmod 775 "$LOG_DIR"
chmod g+s "$LOG_DIR"          # setgid: new files inherit www-data group
find "$LOG_DIR" -type f -exec chmod 664 {} \;

chown -R user1:www-data "$PROJECT_DIR/logs"
chmod 775 "$PROJECT_DIR/logs"
chmod g+s "$PROJECT_DIR/logs"
find "$PROJECT_DIR/logs" -type f -exec chmod 664 {} \;

# --- Project files ---
chown -R user1:www-data "$PROJECT_DIR"

# Directories: rwxrwxr-x
find "$PROJECT_DIR" -type d -exec chmod 775 {} \;

# Shell scripts: rwxrwxr-x (executable)
find "$PROJECT_DIR" -name "*.sh" -exec chmod 775 {} \;

# Python and PHP files: rw-rw-r--
find "$PROJECT_DIR" -name "*.py" -exec chmod 664 {} \;
find "$PROJECT_DIR" -name "*.php" -exec chmod 664 {} \;

# Config/data files: rw-rw-r--
find "$PROJECT_DIR" -name "*.env" -o -name ".env" | xargs -r chmod 660

echo "Done."
echo ""
echo "Ownership: user1:www-data"
echo "Dirs:      775 + setgid on log dirs"
echo "Scripts:   775"
echo "Files:     664"
