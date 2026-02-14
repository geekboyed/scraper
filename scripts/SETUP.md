# Business Insider Scraper - Two-Part System

## Overview

The scraper now uses a **two-part system** for better performance:

1. **Fast Scraper** (`scraper_fast.py`) - Quickly grabs articles without AI processing
2. **Background Summarizer** (`summarizer_background.py`) - Processes unsummarized articles with Gemini AI

## Why Two Scripts?

- **Scraping is fast** - Can get 50+ articles in seconds
- **AI summarization is slow** - Takes 2-3 seconds per article with API calls
- **Separation of concerns** - Scrape new content frequently, summarize in background
- **Better user experience** - Articles appear immediately, summaries fill in over time

## Setup

### 1. Add Your Gemini API Key

Edit `.env` and add your Gemini API key:
```bash
GEMINI_API_KEY=your_key_here
```

Get a free API key at: https://makersuite.google.com/app/apikey

### 2. Install Dependencies

The scripts will auto-create a virtual environment on first run, or manually:
```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

## Running Manually

### Fast Scraper (Gets New Articles)
```bash
./run_scrape.sh
# or
python3 scraper_fast.py
```

### Background Summarizer (Processes Unsummarized Articles)
```bash
./run_summarize.sh         # Process 20 articles
./run_summarize.sh 50      # Process 50 articles
# or
python3 summarizer_background.py 10
```

## Automated Schedule (Cron Jobs)

Both scripts run automatically every hour:

```
# Fast scraper runs at the top of each hour (e.g., 1:00, 2:00, 3:00)
0 * * * * /var/www/html/BIScrape/run_scrape.sh >> /var/www/html/BIScrape/logs/scrape.log 2>&1

# Summarizer runs at 30 minutes past each hour (e.g., 1:30, 2:30, 3:30)
30 * * * * /var/www/html/BIScrape/run_summarize.sh >> /var/www/html/BIScrape/logs/summarize.log 2>&1
```

## How It Works

### 1. Fast Scraper
- Scrapes Business Insider homepage
- Extracts article titles and URLs
- Saves to database with `summary = NULL`
- **No AI processing** - Very fast!
- Skips duplicates automatically

### 2. Background Summarizer
- Finds articles where `summary IS NULL`
- Downloads full article content
- Uses Gemini AI to:
  - Generate 2-3 sentence summary
  - Categorize into business topics
- Updates database with summary and categories
- Processes in batches with rate limiting

### 3. PHP Dashboard
- Shows all articles immediately (even without summaries)
- Displays "⏳ Summary pending..." for unsummarized articles
- Shows "📖 Read Summary" button once summary is generated
- Click button to expand/collapse summary

## Database Schema

```sql
articles
├── id (auto increment)
├── title (article title)
├── url (unique - prevents duplicates)
├── published_date
├── summary (NULL until summarized)
└── scraped_at (timestamp)

categories
├── id
├── name (Finance, Technology, etc.)
└── description

article_categories
├── article_id
└── category_id
```

## Logs

Check logs to monitor activity:
```bash
# Scraper logs
tail -f logs/scrape.log

# Summarizer logs
tail -f logs/summarize.log
```

## Workflow Example

**1:00 PM** - Fast scraper runs
- Finds 25 new articles
- Saves them to database (no summaries yet)
- Articles appear on dashboard with "Summary pending..."

**1:30 PM** - Background summarizer runs
- Processes 20 unsummarized articles
- Generates summaries with Gemini
- Updates database
- "Read Summary" buttons appear on dashboard

**2:00 PM** - Fast scraper runs again
- Finds 10 new articles
- Saves them (summary = NULL)

**2:30 PM** - Background summarizer runs
- Processes remaining 5 articles from 1 PM
- Processes 10 new articles from 2 PM
- All articles now have summaries!

## Tips

- Gemini API has free tier: 60 requests/minute
- Each article = 2 API calls (1 summary + 1 categorization)
- At 20 articles/batch = 40 API calls ≈ safe for free tier
- Increase batch size if you have paid API access
- Summaries improve over time as more content is available

## Troubleshooting

**No summaries appearing?**
- Check if Gemini API key is set in `.env`
- Run manually: `./run_summarize.sh`
- Check logs: `tail -f logs/summarize.log`

**Articles not being scraped?**
- Run manually: `./run_scrape.sh`
- Check logs: `tail -f logs/scrape.log`
- Verify database connection in `.env`

**Cron jobs not running?**
- Check crontab: `crontab -l`
- Verify scripts are executable: `chmod +x *.sh`
- Check system cron logs: `grep CRON /var/log/syslog`
