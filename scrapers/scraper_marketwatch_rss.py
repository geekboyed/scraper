#!/usr/bin/env python3
"""
MarketWatch Multi-RSS Scraper
Uses multiple RSS feeds to get comprehensive coverage
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI
import requests
from xml.etree import ElementTree as ET
from datetime import datetime
import mysql.connector
from mysql.connector import Error
import html
import time

class MarketWatchMultiRSSScraper:
    def __init__(self):
        # Multiple MarketWatch RSS feeds for better coverage
        # Only using feeds that are confirmed to work
        self.rss_feeds = [
            "https://www.marketwatch.com/rss/topstories",
            "https://www.marketwatch.com/rss/realtimeheadlines",
            "https://www.marketwatch.com/rss/marketpulse",  # Biggest source (30+ articles)
            "https://www.marketwatch.com/rss/bulletins",
        ]

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        self.connection = None
        self.source_id = 2  # MarketWatch source ID

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

    def fetch_rss(self, rss_url):
        """Fetch and parse a single RSS feed"""
        try:
            response = requests.get(rss_url, timeout=15)
            response.raise_for_status()

            root = ET.fromstring(response.content)

            articles = []

            # Parse RSS items
            for item in root.findall('.//item'):
                title_elem = item.find('title')
                link_elem = item.find('link')

                if title_elem is not None and link_elem is not None:
                    title = html.unescape(title_elem.text)
                    url = link_elem.text

                    # Clean up URL (remove tracking parameters)
                    if '?' in url:
                        url = url.split('?')[0]

                    articles.append({
                        'title': title[:500],
                        'url': url[:500],
                        'date': datetime.now().date()
                    })

            return articles

        except Exception as e:
            print(f"  ⚠ Error fetching {rss_url}: {e}")
            return []

    def fetch_all_rss(self):
        """Fetch articles from all RSS feeds"""
        print(f"Fetching from {len(self.rss_feeds)} RSS feeds...")

        all_articles = []
        seen_urls = set()

        for i, rss_url in enumerate(self.rss_feeds, 1):
            feed_name = rss_url.split('/rss/')[-1]
            print(f"\n[{i}/{len(self.rss_feeds)}] {feed_name}")

            articles = self.fetch_rss(rss_url)

            # Deduplicate by URL
            new_count = 0
            for article in articles:
                if article['url'] not in seen_urls:
                    seen_urls.add(article['url'])
                    all_articles.append(article)
                    new_count += 1

            print(f"  ✓ {len(articles)} articles ({new_count} unique)")

            # Small delay to be respectful
            time.sleep(0.5)

        print(f"\n✓ Total unique articles found: {len(all_articles)}")
        return all_articles

    def save_article(self, article_data):
        """Save article to database"""
        try:
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
                article_data.get('published_date', datetime.now().date())
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
            if e.errno == 1062:  # Duplicate entry
                return 'skipped'
            print(f"  ✗ DB error: {e}")
            return 'error'

    def update_source_stats(self):
        """Update source statistics"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE sources
                SET last_scraped = NOW(),
                    articles_count = (SELECT COUNT(*) FROM articles WHERE source_id = %s)
                WHERE id = %s
            """, (self.source_id, self.source_id))
            self.connection.commit()
            cursor.close()
        except Error as e:
            print(f"  ⚠ Error updating source stats: {e}")

    def run(self):
        """Main scraping workflow"""
        print("=" * 60)
        print("MarketWatch Multi-RSS Scraper")
        print("=" * 60)

        if not self.connect_db():
            return

        if not self.is_source_enabled():
            print(f"⊘ MarketWatch source (ID {self.source_id}) is disabled. Skipping.")
            return

        # Fetch articles from all RSS feeds
        articles = self.fetch_all_rss()

        if not articles:
            print("No articles found")
            return

        # Save articles
        print(f"\n💾 Saving {len(articles)} articles...")
        saved = 0
        skipped = 0

        for i, article in enumerate(articles, 1):
            result = self.save_article(article)

            if result == 'saved':
                print(f"[{i}/{len(articles)}] ✓ {article['title'][:70]}")
                saved += 1
            elif result == 'skipped':
                skipped += 1

        # Update source statistics
        self.update_source_stats()

        print(f"\n{'=' * 60}")
        print(f"✓ MarketWatch: {saved} new, {skipped} duplicates")
        print(f"{'=' * 60}")

        if self.connection:
            self.connection.close()

if __name__ == "__main__":
    scraper = MarketWatchMultiRSSScraper()
    scraper.run()
