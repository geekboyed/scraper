#!/usr/bin/env python3
"""
Background Article Summarizer
Processes unsummarized articles using Gemini AI
"""

import os
import sys
import requests
from bs4 import BeautifulSoup
import mysql.connector
from mysql.connector import Error
import google.generativeai as genai
from dotenv import load_dotenv
import time
import json

# Load environment variables
load_dotenv()

class ArticleSummarizer:
    def __init__(self):
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
            # Use gemini-2.5-flash (fast and cost-effective)
            self.gemini_model = genai.GenerativeModel('gemini-2.5-flash')
            print("✓ Gemini API configured")
        else:
            print("⚠ No Gemini API key found")
            self.gemini_model = None

        # DeepSeek configuration (fallback)
        self.deepseek_key = os.getenv('DEEPSEEK_API_KEY')
        if self.deepseek_key:
            print("✓ DeepSeek API configured (fallback)")
        else:
            print("⚠ No DeepSeek API key found")

        if not gemini_key and not self.deepseek_key:
            print("✗ No API keys found!")
            sys.exit(1)

        self.connection = None
        self.categories_cache = {}
        self.use_deepseek = False  # Track if we should use DeepSeek

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

    def load_categories(self):
        """Load categories into cache"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, description FROM categories")
        for row in cursor.fetchall():
            self.categories_cache[row['name']] = row
        cursor.close()
        print(f"✓ Loaded {len(self.categories_cache)} categories")

    def get_unsummarized_articles(self, limit=10):
        """Get articles that don't have summaries yet"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, title, url
            FROM articles
            WHERE summary IS NULL OR summary = ''
            ORDER BY scraped_at DESC
            LIMIT %s
        """, (limit,))
        articles = cursor.fetchall()
        cursor.close()
        return articles

    def get_article_content(self, url):
        """Fetch full article content"""
        try:
            response = requests.get(url, headers=self.headers, timeout=15)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Try multiple methods to extract content
            content = ""

            # Method 1: Look for article tag
            article_body = soup.find('article')
            if article_body:
                paragraphs = article_body.find_all('p')
                content = ' '.join([p.get_text(strip=True) for p in paragraphs[:15]])

            # Method 2: Look for common content containers
            if not content or len(content) < 100:
                containers = soup.find_all(['div', 'section'], class_=lambda x: x and any(
                    term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text']
                ))
                for container in containers[:3]:
                    paragraphs = container.find_all('p')
                    if paragraphs:
                        content = ' '.join([p.get_text(strip=True) for p in paragraphs[:15]])
                        if len(content) > 100:
                            break

            # Method 3: Just get all paragraphs from the page
            if not content or len(content) < 100:
                all_paragraphs = soup.find_all('p')
                # Filter out short paragraphs (likely navigation/footer)
                good_paragraphs = [p.get_text(strip=True) for p in all_paragraphs if len(p.get_text(strip=True)) > 50]
                content = ' '.join(good_paragraphs[:15])

            return content[:4000] if content else ""

        except Exception as e:
            print(f"  ⚠ Error fetching content: {e}")
            return ""

    def summarize_article(self, title, content):
        """Use Gemini to summarize the article"""
        try:
            if not content or len(content) < 50:
                return None

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
            print(f"  ⚠ Error summarizing: {e}")
            return None

    def categorize_article(self, title, summary):
        """Use Gemini to categorize the article"""
        try:
            if not summary:
                return ['Global Business']

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
            suggested_categories = [cat.strip() for cat in result.split(',')]

            # Validate against available categories
            valid_categories = []
            for cat in suggested_categories:
                if cat in self.categories_cache:
                    valid_categories.append(cat)

            return valid_categories[:3] if valid_categories else ['Global Business']

        except Exception as e:
            print(f"  ⚠ Error categorizing: {e}")
            return ['Global Business']

    def update_article(self, article_id, summary, categories):
        """Update article with summary and categories"""
        try:
            cursor = self.connection.cursor()

            # Update summary
            cursor.execute("""
                UPDATE articles
                SET summary = %s
                WHERE id = %s
            """, (summary, article_id))

            # Insert categories
            for category_name in categories:
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
            print(f"  ✗ Error updating article: {e}")
            return False

    def run(self, batch_size=10):
        """Main processing workflow"""
        print("=" * 60)
        print("Background Article Summarizer")
        print("=" * 60)

        if not self.connect_db():
            return

        # Get unsummarized articles
        articles = self.get_unsummarized_articles(batch_size)

        if not articles:
            print("\n✓ No articles need summarization")
            return

        print(f"\n📝 Processing {len(articles)} unsummarized articles...")
        successful = 0

        for i, article in enumerate(articles, 1):
            print(f"\n[{i}/{len(articles)}] {article['title'][:80]}...")

            # Get full content
            print("  → Fetching content...")
            content = self.get_article_content(article['url'])

            if not content:
                print("  ⊘ No content found, skipping")
                continue

            # Summarize
            print("  → Summarizing with Gemini...")
            summary = self.summarize_article(article['title'], content)

            if not summary:
                print("  ⊘ Could not generate summary")
                continue

            # Categorize
            print("  → Categorizing...")
            categories = self.categorize_article(article['title'], summary)
            print(f"  → Categories: {', '.join(categories)}")

            # Save to database
            if self.update_article(article['id'], summary, categories):
                print("  ✓ Updated successfully")
                successful += 1

            # Rate limiting: Gemini free tier = 5 requests/minute
            # Each article = 2 requests (summary + categorize)
            # So we can only do 2 articles per minute safely
            # Wait 15 seconds between articles (4 articles/min = safe)
            time.sleep(15)

        print("\n" + "=" * 60)
        print(f"✓ Successfully processed {successful}/{len(articles)} articles")
        print("=" * 60)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    # Get batch size from command line argument if provided
    batch_size = int(sys.argv[1]) if len(sys.argv) > 1 else 10

    summarizer = ArticleSummarizer()
    summarizer.run(batch_size)
