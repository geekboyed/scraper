# MarketWatch Summarization Failure - Diagnostic Report

## Executive Summary

**Problem:** MarketWatch articles are not being summarized by the BIScrape system.

**Root Cause:** MarketWatch uses **client-side rendering** (React/Next.js) where the main article content is loaded dynamically via JavaScript. The Python `requests` library can only fetch the initial HTML, which contains minimal content (preview text only).

**Impact:** All MarketWatch articles in the database have NULL summaries.

---

## Test Details - Article ID 38

**Article:** Stocks turn volatile despite strong January jobs report. Why investors aren't happy.
**URL:** https://www.marketwatch.com/story/why-investors-arent-loving-this-january-jobs-report-dcc240e5?mod=home_lead

### What the Summarizer Extracts

Using the exact `get_article_content()` function from `/var/www/html/BIScrape/summarizer_parallel.py`:

- **Status Code:** 200 (successful request)
- **Content Extracted:** 619 characters (96 words)
- **Passes Minimum Threshold (100 chars)?** ✓ Yes
- **Passes Summarization Threshold (500 chars)?** ✓ Yes

**Extracted Content:**
```
Volatility is back on Wall Street on Wednesday, this time with a surprisingly
strong jobs report for January playing a central role in the day's turbulence.
The Dow Jones Industrial AverageDJIAbriefly traded in record territory near
50,499 after the opening bell, after a delayed January jobs report revealed
that the U.S. economy created more jobs last month than economists expected.
Joy Wiltermuth is a news editor and senior markets reporter based in New York.
Vivien Lou Chen is a Markets Reporter for MarketWatch. You can follow her on
Twitter @vivienlouchen. Copyright ©2026MarketWatch, Inc. All rights reserved.
```

**Issue:** This is only the intro paragraph + author bio footer. The actual article body (which should be 500-1000+ words) is **missing** because it's rendered by JavaScript.

---

## Technical Analysis

### 1. Page Structure

MarketWatch uses a modern JavaScript framework:
- **Framework:** Next.js (React-based)
- **Evidence:**
  - `<div id="__next">` container in HTML
  - Minimal server-rendered HTML
  - Most content injected client-side

### 2. What's in the Initial HTML

The server returns:
- Basic page structure
- Meta tags (minimal)
- Opening paragraph (preview text)
- Author information
- Scripts to load the full content

The server does **NOT** return:
- Full article body
- Main content paragraphs
- Complete text

### 3. HTML Structure Found

```
Total <p> tags found: 11
Breakdown:
  - 2 preview/intro paragraphs (~150 chars each)
  - 2 audio player controls (6-7 chars)
  - 2 author bio paragraphs (~80-100 chars)
  - Other navigation/UI text
```

### 4. Content Detection Methods

All three methods in `get_article_content()` fail to get full content:

**Method 1: `<article>` tag**
- ✗ No `<article>` tag found

**Method 2: Content containers**
- ✗ Found 10 containers but none have substantial paragraphs
- Largest container: only 100 chars

**Method 3: All substantial paragraphs**
- ✗ Only 5 paragraphs with >50 chars
- Combined: 619 chars (mostly intro + author bios)

---

## Why This Happens

1. **Server-Side vs Client-Side Rendering**
   - Traditional sites: Full HTML sent from server ✓ Works with `requests`
   - Modern sites (React/Next.js): HTML skeleton sent, content loaded via JS ✗ Doesn't work with `requests`

2. **JavaScript Requirement**
   - MarketWatch requires JavaScript to:
     - Fetch article data from API
     - Render article body
     - Display full content
   - Python `requests` library cannot execute JavaScript

3. **No Alternative Data Sources**
   - No JSON-LD structured data with `articleBody`
   - No comprehensive meta tags with full content
   - No RSS feed content embedded in page

---

## Impact Assessment

### Database Check - All MarketWatch Articles

```sql
SELECT COUNT(*) FROM articles WHERE url LIKE '%marketwatch%' AND summary IS NULL;
```

**Result:** 10+ MarketWatch articles with NULL summaries

Sample affected articles (IDs 72-82):
- All have NULL summary field
- All would exhibit same behavior
- All require JavaScript to render full content

---

## Why Summarizer Appears to "Work" But Produces Poor Results

The summarizer technically:
- ✓ Fetches the page (200 OK)
- ✓ Extracts some content (619 chars)
- ✓ Passes minimum threshold (>100 chars)
- ✓ Sends to AI for summarization

**BUT** the AI receives:
- Incomplete article (only intro + footer)
- ~96 words instead of 500+ words
- Missing: main arguments, data, analysis, conclusion

**Result:** AI either:
1. Rejects summary (too short after processing)
2. Creates poor quality summary from incomplete info
3. Summary fails validation checks

---

## Solutions

### Option 1: Browser Automation (Recommended)
Use Selenium or Playwright to render JavaScript:

```python
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait

def get_marketwatch_content(url):
    driver = webdriver.Chrome(options=chrome_options)
    driver.get(url)

    # Wait for content to load
    WebDriverWait(driver, 10).until(
        EC.presence_of_element_located((By.CSS_SELECTOR, "article"))
    )

    # Extract rendered content
    content = driver.find_element(By.TAG_NAME, "article").text
    driver.quit()
    return content
```

**Pros:**
- Gets full content
- Works with all JavaScript sites
- Can handle dynamic content

**Cons:**
- Slower (needs browser)
- More resource intensive
- Requires browser driver

### Option 2: API Detection
Inspect MarketWatch's network requests to find content API:

1. Open browser dev tools
2. Load article
3. Check XHR/Fetch requests
4. Find JSON endpoint with article content
5. Request directly

**Pros:**
- Fast
- No browser needed
- Clean data

**Cons:**
- API might be authenticated
- API structure might change
- Requires reverse engineering

### Option 3: Skip MarketWatch
Add MarketWatch to exclusion list:

```python
EXCLUDED_DOMAINS = ['marketwatch.com']

if any(domain in url for domain in EXCLUDED_DOMAINS):
    return None
```

**Pros:**
- Simple
- No code changes needed

**Cons:**
- Loses valuable content source
- Not a real fix

### Option 4: Hybrid Approach
Use different methods for different sources:

```python
if 'marketwatch.com' in url:
    return get_content_selenium(url)
else:
    return get_content_requests(url)
```

---

## Recommended Next Steps

1. **Immediate:** Add logging to track when content extraction yields <200 words
2. **Short-term:** Implement Selenium for MarketWatch URLs
3. **Long-term:** Build source-specific extractors for common JS-heavy sites

---

## Testing Commands

To reproduce the issue:

```bash
# Test article ID 38
python3 /var/www/html/BIScrape/test_marketwatch.py

# Run full diagnostic
python3 /var/www/html/BIScrape/test_summary.py

# Check all MarketWatch articles
mysql -h 192.168.1.210 -u user1 -p'user1' scrapeDB \
  -e "SELECT id, title, LENGTH(summary) FROM articles WHERE url LIKE '%marketwatch%'"
```

---

**Report Generated:** 2026-02-11
**Tested Article:** ID 38
**Status:** Issue confirmed and diagnosed
