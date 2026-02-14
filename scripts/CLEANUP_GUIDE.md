# Cleanup Guide

## Current Directory Structure

### Main Directory (Production Files Only)

```
/var/www/html/scraper/
├── index.php                      # ✅ KEEP - Web interface
├── config.php                     # ✅ KEEP - Database config
├── summarizer_parallel.py         # ✅ KEEP - Generic AI summarizer
├── run_scrape.sh                  # ✅ KEEP - Cron wrapper for scrapers
├── run_summarize_parallel.sh      # ✅ KEEP - Cron wrapper for summarizer
├── CLAUDE.md                      # ✅ KEEP - Project instructions
├── README.md                      # ✅ KEEP - Main documentation
├── SOURCE_TESTING_GUIDE.md        # ✅ KEEP - Testing guide
│
├── scrapers/                      # ✅ KEEP - Custom scrapers directory
│   ├── README.md
│   ├── scraper_curl.py           # Primary multi-source scraper
│   ├── scraper_businessinsider.py
│   ├── scraper_marketwatch_rss.py
│   ├── scraper_verge_rss.py
│   ├── reuters_scraper_example.py
│   ├── fetch_fulltext.py
│   ├── test_new_source.py
│   └── test_source_pipeline.py
│
├── logs/                          # ✅ KEEP - Application logs
└── old/                           # ⚠️ CHECK - Legacy/archived files
```

## Files to Remove

### Old Wrapper Scripts (Replaced)
```bash
rm -f cron_scraper.sh              # Replaced by run_scrape.sh
rm -f run.sh                       # No longer used
```

### Development Documentation (Archive First)
```bash
mkdir -p old/docs
mv IMPROVEMENTS.md old/docs/       # Development notes
mv MARKETWATCH_DIAGNOSIS.md old/docs/  # Debugging notes
mv SETUP.md old/docs/              # Outdated setup guide
```

### Temporary Test Files
```bash
rm -f /tmp/check_articles.php
rm -f /tmp/check_divs.sh
rm -f /tmp/check_healthcare.php
rm -f /tmp/get_categories.php
```

## Quick Cleanup Command

Run this to clean up automatically:
```bash
bash /tmp/cleanup_commands.sh
```

Or manually:
```bash
cd /var/www/html/scraper

# Remove old wrappers
rm -f cron_scraper.sh run.sh

# Archive dev docs
mkdir -p old/docs
mv IMPROVEMENTS.md MARKETWATCH_DIAGNOSIS.md SETUP.md old/docs/

# Remove temp files
rm -f /tmp/check_*.php /tmp/check_*.sh /tmp/get_*.php
```

## Final Structure

After cleanup, you should have:

**Main directory:** Only production files (index.php, config.php, summarizer, wrappers, core docs)

**scrapers/:** All custom source-specific scrapers and testing tools

**logs/:** Application logs (kept for monitoring)

**old/:** Archived development files (can be deleted later if not needed)

---

**Last Updated:** 2026-02-12
