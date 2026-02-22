#!/usr/bin/env python3
"""
Curl-based Scraper - Uses curl for better bot evasion
Some sites respond better to curl's TLS fingerprint than Python requests
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI
import subprocess
from bs4 import BeautifulSoup
from datetime import datetime
from zoneinfo import ZoneInfo
import mysql.connector
from mysql.connector import Error
import re
import json
import requests
from xml.etree import ElementTree as ET
import html as html_module
from email.utils import parsedate_to_datetime

class CurlScraper:
    def __init__(self, source_id=None, source_url=None):
        self.source_id = source_id
        self.base_url = source_url or "https://www.businessinsider.com"

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS'),
            'connect_timeout': 10,  # Connection timeout in seconds
            'autocommit': False,    # Explicit commit control
            'pool_size': 1,         # Single connection per scraper
            'pool_reset_session': True
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

    def clean_cnn_video_title(self, title, url):
        """
        Clean CNN video titles:
        - Remove 'Video' prefix
        - Remove time stamps (e.g., '3:33', '1:10')
        - Remove ' CNN' suffix
        - Remove image attributions (e.g., 'Frazer Harrison/Getty Images...')
        - Add '(video)' suffix
        """
        if 'cnn.com' not in url.lower():
            return title

        # Remove image attributions that start with bullet points or "Video"
        # Pattern: "• VideoName/Agency..." or just image credits
        cleaned = re.sub(r'^[•●]\s*Video', '', title)
        cleaned = re.sub(r'^[•●]\s*', '', cleaned)

        # Remove image attribution patterns (Name/Agency/Getty Images, etc.)
        cleaned = re.sub(r'[A-Z][a-z]+\s+[A-Z][a-z]+/[^(]+?(?=\s*\(video\)|$)', '', cleaned)

        # Check if this is a video title (starts with "Video" or has timestamp)
        is_video = cleaned.startswith('Video') or re.search(r'\d+:\d+', cleaned)

        if not is_video:
            return cleaned.strip()

        # Remove "Video" prefix
        cleaned = re.sub(r'^Video\s*', '', cleaned)

        # Remove time stamps (e.g., "3:33", "1:10:45", etc.)
        cleaned = re.sub(r'\s*\d+:\d+(?::\d+)?\s*', '', cleaned)

        # Remove " CNN" suffix
        cleaned = re.sub(r'\s+CNN\s*$', '', cleaned)

        # Clean up extra whitespace
        cleaned = ' '.join(cleaned.split())

        # Add (video) suffix if not already present
        if not cleaned.endswith('(video)'):
            cleaned = f"{cleaned} (video)"

        return cleaned

    def clean_scraped_title(self, title):
        """
        Clean raw scraped titles:
        - Remove image attribution text (Name/Agency/Getty Images...)
        - Remove bullet points
        - Remove standalone 'Video' prefixes with image credits
        """
        if not title:
            return title

        # Remove bullet points at the start
        cleaned = re.sub(r'^[•●]\s*', '', title)

        # Remove image attribution patterns:
        # "Frazer Harrison/Getty Images North America/Getty Images (video)"
        # Pattern: FirstName LastName/Organization/Organization... before (video) or end
        cleaned = re.sub(r'^(?:Video)?[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?/[^(]+?(?=\s*\(video\)|$)', '', cleaned)

        # Clean up extra whitespace
        cleaned = ' '.join(cleaned.split()).strip()

        return cleaned

    def clean_ny_athletic_title(self, title, url):
        """
        Clean NY Athletic titles:
        - Remove author names at end (e.g., "John Smith", "Jane Doe3")
        - Remove trailing numbers (comment counts)
        - Remove "NY Athletic" suffix
        """
        if 'nytimes.com/athletic' not in url.lower():
            return title

        cleaned = title

        # Remove "NY Athletic" suffix
        cleaned = re.sub(r'\s*NY\s+Athletic\s*$', '', cleaned, flags=re.IGNORECASE)

        # Remove author name pattern at end: "FirstName LastName" (may have numbers attached)
        # This pattern is more specific: author name directly attached or with small number
        cleaned = re.sub(r'([a-z])([A-Z][a-z]+\s+[A-Z][a-z]+)\s*\d{0,3}\s*$', r'\1', cleaned)

        # Clean up extra whitespace
        cleaned = ' '.join(cleaned.split())

        return cleaned

    def scrape_rss_feed(self, rss_url):
        """Scrape RSS/Atom feed (for sources like The Verge)"""
        try:
            print(f"\n🔍 Scraping RSS feed...")

            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept': 'application/rss+xml, application/xml, text/xml, */*',
                'Referer': self.base_url
            }

            response = requests.get(rss_url, headers=headers, timeout=15)
            response.raise_for_status()

            root = ET.fromstring(response.content)

            articles = []

            # Check if Atom or RSS
            if root.tag.endswith('feed'):  # Atom feed
                ns = {'atom': 'http://www.w3.org/2005/Atom'}
                entries = root.findall('.//atom:entry', ns)

                for entry in entries[:20]:
                    title_elem = entry.find('atom:title', ns)
                    link_elem = entry.find("atom:link[@rel='alternate']", ns)
                    published_elem = entry.find('atom:published', ns)
                    updated_elem = entry.find('atom:updated', ns)

                    if title_elem is not None and link_elem is not None:
                        title = html_module.unescape(title_elem.text or '')
                        url = link_elem.get('href', '')

                        # Clean title
                        title = re.sub(r'<!\[CDATA\[(.*?)\]\]>', r'\1', title)
                        title = re.sub(r'<[^>]+>', '', title).strip()

                        # Extract publish datetime from Atom feed and convert to Pacific time
                        pub_datetime = None
                        pacific_tz = ZoneInfo('America/Los_Angeles')

                        if published_elem is not None and published_elem.text:
                            try:
                                # Parse ISO 8601 datetime (Atom standard)
                                pub_datetime = datetime.fromisoformat(published_elem.text.replace('Z', '+00:00'))
                                # Convert to Pacific time
                                pub_datetime = pub_datetime.astimezone(pacific_tz)
                            except:
                                pass
                        elif updated_elem is not None and updated_elem.text:
                            try:
                                pub_datetime = datetime.fromisoformat(updated_elem.text.replace('Z', '+00:00'))
                                # Convert to Pacific time
                                pub_datetime = pub_datetime.astimezone(pacific_tz)
                            except:
                                pass

                        # Fallback to current Pacific datetime if parsing failed
                        if not pub_datetime:
                            pub_datetime = datetime.now(pacific_tz)

                        if title and url:
                            articles.append({
                                'title': title[:500],
                                'url': url[:500],
                                'date': pub_datetime
                            })

            else:  # RSS feed
                for item in root.findall('.//item'):
                    title_elem = item.find('title')
                    link_elem = item.find('link')
                    pubdate_elem = item.find('pubDate')

                    if title_elem is not None and link_elem is not None:
                        title = html_module.unescape(title_elem.text or '')
                        url = link_elem.text

                        # Extract publish datetime from RSS feed and convert to Pacific time
                        pub_datetime = None
                        pacific_tz = ZoneInfo('America/Los_Angeles')

                        if pubdate_elem is not None and pubdate_elem.text:
                            try:
                                # Parse RFC 2822 datetime (RSS standard)
                                pub_datetime = parsedate_to_datetime(pubdate_elem.text)
                                # Convert to Pacific time
                                pub_datetime = pub_datetime.astimezone(pacific_tz)
                            except:
                                pass

                        # Fallback to current Pacific datetime if parsing failed
                        if not pub_datetime:
                            pub_datetime = datetime.now(pacific_tz)

                        if title and url:
                            articles.append({
                                'title': title[:500],
                                'url': url[:500],
                                'date': pub_datetime
                            })

            print(f"✓ Found {len(articles)} articles")
            return articles

        except Exception as e:
            print(f"✗ RSS fetch error: {e}")
            return []

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
                title = link.get_text(separator=' ', strip=True)

                # Clean image attributions and junk
                title = self.clean_scraped_title(title)

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
                    'date': datetime.now(ZoneInfo('America/Los_Angeles'))
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
                    title = link.get_text(separator=' ', strip=True)

                    # Clean image attributions and junk
                    title = self.clean_scraped_title(title)

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
                        'date': datetime.now(ZoneInfo('America/Los_Angeles'))
                    })

            print(f"✓ Found {len(articles)} articles")
            return articles

        except Exception as e:
            print(f"✗ Error scraping: {e}")
            return []

    def save_article(self, article_data):
        """Save article to database"""
        try:
            # Clean CNN video titles
            article_data['title'] = self.clean_cnn_video_title(
                article_data['title'],
                article_data['url']
            )

            # Clean NY Athletic titles
            article_data['title'] = self.clean_ny_athletic_title(
                article_data['title'],
                article_data['url']
            )

            cursor = self.connection.cursor()

            # Check for duplicates by URL or title within 24 hours
            cursor.execute("""
                SELECT id FROM articles
                WHERE url = %s
                OR (title = %s AND source_id = %s AND ABS(DATEDIFF(published_date, %s)) <= 1)
            """, (
                article_data.get('url', ''),
                article_data['title'],
                self.source_id,
                article_data.get('date', datetime.now().date())
            ))

            # Fully consume all results to avoid "Unread result found" error
            existing = cursor.fetchall()  # Use fetchall() to fully consume result set
            if existing:  # If list is not empty, duplicate exists
                cursor.close()
                return 'skipped'

            cursor.execute("""
                INSERT INTO articles (source_id, title, url, published_date, scraped_at)
                VALUES (%s, %s, %s, %s, NOW())
            """, (
                self.source_id,
                article_data['title'],
                article_data['url'],
                article_data['date']
            ))

            # Auto-assign Sports category for sports sources (ESPN=17, NY Athletic=18, AP Sports=19)
            if self.source_id in [17, 18, 19]:
                article_id = cursor.lastrowid
                cursor.execute("""
                    INSERT IGNORE INTO article_categories (article_id, category_id)
                    VALUES (%s, 23)
                """, (article_id,))

            self.connection.commit()
            cursor.close()
            return 'saved'

        except Error as e:
            print(f"  ✗ DB error: {e}")
            return 'error'

    def get_enabled_sources(self):
        """Get enabled sources with rate limiting info"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, name, url, scrape_delay_minutes, last_scraped_at
            FROM sources
            WHERE isActive = 'Y'
        """)
        sources = cursor.fetchall()
        cursor.close()
        return sources

    def should_scrape_source(self, source):
        """Check if enough time has passed since last scrape"""
        if not source.get('last_scraped_at'):
            return True  # Never scraped before

        delay_minutes = source.get('scrape_delay_minutes', 10)

        cursor = self.connection.cursor()
        cursor.execute("""
            SELECT TIMESTAMPDIFF(MINUTE, last_scraped_at, NOW()) as minutes_since
            FROM sources
            WHERE id = %s
        """, (source['id'],))
        result = cursor.fetchone()
        cursor.close()

        if not result:
            return True

        minutes_since = result[0] if result[0] is not None else 999999

        if minutes_since < delay_minutes:
            wait_minutes = delay_minutes - minutes_since
            print(f"  ⏳ Rate limit: Wait {wait_minutes} more minutes (delay: {delay_minutes}m)")
            return False

        return True

    def update_source_stats(self, source_id):
        """Update source statistics and timestamp"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE sources
                SET last_scraped = NOW(),
                    last_scraped_at = NOW(),
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

            # Check rate limiting
            if not self.should_scrape_source(source):
                continue

            self.source_id = source['id']
            self.base_url = source['url']

            # Use RSS for sites that block bots
            if source['id'] == 12:  # The Verge
                articles = self.scrape_rss_feed('https://www.theverge.com/rss/index.xml')
            elif source['id'] == 13:  # VentureBeat
                articles = self.scrape_rss_feed('https://venturebeat.com/feed/')
            elif source['id'] == 48:  # sfGate (PerimeterX bot protection)
                articles = self.scrape_rss_feed('https://www.sfgate.com/bayarea/feed/bay-area-news-429.php')
            else:
                articles = self.scrape_homepage()

            if not articles:
                continue

            print(f"\n💾 Saving {len(articles)} articles...")
            saved = 0
            duplicates = 0

            for i, article in enumerate(articles, 1):
                result = self.save_article(article)

                if result == 'saved':
                    print(f"[{i}/{len(articles)}] ✓ {article['title'][:70]}")
                    saved += 1
                elif result == 'skipped':
                    duplicates += 1

            total_saved += saved

            # Update source statistics
            self.update_source_stats(source['id'])

            print(f"\n✓ {source['name']}: {saved} new articles")
            if duplicates > 0:
                print(f"⊘ Skipped {duplicates} duplicates")

        print(f"\n{'=' * 60}")
        print(f"TOTAL: {total_saved} new articles from {len(sources)} sources")
        print(f"{'=' * 60}")

        if self.connection:
            self.connection.close()

        # Run Slickdeals scraper
        try:
            print(f"\n{'=' * 60}")
            print("Running Slickdeals Scraper")
            print(f"{'=' * 60}")

            from scraper_slickdeals import SlickdealsScraper
            deals_scraper = SlickdealsScraper()
            deals_saved = deals_scraper.run()

            if deals_saved > 0:
                print(f"\n✓ Saved {deals_saved} new deals")

        except ImportError:
            print("⚠ Slickdeals scraper not available")
        except Exception as e:
            print(f"⚠ Deals scraper error: {e}")

if __name__ == "__main__":
    scraper = CurlScraper()
    scraper.run()
