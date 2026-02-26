#!/bin/bash
# Parallel summarizer with Gemini + DeepSeek fallback

cd "$(dirname "$0")"

# Lock file to prevent multiple instances
LOCKFILE="/tmp/summarizer.lock"

if [ -f "$LOCKFILE" ]; then
    PID=$(cat "$LOCKFILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "Summarizer already running (PID: $PID). Exiting."
        exit 0
    else
        echo "Removing stale lock file"
        rm -f "$LOCKFILE"
    fi
fi

echo $$ > "$LOCKFILE"

cleanup() {
    rm -f "$LOCKFILE"
}
trap cleanup EXIT

# Run parallel summarizer
# Args: batch_size (default 76), max_workers (default 5)
python3 summarizer_parallel.py ${1:-76} ${2:-5}
