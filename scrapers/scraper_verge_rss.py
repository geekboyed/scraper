#!/usr/bin/env python3
"""
The Verge RSS Scraper
Scrapes articles from The Verge RSS feed
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI
import requests
from xml.etree import ElementTree as ET
from datetime import datetime
from zoneinfo import ZoneInfo
import mysql.connector
from mysql.connector import Error
import html
import re
from email.utils import parsedate_to_datetime

class VergeRSSScraper:
    def __init__(self):
        self.rss_url = "https://www.theverge.com/rss/index.xml"
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'application/rss+xml, application/xml, text/xml, */*',
            'Accept-Language': 'en-US,en;q=0.9',
            'Referer': 'https://www.theverge.com/'
        }

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        self.connection = None
        self.source_id = 12  # The Verge source ID

    def connect_db(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            if self.connection.is_connected():
                cursor = self.connection.cursor()
                cursor.execute("SET time_zone = '-08:00'")
                cursor.close()
                print("✓ Connected to MySQL database")
                return True
        except Error as e:
            print(f"✗ Error connecting to MySQL: {e}")
            return False

    def is_source_enabled(self):
        """Check if this source is active"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("SELECT isActive FROM sources WHERE id = %s", (self.source_id,))
            result = cursor.fetchone()
            cursor.close()
            if result is None:
                print(f"⚠ Source ID {self.source_id} not found in database")
                return False
            return result[0] == 'Y'
        except Error as e:
            print(f"✗ Error checking source status: {e}")
            return False

    def fetch_rss(self):
        """Fetch and parse The Verge Atom feed"""
        try:
            response = requests.get(self.rss_url, headers=self.headers, timeout=15)
            response.raise_for_status()

            root = ET.fromstring(response.content)

            # Atom namespace
            ns = {'atom': 'http://www.w3.org/2005/Atom'}

            articles = []
            entries = root.findall('.//atom:entry', ns)

            for entry in entries[:20]:  # Limit to 20 articles
                title_elem = entry.find('atom:title', ns)
                link_elem = entry.find("atom:link[@rel='alternate']", ns)
                published_elem = entry.find('atom:published', ns)
                updated_elem = entry.find('atom:updated', ns)

                if title_elem is not None and link_elem is not None:
                    title = html.unescape(title_elem.text or '')
                    url = link_elem.get('href', '')

                    # Clean title (remove CDATA and HTML tags)
                    title = re.sub(r'<!\[CDATA\[(.*?)\]\]>', r'\1', title)
                    title = re.sub(r'<[^>]+>', '', title)
                    title = title.strip()

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

            return articles

        except Exception as e:
            print(f"  ⚠ Error fetching RSS: {e}")
            return []

    def save_article(self, article):
        """Save article to database"""
        try:
            cursor = self.connection.cursor()

            # Check if exists (by URL or same title within 24 hours)
            cursor.execute("""
                SELECT id FROM articles
                WHERE url = %s
                OR (source_id = %s AND title = %s AND ABS(DATEDIFF(published_date, %s)) <= 1)
            """, (
                article['url'],
                self.source_id,
                article['title'],
                article.get('published_date', datetime.now().date())
            ))

            # Consume the result to avoid "Unread result found" error
            existing = cursor.fetchone()
            if existing:
                cursor.close()
                return 'skipped'

            # Insert new article
            cursor.execute("""
                INSERT INTO articles (source_id, title, url, published_date)
                VALUES (%s, %s, %s, %s)
            """, (self.source_id, article['title'], article['url'], article['date']))

            self.connection.commit()
            cursor.close()
            return True

        except Error as e:
            print(f"  ✗ Error saving article: {e}")
            return False

    def run(self):
        """Main scraping workflow"""
        print("=" * 60)
        print("The Verge RSS Scraper")
        print("=" * 60)

        if not self.connect_db():
            return

        if not self.is_source_enabled():
            print(f"⊘ The Verge source (ID {self.source_id}) is disabled. Skipping.")
            return

        print(f"\n🔍 Fetching from RSS feed...")
        articles = self.fetch_rss()

        if not articles:
            print("No articles found")
            return

        print(f"✓ Found {len(articles)} articles")

        print(f"\n📝 Processing articles...")
        new_count = 0
        skipped_count = 0

        for i, article in enumerate(articles, 1):
            print(f"\n[{i}/{len(articles)}] {article['title'][:70]}...")

            result = self.save_article(article)

            if result == 'skipped':
                print("  ℹ Already exists")
                skipped_count += 1
            elif result:
                print("  ✓ Saved to database")
                new_count += 1

        print("\n" + "=" * 60)
        print(f"✓ Added {new_count} new articles")
        print(f"  Skipped {skipped_count} existing articles")
        print("=" * 60)

        # Update source statistics
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE sources
                SET articles_count = (SELECT COUNT(*) FROM articles WHERE source_id = %s),
                    last_scraped = NOW()
                WHERE id = %s
            """, (self.source_id, self.source_id))
            self.connection.commit()
            cursor.close()
        except Error as e:
            print(f"⚠ Warning: Could not update source statistics: {e}")

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    scraper = VergeRSSScraper()
    scraper.run()
