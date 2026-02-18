#!/bin/bash
# Cron job wrapper for Business Insider scraper
# Runs hourly and logs output

cd /var/www/html/BIScrape

# Load LOG_DIR from .env or use default
if [ -f /var/www/html/scraper/.env ]; then
    export $(grep -v '^#' /var/www/html/scraper/.env | grep LOG_DIR | xargs)
fi
LOG_DIR="${LOG_DIR:-/var/log/scraper}"

# Log file with date
LOG_FILE="$LOG_DIR/scraper_$(date +\%Y\%m\%d).log"

# Run scraper and log output
echo "=== Scraper run started at $(date) ===" >> "$LOG_FILE"
python3 scraper.py >> "$LOG_FILE" 2>&1
echo "=== Scraper run completed at $(date) ===" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
