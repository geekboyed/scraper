#!/usr/bin/env python3
"""
Environment Loader - Auto-loads .env and ~/.env_AI
Import this module instead of dotenv to automatically load both files
"""

import os
from dotenv import load_dotenv

def load_all_env():
    """Load both local .env and ~/.env_AI in correct order"""
    debug_log = '/var/log/scraper/env_loader_debug.log'

    # Load local .env first (override system env)
    load_dotenv(override=True)

    # Load AI config - try multiple locations (explicit path first for www-data)
    ai_env_paths = [
        '/home/user1/.env_AI',             # Explicit user1 path (priority for web server)
        os.path.expanduser('~/.env_AI'),  # Current user's home
        os.path.join(os.path.dirname(__file__), '.env_AI')  # Same directory as script
    ]

    def dbg(msg):
        try:
            with open(debug_log, 'a') as f:
                f.write(msg)
        except (PermissionError, OSError):
            pass

    loaded = False
    dbg(f"[{os.getpid()}] Checking paths:\n")
    for p in ai_env_paths:
        dbg(f"  - {p} (exists: {os.path.exists(p)})\n")

    for path in ai_env_paths:
        if os.path.exists(path):
            dbg(f"[{os.getpid()}] Loading from: {path}\n")
            load_dotenv(path, override=True)
            loaded = True
            minai = os.getenv('MINAI_API_KEY')
            dbg(f"[{os.getpid()}] MINAI_API_KEY loaded: {('Yes' if minai else 'No')}\n")
            break

    if not loaded:
        dbg(f"[{os.getpid()}] No .env_AI file found at any checked path!\n")

# Auto-load when module is imported
load_all_env()
