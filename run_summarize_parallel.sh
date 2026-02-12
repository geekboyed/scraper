#!/bin/bash
# Parallel summarizer with Gemini + DeepSeek fallback

cd "$(dirname "$0")"

# Create virtual environment if it doesn't exist
if [ ! -d "venv" ]; then
    python3 -m venv venv
    source venv/bin/activate
    pip install -q -r requirements.txt
else
    source venv/bin/activate
fi

# Run parallel summarizer
# Args: batch_size (default 20), max_workers (default 5)
python3 summarizer_parallel.py ${1:-20} ${2:-5}

deactivate
