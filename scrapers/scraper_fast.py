#!/usr/bin/env python3
"""
Business Insider Fast Scraper
Quickly scrapes articles without AI processing
"""

import os
import requests
from bs4 import BeautifulSoup
from datetime import datetime
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv
import re

# Load environment variables
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env"))
load_dotenv(os.path.expanduser('~/.env_AI'))  # Load AI keys from home directory

class FastScraper:
    def __init__(self, source_id=None, source_url=None):
        self.source_id = source_id
        self.base_url = source_url or "https://www.businessinsider.com"
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept-Encoding': 'gzip, deflate',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Sec-Fetch-User': '?1',
            'Cache-Control': 'max-age=0'
        }

        # Database configuration
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        # Navigation/non-article keywords to filter out
        self.skip_keywords = [
            'get the app', 'newsletters', 'subscribe', 'sign up',
            'still standing', 'explainers', 'so expensive', 'big business',
            'military & defense', 'entertainment', 'innovation', 'transportation',
            'enterprise', 'personal finance', 'the better work project',
            'small business', 'real estate', 'advertising', 'business insider',
            'about us', 'contact', 'careers', 'masthead', 'homepage',
            'all categories', 'view all', 'see more', 'browse'
        ]

        self.connection = None

    def connect_db(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            if self.connection.is_connected():
                print("✓ Connected to MySQL database")
                return True
        except Error as e:
            print(f"✗ Error connecting to MySQL: {e}")
            return False

    def extract_article_date(self, url, href):
        """Extract publication date from article page"""
        try:
            # First try URL pattern
            date_match = re.search(r'-(\d{4})-(\d{1,2})$', href)
            if date_match:
                year, month = date_match.groups()

                # Fetch the actual article to get the real date
                try:
                    response = requests.get(url, headers=self.headers, timeout=10)
                    soup = BeautifulSoup(response.content, 'html.parser')

                    # Look for date in meta tags
                    date_meta = soup.find('meta', {'property': 'article:published_time'}) or \
                               soup.find('meta', {'name': 'publish-date'}) or \
                               soup.find('meta', {'property': 'og:published_time'}) or \
                               soup.find('time', {'datetime': True})

                    if date_meta:
                        if date_meta.get('content'):
                            date_str = date_meta.get('content')
                        elif date_meta.get('datetime'):
                            date_str = date_meta.get('datetime')
                        else:
                            date_str = None

                        if date_str:
                            # Parse ISO date format
                            from datetime import datetime as dt
                            parsed_date = dt.fromisoformat(date_str.replace('Z', '+00:00'))
                            return parsed_date.strftime('%Y-%m-%d')
                except:
                    pass

                # Fallback to current day with URL year/month
                return f"{year}-{int(month):02d}-{datetime.now().day:02d}"

            return datetime.now().date()
        except:
            return datetime.now().date()

    def is_article_url(self, url, href):
        """Check if URL is an article based on patterns"""
        url_lower = url.lower()

        # Skip non-article links
        skip_patterns = ['/author/', '/videos/', '/category/', 'javascript:', '#', '/newsletters', '/explainers', '/press-release/']
        if any(skip in url_lower for skip in skip_patterns):
            return False

        # Accept different article URL patterns
        article_patterns = [
            r'-\d{4}-\d{1,2}$',  # Business Insider: /slug-2026-2
            r'/story/',           # MarketWatch: /story/slug
            r'/articles?/',       # Generic: /article/slug
            r'/\d{4}/\d{2}/',    # Date-based: /2026/02/slug
        ]

        return any(re.search(pattern, href) for pattern in article_patterns)

    def scrape_homepage(self):
        """Scrape articles from homepage - supports multiple site structures"""
        try:
            print(f"\n🔍 Scraping {self.base_url}...")
            response = requests.get(self.base_url, headers=self.headers, timeout=15)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')
            articles = []

            # Find article links from headings and direct links
            headings = soup.find_all(['h2', 'h3', 'h4'])
            all_links = soup.find_all('a', href=True)

            print(f"  DEBUG: Found {len(headings)} headings, {len(all_links)} links")

            seen_urls = set()

            # Method 1: Check headings first (most reliable)
            checked = 0
            for heading in headings:
                link = heading.find('a', href=True)
                if not link:
                    continue

                href = link.get('href', '')
                title = heading.get_text(strip=True)
                checked += 1

                # Skip if no title or too short
                if not title or len(title) < 30:
                    if checked <= 2:
                        print(f"  DEBUG: Title too short: '{title}'")
                    continue

                # Skip navigation keywords
                title_lower = title.lower()
                if any(keyword in title_lower for keyword in self.skip_keywords):
                    if checked <= 2:
                        print(f"  DEBUG: Skip keyword in: '{title[:40]}'")
                    continue

                # Make absolute URL
                if href.startswith('http'):
                    url = href
                elif href.startswith('/'):
                    # Parse base URL to get scheme and domain
                    from urllib.parse import urlparse
                    parsed = urlparse(self.base_url)
                    url = f"{parsed.scheme}://{parsed.netloc}{href}"
                else:
                    continue

                # Skip if duplicate or not an article
                if url in seen_urls:
                    continue

                if not self.is_article_url(url, href):
                    # Debug: print first few rejections
                    if len(seen_urls) < 3:
                        print(f"  DEBUG: Rejected '{title[:40]}' - URL: {href[:60]}")
                    continue

                seen_urls.add(url)

                # Extract publication date
                published_date = self.extract_article_date(url, href)

                articles.append({
                    'title': title[:500],
                    'url': url[:500],
                    'date': published_date
                })

                if len(articles) >= 50:
                    break

            # Method 2: If not enough articles, check direct links with substantial text
            if len(articles) < 10:
                print("  → Trying direct links method...")
                for link in all_links:
                    if len(articles) >= 50:
                        break

                    href = link.get('href', '')
                    title = link.get_text(strip=True)

                    # Must have good title
                    if not title or len(title) < 30 or len(title) > 200:
                        continue

                    # Skip navigation keywords
                    title_lower = title.lower()
                    if any(keyword in title_lower for keyword in self.skip_keywords):
                        continue

                    # Make absolute URL
                    if href.startswith('/'):
                        url = self.base_url.rstrip('/') + href
                    elif not href.startswith('http'):
                        continue
                    else:
                        url = href

                    # Skip if duplicate or not an article
                    if url in seen_urls or not self.is_article_url(url, href):
                        continue

                    seen_urls.add(url)

                    # Extract publication date
                    published_date = self.extract_article_date(url, href)

                    articles.append({
                        'title': title[:500],
                        'url': url[:500],
                        'date': published_date
                    })

            print(f"✓ Found {len(articles)} articles")
            return articles

        except Exception as e:
            print(f"✗ Error scraping homepage: {e}")
            return []

    def save_article(self, article_data):
        """Save article to database (without summary)"""
        try:
            cursor = self.connection.cursor()

            # Check if article already exists (by URL or same title within 24 hours)
            cursor.execute("""
                SELECT id FROM articles
                WHERE url = %s
                OR (title = %s AND ABS(DATEDIFF(published_date, %s)) <= 1)
            """, (
                article_data['url'],
                article_data['title'],
                article_data.get('date', datetime.now().date())
            ))

            existing = cursor.fetchone()
            if existing:
                cursor.close()
                return 'skipped'

            # Insert new article with source_id (summary will be NULL initially)
            insert_query = """
                INSERT INTO articles (source_id, title, url, published_date)
                VALUES (%s, %s, %s, %s)
            """

            cursor.execute(insert_query, (
                self.source_id,
                article_data['title'],
                article_data['url'],
                article_data.get('date', datetime.now().date())
            ))

            self.connection.commit()
            cursor.close()
            return 'saved'

        except Error as e:
            print(f"  ✗ Error saving article: {e}")
            return 'error'

    def get_enabled_sources(self):
        """Get all enabled sources from database"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, url FROM sources WHERE enabled = 1 ORDER BY id")
        sources = cursor.fetchall()
        cursor.close()
        return sources

    def update_source_stats(self, source_id, article_count):
        """Update source statistics"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE sources
                SET last_scraped = NOW(),
                    articles_count = (SELECT COUNT(*) FROM articles WHERE source_id = %s)
                WHERE id = %s
            """, (source_id, source_id))
            self.connection.commit()
            cursor.close()
        except:
            pass

    def run(self):
        """Main scraping workflow - processes all enabled sources"""
        print("=" * 60)
        print("Multi-Source Fast Scraper")
        print("=" * 60)

        if not self.connect_db():
            return

        # Get all enabled sources
        sources = self.get_enabled_sources()

        if not sources:
            print("No enabled sources found")
            return

        print(f"\n📡 Found {len(sources)} enabled source(s)")

        total_saved = 0
        total_skipped = 0

        for source in sources:
            print(f"\n{'=' * 60}")
            print(f"Scraping: {source['name']}")
            print(f"URL: {source['url']}")
            print(f"{'=' * 60}")

            # Update scraper for this source
            self.source_id = source['id']
            self.base_url = source['url']

            # Scrape homepage
            articles = self.scrape_homepage()

            if not articles:
                print("No articles found")
                continue

            print(f"\n💾 Saving {len(articles)} articles...")
            saved = 0
            skipped = 0

            for i, article in enumerate(articles, 1):
                result = self.save_article(article)

                if result == 'saved':
                    print(f"[{i}/{len(articles)}] ✓ {article['title'][:80]}...")
                    saved += 1
                elif result == 'skipped':
                    print(f"[{i}/{len(articles)}] ⊘ {article['title'][:80]}... (duplicate)")
                    skipped += 1

            total_saved += saved
            total_skipped += skipped

            # Update source statistics
            self.update_source_stats(source['id'], saved)

            print(f"\n✓ {source['name']}: Saved {saved} | Skipped {skipped}")

        print("\n" + "=" * 60)
        print(f"TOTAL: Saved {total_saved} | Skipped {total_skipped} | Sources: {len(sources)}")
        print("=" * 60)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    scraper = FastScraper()
    scraper.run()
