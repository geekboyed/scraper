#!/usr/bin/env python3
"""
Environment Loader - Auto-loads .env and ~/.env_AI
Import this module instead of dotenv to automatically load both files
"""

import os
from dotenv import load_dotenv

def load_all_env():
    """Load both local .env and ~/.env_AI in correct order"""
    # Load local .env first (override system env)
    load_dotenv(override=True)

    # Load AI config - try multiple locations
    ai_env_paths = [
        os.path.expanduser('~/.env_AI'),  # Current user's home
        '/home/user1/.env_AI',             # Explicit user1 path
        os.path.join(os.path.dirname(__file__), '.env_AI')  # Same directory as script
    ]

    for path in ai_env_paths:
        if os.path.exists(path):
            load_dotenv(path, override=True)
            break

# Auto-load when module is imported
load_all_env()
