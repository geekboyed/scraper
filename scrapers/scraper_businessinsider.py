#!/usr/bin/env python3
"""
Business Insider Web Scraper
Scrapes articles from the homepage, summarizes them using AI, and categorizes them.
"""

import os
import sys
import requests
from bs4 import BeautifulSoup
from datetime import datetime, timedelta
import mysql.connector
from mysql.connector import Error
import google.generativeai as genai
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI
import time
import re

class BusinessInsiderScraper:
    def __init__(self):
        self.base_url = "https://www.businessinsider.com"
        self.source_id = 1  # Business Insider source ID
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        }

        # Database configuration
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        # Gemini configuration
        gemini_key = os.getenv('GEMINI_API_KEY')
        if gemini_key:
            genai.configure(api_key=gemini_key)
            self.gemini_model = genai.GenerativeModel('gemini-pro')
        else:
            print("⚠ Warning: No Gemini API key found")
            self.gemini_model = None

        self.connection = None
        self.categories_cache = {}

    def connect_db(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            if self.connection.is_connected():
                print("✓ Connected to MySQL database")
                self.load_categories()
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

    def load_categories(self):
        """Load categories into cache"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, description FROM categories")
        for row in cursor.fetchall():
            self.categories_cache[row['name']] = row
        cursor.close()
        print(f"✓ Loaded {len(self.categories_cache)} categories")

    def scrape_homepage(self):
        """Scrape articles from Business Insider homepage"""
        try:
            print(f"\n🔍 Scraping {self.base_url}...")
            response = requests.get(self.base_url, headers=self.headers, timeout=10)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')
            articles = []

            # Find article links - Business Insider uses various patterns
            # Looking for article links in common containers
            article_links = soup.find_all('a', href=True)

            seen_urls = set()
            for link in article_links:
                href = link.get('href', '')

                # Filter for article URLs (typically contain year in path like /2025/ or /2026/)
                if re.search(r'/\d{4}/\d+/', href) or href.startswith('/'):
                    # Make absolute URL
                    if href.startswith('/'):
                        url = self.base_url + href
                    else:
                        url = href

                    # Get title from link text or nearby heading
                    title = link.get_text(strip=True)

                    # Skip if no title or duplicate URL
                    if not title or len(title) < 10 or url in seen_urls:
                        continue

                    # Skip navigation links, category pages, and non-article URLs
                    skip_patterns = [
                        '/author/', '/videos/', '/category/', 'javascript:', '#',
                        '/newsletters', '/app', '/standing', '/explainers',
                        '/so-expensive', '/big-business', '/military-defense',
                        '/entertainment', '/innovation', '/transportation',
                        '/enterprise', '/personal-finance', '/small-business',
                        '/real-estate', '/advertising', '/about', '/contact',
                        '/privacy', '/terms', '/subscribe'
                    ]
                    if any(skip in url.lower() for skip in skip_patterns):
                        continue

                    # Skip if title is too short (likely a category/nav link)
                    if len(title) < 20:
                        continue

                    seen_urls.add(url)
                    articles.append({
                        'title': title[:500],  # Limit title length
                        'url': url[:500]
                    })

                    if len(articles) >= 20:  # Limit to 20 articles per run
                        break

            print(f"✓ Found {len(articles)} articles")
            return articles

        except Exception as e:
            print(f"✗ Error scraping homepage: {e}")
            return []

    def get_article_content(self, url):
        """Fetch full article content"""
        try:
            response = requests.get(url, headers=self.headers, timeout=10)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Extract article text (Business Insider specific selectors)
            article_body = soup.find('div', {'class': 'content-lock-content'}) or \
                          soup.find('div', {'class': 'post-content'}) or \
                          soup.find('article')

            if article_body:
                # Get all paragraphs
                paragraphs = article_body.find_all('p')
                content = ' '.join([p.get_text(strip=True) for p in paragraphs[:10]])  # First 10 paragraphs
                return content[:3000]  # Limit content length

            return ""
        except Exception as e:
            print(f"  ⚠ Error fetching article content: {e}")
            return ""

    def summarize_article(self, title, content):
        """Use Gemini to summarize the article"""
        try:
            if not content or len(content) < 50:
                return f"Article about {title}"

            if not self.gemini_model:
                return f"Summary not available - no Gemini API key configured"

            prompt = f"""Summarize the following business article in 2-3 concise sentences. Focus on the key facts and main points.

Title: {title}
Content: {content}

Provide only the summary, no additional commentary:"""

            response = self.gemini_model.generate_content(
                prompt,
                generation_config={
                    'temperature': 0.3,
                    'max_output_tokens': 200,
                }
            )

            summary = response.text.strip()
            return summary

        except Exception as e:
            print(f"  ⚠ Error summarizing article: {e}")
            return f"Article about {title}"

    def categorize_article(self, title, summary):
        """Use Gemini to categorize the article"""
        try:
            if not self.gemini_model:
                return ['Global Business']  # Default category

            categories_list = list(self.categories_cache.keys())

            prompt = f"""Categorize the following business article into the most relevant categories from this list:
{', '.join(categories_list)}

Title: {title}
Summary: {summary}

Return ONLY the category names separated by commas (up to 3 categories, most relevant first). Do not include any other text:"""

            response = self.gemini_model.generate_content(
                prompt,
                generation_config={
                    'temperature': 0.2,
                    'max_output_tokens': 50,
                }
            )

            result = response.text.strip()

            # Parse categories
            suggested_categories = [cat.strip() for cat in result.split(',')]

            # Validate against available categories
            valid_categories = []
            for cat in suggested_categories:
                if cat in self.categories_cache:
                    valid_categories.append(cat)

            return valid_categories[:3] if valid_categories else ['Global Business']

        except Exception as e:
            print(f"  ⚠ Error categorizing article: {e}")
            return ['Global Business']  # Default category

    def save_article(self, article_data):
        """Save article to database"""
        try:
            cursor = self.connection.cursor()

            # Check if article already exists (by URL or same title within 24 hours)
            cursor.execute("""
                SELECT id FROM articles
                WHERE url = %s
                OR (source_id = %s AND title = %s AND ABS(DATEDIFF(published_date, %s)) <= 1)
            """, (
                article_data['url'],
                self.source_id,
                article_data['title'],
                article_data.get('published_date', datetime.now().date())
            ))

            existing = cursor.fetchone()
            if existing:
                print("  ℹ Article already exists, skipping...")
                cursor.close()
                return 'skipped'

            # Insert new article
            insert_query = """
                INSERT INTO articles (source_id, title, url, published_date, summary, `fullArticle`)
                VALUES (%s, %s, %s, %s, %s, %s)
            """

            cursor.execute(insert_query, (
                self.source_id,
                article_data['title'],
                article_data['url'],
                article_data.get('date', datetime.now().date()),
                article_data['summary'],
                article_data.get('fullArticle', None)
            ))

            article_id = cursor.lastrowid

            # Insert categories
            if article_id and 'categories' in article_data:
                for category_name in article_data['categories']:
                    if category_name in self.categories_cache:
                        category_id = self.categories_cache[category_name]['id']
                        cursor.execute("""
                            INSERT IGNORE INTO article_categories (article_id, category_id)
                            VALUES (%s, %s)
                        """, (article_id, category_id))

            self.connection.commit()
            cursor.close()
            return True

        except Error as e:
            print(f"  ✗ Error saving article: {e}")
            return False

    def get_article_id(self, url, title):
        """Get article ID by title + source"""
        cursor = self.connection.cursor()
        cursor.execute("""
            SELECT id FROM articles
            WHERE title = %s AND source_id = %s
        """, (title, self.source_id))
        result = cursor.fetchone()
        cursor.close()
        return result[0] if result else None

    def run(self):
        """Main scraping workflow"""
        print("=" * 60)
        print("Business Insider Scraper")
        print("=" * 60)

        if not self.connect_db():
            return

        if not self.is_source_enabled():
            print(f"⊘ Source '{self.base_url}' is disabled. Skipping.")
            return

        # Scrape homepage
        articles = self.scrape_homepage()

        if not articles:
            print("No articles found to process")
            return

        print(f"\n📝 Processing {len(articles)} articles...")
        successful = 0

        for i, article in enumerate(articles, 1):
            print(f"\n[{i}/{len(articles)}] {article['title'][:80]}...")

            # Get full content
            content = self.get_article_content(article['url'])

            # Save full text
            article['fullArticle'] = content if content else None
            if content:
                print(f"  → Full text: {len(content)} characters")

            # Summarize
            print("  → Summarizing...")
            summary = self.summarize_article(article['title'], content)
            article['summary'] = summary

            # Categorize
            print("  → Categorizing...")
            categories = self.categorize_article(article['title'], summary)
            article['categories'] = categories
            print(f"  → Categories: {', '.join(categories)}")

            # Save to database
            if self.save_article(article):
                print("  ✓ Saved to database")
                successful += 1

            # Rate limiting
            time.sleep(1)

        print("\n" + "=" * 60)
        print(f"✓ Successfully processed {successful}/{len(articles)} articles")
        print("=" * 60)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    scraper = BusinessInsiderScraper()
    scraper.run()
