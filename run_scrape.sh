#!/bin/bash
# Fast scraper - Gets articles quickly without AI processing

cd "$(dirname "$0")"

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

# Create virtual environment if it doesn't exist
if [ ! -d "venv" ]; then
    echo "Creating virtual environment..."
    python3 -m venv venv
    source venv/bin/activate
    pip install -q -r requirements.txt
else
    source venv/bin/activate
fi

echo "======================================"
echo "Fast Article Scraper"
echo "======================================"

python3 scrapers/scraper_curl.py

deactivate
