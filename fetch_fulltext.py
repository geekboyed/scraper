#!/usr/bin/env python3
"""
Fetch and save fullText for recent articles
"""

import os
import sys
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv
import requests
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

# Load environment variables
load_dotenv()

class FullTextFetcher:
    def __init__(self):
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9',
        }

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

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

    def get_recent_articles(self, hours=1):
        """Get articles from last N hours"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, title, url
            FROM articles
            WHERE scraped_at >= DATE_SUB(NOW(), INTERVAL %s HOUR)
            ORDER BY scraped_at DESC
        """, (hours,))
        articles = cursor.fetchall()
        cursor.close()
        return articles

    def get_article_content(self, url):
        """Fetch comprehensive article content"""
        try:
            # First try with requests
            response = requests.get(url, headers=self.headers, timeout=20)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Remove unwanted elements
            for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside', 'iframe']):
                element.decompose()

            content = ""

            # Method 1: Look for article tag
            article_body = soup.find('article')
            if article_body:
                paragraphs = article_body.find_all('p')
                content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs if len(p.get_text(strip=True)) > 50])

            # Method 2: Look for main content containers
            if not content or len(content) < 500:
                containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                    term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                ))
                for container in containers[:8]:
                    paragraphs = container.find_all('p')
                    if paragraphs:
                        temp_content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs if len(p.get_text(strip=True)) > 50])
                        if len(temp_content) > len(content):
                            content = temp_content
                        if len(content) > 500:
                            break

            # Method 3: Get all substantial paragraphs
            if not content or len(content) < 500:
                all_paragraphs = soup.find_all('p')
                good_paragraphs = [
                    p.get_text(strip=True)
                    for p in all_paragraphs
                    if len(p.get_text(strip=True)) > 50
                ]
                content = '\n\n'.join(good_paragraphs)

            # If still insufficient, try Playwright
            if not content or len(content) < 200:
                print(f"  → Trying Playwright...")
                content = self.get_article_content_playwright(url)

            return content[:50000] if content else ""

        except Exception as e:
            print(f"  ⚠ Error fetching content: {str(e)[:50]}")
            # Try Playwright as backup
            print(f"  → Trying Playwright backup...")
            return self.get_article_content_playwright(url)

    def get_article_content_playwright(self, url):
        """Fetch article content using Playwright"""
        try:
            with sync_playwright() as p:
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox', '--disable-blink-features=AutomationControlled']
                )
                page = browser.new_page(
                    user_agent=self.headers['User-Agent'],
                    viewport={'width': 1920, 'height': 1080}
                )

                page.goto(url, wait_until='domcontentloaded', timeout=30000)

                # Wait for content
                try:
                    page.wait_for_selector('article, p', timeout=5000)
                except:
                    pass

                html = page.content()
                browser.close()

                soup = BeautifulSoup(html, 'html.parser')

                # Remove unwanted elements
                for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside', 'iframe']):
                    element.decompose()

                # Get article content
                article_body = soup.find('article')
                if article_body:
                    paragraphs = article_body.find_all('p')
                    content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs if len(p.get_text(strip=True)) > 50])
                    return content[:50000] if content else ""

                # Fallback: get all good paragraphs
                all_paragraphs = soup.find_all('p')
                good_paragraphs = [
                    p.get_text(strip=True)
                    for p in all_paragraphs
                    if len(p.get_text(strip=True)) > 50
                ]
                content = '\n\n'.join(good_paragraphs)
                return content[:50000] if content else ""

        except Exception as e:
            print(f"  ⚠ Playwright error: {str(e)[:50]}")
            return ""

    def has_paywall(self, content):
        """Detect if content indicates a paywall"""
        if not content:
            return False

        content_lower = content.lower()
        paywall_patterns = [
            'subscribe to read', 'subscribers only', 'subscriber exclusive',
            'premium content', 'create a free account', 'sign in to continue',
            'subscription required', 'become a member', 'unlock this article',
            'register to read', 'paywall', 'exclusive to subscribers',
            'log in to view', 'free trial to read'
        ]

        for pattern in paywall_patterns:
            if pattern in content_lower:
                return True

        # Very short content with account/signin keywords
        if len(content) < 300:
            if any(kw in content_lower for kw in ['subscribe', 'sign in', 'log in', 'member only']):
                return True

        return False

    def update_fulltext(self, article_id, fulltext):
        """Update article with fullText and paywall detection"""
        cursor = self.connection.cursor()

        has_paywall = 'Y' if self.has_paywall(fulltext) else 'N'

        cursor.execute("""
            UPDATE articles
            SET `fullText` = %s, hasPaywall = %s
            WHERE id = %s
        """, (fulltext, has_paywall, article_id))
        self.connection.commit()
        cursor.close()

    def run(self, hours=1):
        """Fetch fullText for recent articles"""
        print("=" * 70)
        print(f"Fetching Full Text for Articles (Last {hours} Hour{'s' if hours > 1 else ''})")
        print("=" * 70)

        if not self.connect_db():
            return

        articles = self.get_recent_articles(hours)

        if not articles:
            print(f"\n✓ No articles found from last {hours} hour(s)")
            return

        print(f"\n📝 Processing {len(articles)} articles...")

        successful = 0

        for i, article in enumerate(articles, 1):
            print(f"\n[{i}/{len(articles)}] {article['title'][:70]}...")

            # Fetch full text
            fulltext = self.get_article_content(article['url'])

            if fulltext and len(fulltext) > 200:
                self.update_fulltext(article['id'], fulltext)
                print(f"  ✓ Saved {len(fulltext)} characters")
                successful += 1
            else:
                print(f"  ⚠ Insufficient content ({len(fulltext) if fulltext else 0} chars)")

        print("\n" + "=" * 70)
        print(f"✓ Updated {successful}/{len(articles)} articles with full text")
        print("=" * 70)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    hours = int(sys.argv[1]) if len(sys.argv) > 1 else 1
    fetcher = FullTextFetcher()
    fetcher.run(hours)
