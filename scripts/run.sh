#!/bin/bash
# Business Insider Scraper Runner

cd "$(dirname "$0")"

echo "======================================"
echo "Business Insider Scraper"
echo "======================================"
echo ""

# Check if virtual environment exists
if [ ! -d "venv" ]; then
    echo "Creating virtual environment..."
    python3 -m venv venv
    echo "Installing dependencies..."
    source venv/bin/activate
    pip install -r requirements.txt
else
    source venv/bin/activate
fi

# Run the scraper
python3 scraper.py

deactivate
