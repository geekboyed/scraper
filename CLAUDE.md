# BIScrape - Multi-Source Business News Scraper

## Project Overview
A production web scraping and AI summarization system that collects business news from multiple sources, summarizes articles using AI, and displays them in a web interface with filtering and categorization.

## Tech Stack
- **Backend**: Python 3 with BeautifulSoup, Playwright, Requests
- **Database**: MySQL (remote at 192.168.1.210)
- **Frontend**: PHP with Bootstrap
- **AI/ML**: 1min.ai (GPT-4o-mini), Anthropic Claude 3.5 Haiku
- **Automation**: Cron jobs for scheduled scraping and summarization
- **Server**: Apache/LAMP stack on WSL2

## Project Structure
```
/var/www/html/BIScrape/
├── index.php                          # Main web interface
├── scraper_*.py                       # Source-specific scrapers
├── summarizer_parallel.py             # AI-powered parallel summarizer
├── run_scrape.sh                      # Scraper cron wrapper
├── run_summarize_parallel.sh          # Summarizer cron wrapper
├── logs/                              # Application logs
├── .env                               # API keys and configuration
└── CLAUDE.md                          # This file
```

## Development Guidelines

### File Management
- **Test Scripts**: Always create test/temporary scripts in `/tmp/` directory
- **SQL Scripts**: Place SQL test scripts in `/tmp/` or create a `scripts/` directory
- **Production Scripts**: Only production-ready scrapers and core scripts belong in project root
- **Cleanup**: Remove all test files after testing is complete
- **Example**:
  ```bash
  # Good - test in /tmp
  /tmp/test_api.py
  /tmp/test_scraper.py

  # Bad - test in project root
  /var/www/html/BIScrape/test_api.py  # Don't do this
  ```

### Code Practices
- Follow PEP 8 for Python code
- Use meaningful variable names and comments
- Handle errors gracefully with try/except blocks
- Log important operations and errors
- Use environment variables for sensitive data (API keys, DB credentials)
- **CRITICAL: Always validate HTML structure** - After making changes to PHP/HTML files, verify all opening tags have matching closing tags. Use automated checking for `<div>`, `<span>`, `<button>`, and other container elements. Mismatched tags cause layout issues and hidden content.

### Database Operations
- Database: `scrapeDB` on 192.168.1.210
- Tables: `articles`, `sources`, `categories`, `article_categories`
- Always use prepared statements to prevent SQL injection
- Set timezone to PST (`SET time_zone = '-08:00'`)
- **Read-only operations allowed without authorization**: Any SELECT queries, DESCRIBE, SHOW TABLES, SHOW COLUMNS, EXPLAIN, and other schema inspection commands can be executed freely for debugging and analysis
- Test queries before running on production data (applies to INSERT, UPDATE, DELETE, ALTER)

### AI API Configuration
- **Primary**: 1min.ai (GPT-4o-mini) via MINAI_API_KEY
- **Backup**: Anthropic Claude 3.5 Haiku via ANTHROPIC_API_KEY
- Keep API keys in `.env` file, never hardcode
- Test new keys before deploying to production

### Cron Schedule
- **Scraper**: Every 10 minutes (`*/10 * * * *`)
- **Summarizer**: Every hour at :10 (`10 * * * *`)
- Logs written to `/var/www/html/scraper/logs/`

### Summary Requirements
- Target length: 200-300 words
- Include key facts, figures, and names
- Validate word count and truncate if needed
- Track failures with `isSummaryFailed` flag

## Current Features
- Multi-source scraping (Business Insider, MarketWatch, Reuters, Bloomberg)
- AI-powered article summarization
- Automatic categorization (13 business categories)
- Date filtering (1 day, 1 week, 1 month, all)
- Source and category filtering
- Parallel processing for performance
- Failed article tracking
- Responsive web interface
