# Business Insider Web Scraper

A Python-based web scraper that automatically fetches articles from Business Insider, summarizes them using AI, categorizes them, and displays them in a beautiful PHP dashboard.

## Features

- 🔍 **Web Scraping**: Automatically scrapes articles from Business Insider homepage
- 🤖 **AI Summarization**: Uses OpenAI GPT-4o-mini to generate concise summaries
- 📊 **Auto-Categorization**: Intelligently categorizes articles into business topics
- 💾 **Database Storage**: Stores articles in MySQL with full metadata
- 🌐 **Web Dashboard**: Beautiful PHP interface to browse and filter articles

## Setup

### 1. Install Python Dependencies

```bash
pip3 install -r requirements.txt
```

### 2. Configure Environment

Make sure your `.env` file has the correct settings:
- Database credentials (DB_HOST, DB_NAME, DB_USER, DB_PASS)
- OpenAI API key (OPENAI_API_KEY)

### 3. Run the Scraper

```bash
python3 scraper.py
```

Or use the convenience script:
```bash
./run.sh
```

## Database Schema

### Tables

**articles**
- `id`: Primary key
- `title`: Article title
- `url`: Article URL (unique)
- `published_date`: Publication date
- `summary`: AI-generated summary
- `scraped_at`: Timestamp of scraping

**categories**
- `id`: Primary key
- `name`: Category name (unique)
- `description`: Category description

**article_categories**
- `article_id`: Foreign key to articles
- `category_id`: Foreign key to categories
- `confidence`: Categorization confidence score

## Business Categories

- Finance
- Technology
- Retail
- Real Estate
- Healthcare
- Energy
- Automotive
- Media & Entertainment
- Economy
- Markets
- Leadership
- Startups
- Global Business

## Web Dashboard

Access the dashboard at: `http://your-server/BIScrape/`

Features:
- View all scraped articles
- Filter by category
- Search articles by title/summary
- Click through to original articles
- Responsive design

## How It Works

1. **Scraping**: Fetches Business Insider homepage and extracts article links
2. **Content Extraction**: Downloads full article content
3. **Summarization**: Uses OpenAI API to create concise summaries
4. **Categorization**: AI categorizes each article into relevant business topics
5. **Storage**: Saves to MySQL database with relationships
6. **Display**: PHP dashboard queries and presents the data

## API Usage

The scraper uses OpenAI's GPT-4o-mini model for:
- Article summarization (2-3 sentences)
- Topic categorization (up to 3 categories per article)

## Rate Limiting

- 1-second delay between articles to be respectful to Business Insider
- Limits to 20 articles per run to manage API costs

## Scheduling

To run automatically, add to crontab:
```bash
# Run every hour
0 * * * * cd /var/www/html/BIScrape && python3 scraper.py >> scraper.log 2>&1

# Run twice daily (8 AM and 8 PM)
0 8,20 * * * cd /var/www/html/BIScrape && python3 scraper.py >> scraper.log 2>&1
```

## Notes

- The scraper respects the target website's structure
- Uses appropriate user agents and rate limiting
- Deduplicates articles based on URL
- Handles errors gracefully with fallbacks
