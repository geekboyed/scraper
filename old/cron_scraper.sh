#!/bin/bash
# Cron job wrapper for Business Insider scraper
# Runs hourly and logs output

cd /var/www/html/BIScrape

# Log file with date
LOG_FILE="/var/www/html/BIScrape/logs/scraper_$(date +\%Y\%m\%d).log"

# Create logs directory if it doesn't exist
mkdir -p logs

# Run scraper and log output
echo "=== Scraper run started at $(date) ===" >> "$LOG_FILE"
python3 scraper.py >> "$LOG_FILE" 2>&1
echo "=== Scraper run completed at $(date) ===" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
