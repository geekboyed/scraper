#!/bin/bash
# Wrapper to start summarizer in true background mode

cd "$(dirname "$0")"

# Load LOG_DIR from .env or use default
if [ -f .env ]; then
    export $(grep -v '^#' .env | grep LOG_DIR | xargs)
fi
LOG_DIR="${LOG_DIR:-/var/log/scraper}"

LOG_FILE="$LOG_DIR/summarize_$(date +%Y%m%d%H%M%S).log"

# Use venv python directly in background
venv/bin/python3 summarizer_parallel.py 20 5 > "$LOG_FILE" 2>&1 &

# Output the log filename for API response
basename "$LOG_FILE"
