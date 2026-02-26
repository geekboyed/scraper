#!/bin/bash
# Fast scraper - Gets articles quickly without AI processing

cd "$(dirname "$0")"

# Ensure user-installed Python packages are visible (e.g. when run as www-data via Apache)
export PYTHONPATH="/home/user1/.local/lib/python3.12/site-packages:${PYTHONPATH}"

# Lock file to prevent multiple instances
LOCKFILE="/tmp/scraper.lock"

# Check if another instance is running
if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "Scraper already running (PID: $PID). Exiting."
        exit 0
    else
        echo "Removing stale lock file"
        rm -f "$LOCKFILE"
    fi
fi

# Create lock file with current PID
echo $$ > "$LOCKFILE"

# Cleanup function to remove lock file on exit
cleanup() {
    rm -f "$LOCKFILE"
}
trap cleanup EXIT

echo "======================================"
echo "Fast Article Scraper"
echo "======================================"

python3 scrapers/scraper_curl.py
python3 scrapers/scraper_marketwatch_rss.py

echo "======================================"
echo "Deal Scrapers"
echo "======================================"

python3 scrapers/scraper_slickdeals.py
python3 scrapers/scraper_befrugal.py
python3 scrapers/scraper_freebieguy.py
python3 scrapers/scraper_techbargains.py

echo "======================================"
echo "Deal Image Finder"
echo "======================================"

./run_deal_images.sh
