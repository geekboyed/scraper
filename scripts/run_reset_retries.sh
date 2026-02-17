#!/bin/bash
# Wrapper script for daily retry count reset
# Runs at midnight via cron to reset summary retry counts for failed articles

# Change to project directory
cd /var/www/html/scraper

# Run the reset script and log output
python3 reset_retry_counts.py >> logs/reset_retries.log 2>&1

# Exit with the Python script's exit code
exit $?
