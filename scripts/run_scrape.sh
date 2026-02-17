#!/bin/bash
# Fast scraper - Gets articles quickly without AI processing

cd "$(dirname "$0")"

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

python3 scraper_curl.py

deactivate
