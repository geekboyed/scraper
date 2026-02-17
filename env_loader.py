#!/usr/bin/env python3
"""
Environment Loader - Auto-loads .env and ~/.env_AI
Import this module instead of dotenv to automatically load both files
"""

import os
from dotenv import load_dotenv

def load_all_env():
    """Load both local .env and ~/.env_AI in correct order"""
    # Load local .env first
    load_dotenv()
    # Load AI config from home directory (overwrites any conflicts)
    load_dotenv(os.path.expanduser('~/.env_AI'))

# Auto-load when module is imported
load_all_env()
