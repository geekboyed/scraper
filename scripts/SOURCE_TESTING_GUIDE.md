# Source Testing & Self-Healing Guide

## Overview

These tools automatically test new sources and use Claude AI to fix scraping issues.

## Tools

### 1. `test_new_source.py` - Self-Healing Scraper Test
Tests if a source can be scraped, with automatic fixing.

**Usage:**
```bash
python3 scrapers/test_new_source.py <article_url>
```

**What it does:**
1. Tries to scrape article with default extraction logic
2. If it fails, asks Claude to analyze the HTML and suggest fixes
3. Retries with Claude's suggested code
4. Repeats up to 3 times until it works

**Example:**
```bash
python3 scrapers/test_new_source.py https://techcrunch.com/2025/01/15/some-article
```

### 2. `test_source_pipeline.py` - Full Pipeline Test
Tests complete workflow: scraping → summarization → categorization

**Usage:**
```bash
python3 scrapers/test_source_pipeline.py <article_url>
```

**What it does:**
1. **Scraping**: Extracts article content (with self-healing)
2. **Summarization**: Tests AI summarization with Claude Haiku
3. **Categorization**: Auto-categorizes into business topics

**Example:**
```bash
python3 scrapers/test_source_pipeline.py https://www.businessinsider.com/article-slug
```

## Output Examples

### Successful Test
```
✅ SUCCESS!
📊 Content Statistics:
   • Length: 2640 characters
   • Words: 415
   • Paragraphs: 14
   • Newlines: 28

✅ Summarization: Success (232 words)
✅ Categorization: Success (Business & Economy, Healthcare & Biotech)
```

### Self-Healing in Action
```
❌ Attempt 1 failed: No content extracted

🤖 Asking Claude to fix the issue...
📝 Claude suggested new extraction code:
----------------------------------------------------------------------
# Find article body
article = soup.find('div', class_='post-content')
if article:
    paragraphs = article.find_all('p')
    content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs])
----------------------------------------------------------------------

✅ SUCCESS!
```

## When to Use

### Before Adding a New Source:
1. Find a sample article URL from the source
2. Run `test_source_pipeline.py` on it
3. If successful, add the source to your database
4. If Claude provides custom extraction code, integrate it into your scraper

### Troubleshooting Existing Sources:
If a source stops working:
1. Get a recent article URL from that source
2. Run `test_new_source.py` to get updated extraction code
3. Update your scraper with the new code

## How Self-Healing Works

The self-healing process:

1. **Attempt scraping** with default logic
2. **On failure**: Captures error + HTML sample
3. **Ask Claude**: Sends error and HTML to Claude Sonnet 4.5
4. **Get fix**: Claude analyzes the page structure and provides extraction code
5. **Apply fix**: Executes Claude's code
6. **Retry**: Tests with new code
7. **Repeat**: Up to 3 attempts total

## API Requirements

- **ANTHROPIC_API_KEY** in `.env` file
- Model used: `claude-sonnet-4-5-20250929` (for code fixes)
- Model used: `claude-3-5-haiku-20241022` (for summarization/categorization)

## Limitations

### Won't work for:
- Sites requiring JavaScript rendering (need Playwright)
- Sites with aggressive bot protection (Cloudflare, etc.)
- Paywalled content (need authentication)

### Success rate:
- **~80%** for standard news sites
- **~50%** for complex/protected sites
- **Higher** with each retry (Claude learns from errors)

## Integration Example

After testing a new source:

```python
# If Claude provided custom extraction code:
if source_id == 'new_source':
    article = soup.find('div', class_='article-body')  # From Claude
    paragraphs = article.find_all('p')
    content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs])
else:
    # Default extraction
    content = extract_default(soup)
```

## Cost Estimates

Per test run:
- Scraping only: **Free**
- With self-healing (1 fix): **~$0.02** (Claude API)
- Full pipeline: **~$0.03-$0.05** (scraping + summarization + categorization)

## Tips

1. **Test with recent articles** - Old URLs may be 404s
2. **Check console output** - Shows exactly what Claude suggests
3. **Save custom extraction code** - Integrate into main scraper
4. **Test multiple articles** - Some pages have different layouts
5. **Use full pipeline** - Verifies complete workflow works

## Next Steps

After successful test:
1. ✅ Add source to database
2. ✅ Integrate custom extraction code (if provided)
3. ✅ Run scraper to collect articles
4. ✅ Run summarizer to generate summaries
5. ✅ Verify in web interface

---

**Created**: 2026-02-12
**Updated**: 2026-02-12
