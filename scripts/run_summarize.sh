#!/bin/bash
# Background summarizer - Processes unsummarized articles with Gemini

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
echo "Background Article Summarizer"
echo "======================================"

# Process up to 20 articles per run (or pass custom number as argument)
BATCH_SIZE=${1:-20}

python3 summarizer_background.py $BATCH_SIZE

deactivate
