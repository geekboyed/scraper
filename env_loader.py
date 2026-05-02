#!/usr/bin/env python3
"""
Environment Loader - Auto-loads .env
Import this module instead of dotenv to automatically load env vars
"""

import os
from dotenv import load_dotenv

def load_all_env():
    """Load local .env (contains both DB and AI config)"""
    load_dotenv(override=True)

# Auto-load when module is imported
load_all_env()
