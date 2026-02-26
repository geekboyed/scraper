#!/bin/bash
# Wrapper to start summarizer in true background mode

cd "$(dirname "$0")"

# Set HOME for env_loader to find ~/.env_AI when run by web server
export HOME=/home/user1

# Load API keys from .env_AI
if [ -f /home/user1/.env_AI ]; then
    set -a  # Enable automatic export of variables
    source <(grep -v '^#' /home/user1/.env_AI | grep API_KEY)
    set +a  # Disable automatic export
fi

# Load LOG_DIR from .env or use default
if [ -f .env ]; then
    export $(grep -v '^#' .env | grep LOG_DIR | xargs)
fi
LOG_DIR="${LOG_DIR:-/var/log/scraper}"

LOG_FILE="$LOG_DIR/summarize_$(date +%Y%m%d%H%M%S).log"

# Run summarizer in background
python3 summarizer_parallel.py 50 5 > "$LOG_FILE" 2>&1 &

# Output the log filename for API response
basename "$LOG_FILE"
