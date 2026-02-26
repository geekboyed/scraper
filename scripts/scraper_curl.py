#!/usr/bin/env python3
"""
Curl-based Scraper - Uses curl for better bot evasion
Some sites respond better to curl's TLS fingerprint than Python requests
"""

import os
import subprocess
from bs4 import BeautifulSoup
from datetime import datetime
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv
import re
import json

load_dotenv()

class CurlScraper:
    def __init__(self, source_id=None, source_url=None):
        self.source_id = source_id
        self.base_url = source_url or "https://www.businessinsider.com"

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        self.connection = None
        self.skip_keywords = [
            'get the app', 'newsletters', 'subscribe', 'sign up',
            'still standing', 'explainers', 'so expensive', 'big business',
            'about us', 'contact', 'careers', 'homepage',
        ]

    def fetch_with_curl(self, url):
        """Fetch page using curl - better for some sites"""
        try:
            cmd = [
                'curl', '-s', '-L',
                '-A', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                '-H', 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                '-H', 'Accept-Language: en-US,en;q=0.9',
                '-H', 'Accept-Encoding: gzip, deflate, br',
                '-H', 'Cache-Control: max-age=0',
                '-H', 'Sec-Fetch-Dest: document',
                '-H', 'Sec-Fetch-Mode: navigate',
                '-H', 'Sec-Fetch-Site: none',
                '-H', 'Sec-Fetch-User: ?1',
                '--compressed',
                '--max-time', '15',
                url
            ]

            result = subprocess.run(cmd, capture_output=True, text=True, timeout=20)

            if result.returncode == 0:
                return result.stdout
            else:
                print(f"  Curl error: {result.stderr[:100]}")
                return None

        except Exception as e:
            print(f"  Curl exception: {e}")
            return None

    def connect_db(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            if self.connection.is_connected():
                # Set timezone to PST
                cursor = self.connection.cursor()
                cursor.execute("SET time_zone = '-08:00'")
                cursor.close()
                print("✓ Connected to MySQL database")
                return True
        except Error as e:
            print(f"✗ Error connecting to MySQL: {e}")
            return False

    def is_article_url(self, url, href):
        """Check if URL is an article"""
        url_lower = url.lower()

        skip_patterns = ['/author/', '/videos/', '/category/', 'javascript:', '#']
        if any(skip in url_lower for skip in skip_patterns):
            return False

        article_patterns = [
            r'-\d{4}-\d{1,2}$',         # Business Insider: /slug-2026-2
            r'/story/',                  # MarketWatch: /story/slug
            r'/articles?/',              # Generic: /article/slug
            r'/\d{4}/\d{2}/\d{2}/',     # CNBC, Reuters: /2026/02/11/slug.html
            r'/\d{4}/\d{2}/',           # Generic date: /2026/02/slug
            r'/business/[^/]+/$',       # Reuters: /business/slug/
            r'/markets/[^/]+/$',        # Reuters: /markets/slug/
            r'/news/[^/]+\.html',       # Yahoo Finance: /news/slug-123.html
            r'/article/[a-f0-9-]{30,}', # AP News: /article/slug-uuid
        ]

        return any(re.search(pattern, href) for pattern in article_patterns)

    def scrape_homepage(self):
        """Scrape using curl then parse"""
        try:
            print(f"\n🔍 Scraping {self.base_url} (via curl)...")

            # Fetch with curl
            html = self.fetch_with_curl(self.base_url)

            if not html:
                print("✗ Failed to fetch page")
                return []

            # Parse with BeautifulSoup
            soup = BeautifulSoup(html, 'html.parser')

            headings = soup.find_all(['h2', 'h3', 'h4'])
            all_links = soup.find_all('a', href=True)

            print(f"  Found {len(headings)} headings, {len(all_links)} links")

            articles = []
            seen_urls = set()

            # Check headings
            for heading in headings:
                link = heading.find('a', href=True)
                if not link:
                    continue

                href = link.get('href', '')
                title = heading.get_text(strip=True)

                if not title or len(title) < 30:
                    continue

                if any(kw in title.lower() for kw in self.skip_keywords):
                    continue

                # Make absolute URL
                if href.startswith('http'):
                    url = href
                elif href.startswith('/'):
                    from urllib.parse import urlparse
                    parsed = urlparse(self.base_url)
                    url = f"{parsed.scheme}://{parsed.netloc}{href}"
                else:
                    continue

                if url in seen_urls or not self.is_article_url(url, href):
                    continue

                seen_urls.add(url)

                articles.append({
                    'title': title[:500],
                    'url': url[:500],
                    'date': datetime.now().date()
                })

                if len(articles) >= 50:
                    break

            # Try direct links if needed
            if len(articles) < 10:
                print("  → Checking direct links...")
                for link in all_links:
                    if len(articles) >= 50:
                        break

                    href = link.get('href', '')
                    title = link.get_text(strip=True)

                    if not title or len(title) < 30 or len(title) > 200:
                        continue

                    if href.startswith('http'):
                        url = href
                    elif href.startswith('/'):
                        from urllib.parse import urlparse
                        parsed = urlparse(self.base_url)
                        url = f"{parsed.scheme}://{parsed.netloc}{href}"
                    else:
                        continue

                    if url in seen_urls or not self.is_article_url(url, href):
                        continue

                    seen_urls.add(url)

                    articles.append({
                        'title': title[:500],
                        'url': url[:500],
                        'date': datetime.now().date()
                    })

            print(f"✓ Found {len(articles)} articles")
            return articles

        except Exception as e:
            print(f"✗ Error scraping: {e}")
            return []

    def save_article(self, article_data):
        """Save article to database"""
        try:
            cursor = self.connection.cursor()

            cursor.execute("""
                SELECT id FROM articles WHERE url = %s
            """, (article_data['url'],))

            if cursor.fetchone():
                cursor.close()
                return 'skipped'

            cursor.execute("""
                INSERT INTO articles (source_id, title, url, published_date)
                VALUES (%s, %s, %s, %s)
            """, (
                self.source_id,
                article_data['title'],
                article_data['url'],
                article_data['date']
            ))

            self.connection.commit()
            cursor.close()
            return 'saved'

        except Error as e:
            print(f"  ✗ DB error: {e}")
            return 'error'

    def get_enabled_sources(self):
        """Get active sources"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, url FROM sources WHERE isActive = 'Y'")
        sources = cursor.fetchall()
        cursor.close()
        return sources

    def update_source_stats(self, source_id):
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
        except Error as e:
            print(f"  ⚠ Error updating source stats: {e}")

    def run(self):
        """Main scraping workflow"""
        print("=" * 60)
        print("Curl-Based Multi-Source Scraper")
        print("=" * 60)

        if not self.connect_db():
            return

        sources = self.get_enabled_sources()

        if not sources:
            print("No enabled sources")
            return

        print(f"\n📡 Processing {len(sources)} source(s)")

        total_saved = 0

        for source in sources:
            print(f"\n{'=' * 60}")
            print(f"Source: {source['name']}")
            print(f"{'=' * 60}")

            self.source_id = source['id']
            self.base_url = source['url']

            articles = self.scrape_homepage()

            if not articles:
                continue

            print(f"\n💾 Saving {len(articles)} articles...")
            saved = 0

            for i, article in enumerate(articles, 1):
                result = self.save_article(article)

                if result == 'saved':
                    print(f"[{i}/{len(articles)}] ✓ {article['title'][:70]}")
                    saved += 1
                elif result == 'skipped':
                    print(f"[{i}/{len(articles)}] ⊘ Duplicate")

            total_saved += saved

            # Update source statistics
            self.update_source_stats(source['id'])

            print(f"\n✓ {source['name']}: {saved} new articles")

        print(f"\n{'=' * 60}")
        print(f"TOTAL: {total_saved} new articles from {len(sources)} sources")
        print(f"{'=' * 60}")

        if self.connection:
            self.connection.close()

if __name__ == "__main__":
    scraper = CurlScraper()
    scraper.run()
