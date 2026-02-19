#!/usr/bin/env python3
"""
Parallel Background Article Summarizer
Processes multiple articles concurrently using 1min.ai (GPT-4o-mini) + Claude fallback
With Playwright backup for JavaScript-rendered pages
"""

import os
import sys
import requests
from bs4 import BeautifulSoup
import mysql.connector
from mysql.connector import Error
from google import genai
from google.genai import types
from dotenv import load_dotenv
import time
import json
from concurrent.futures import ThreadPoolExecutor, as_completed
from threading import Lock
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

# Load environment variables
load_dotenv()

class ParallelSummarizer:
    def __init__(self):
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9',
            'Accept-Encoding': 'gzip, deflate, br',
            'Connection': 'keep-alive',
            'Upgrade-Insecure-Requests': '1',
            'Sec-Fetch-Dest': 'document',
            'Sec-Fetch-Mode': 'navigate',
            'Sec-Fetch-Site': 'none',
            'Cache-Control': 'max-age=0'
        }

        # Database configuration
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        # Load AI provider order from .env
        provider_order = os.getenv('AI_PROVIDER_ORDER', 'anthropic,minai,deepseek').split(',')
        self.provider_order = [p.strip() for p in provider_order]

        # API Keys configuration
        self.minai_key = os.getenv('MINAI_API_KEY')
        self.anthropic_key = os.getenv('ANTHROPIC_API_KEY')
        self.deepseek_key = os.getenv('DEEPSEEK_API_KEY')
        self.openai_key = os.getenv('OPENAI_API_KEY')

        # Display configuration
        print(f"✓ AI Provider Order: {' → '.join(self.provider_order)}")
        if self.anthropic_key:
            print("✓ Anthropic API configured (Claude 3.5 Haiku)")
        if self.minai_key:
            print("✓ 1min.ai API configured (GPT-4o-mini)")
        if self.deepseek_key:
            print("✓ DeepSeek API configured")
        if self.openai_key:
            print("✓ OpenAI API configured (quota may be limited)")

        # Gemini direct configuration (disabled but kept for future use)
        self.gemini_client = None

        # Check if we have at least one working API key
        if not any([self.minai_key, self.anthropic_key, self.deepseek_key, self.openai_key]):
            print("✗ No API keys found!")
            sys.exit(1)

        self.connection = None
        self.categories_cache = {}
        self.db_lock = Lock()
        self.gemini_rate_limited = False

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
        """Get articles that don't have summaries yet from enabled sources (excluding previously failed)"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT a.id, a.title, a.url
            FROM articles a
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE (a.summary IS NULL OR a.summary = '')
            AND (a.isSummaryFailed IS NULL OR a.isSummaryFailed != 'Y')
            AND (s.isActive = 'Y' OR s.id IS NULL)
            ORDER BY a.scraped_at DESC
            LIMIT %s
        """, (limit,))
        articles = cursor.fetchall()
        cursor.close()
        return articles

    def get_article_content_playwright(self, url):
        """Fetch article content using Playwright (for JavaScript-rendered pages)"""
        try:
            with sync_playwright() as p:
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox', '--disable-blink-features=AutomationControlled']
                )
                page = browser.new_page(
                    user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    viewport={'width': 1920, 'height': 1080}
                )

                # Set a longer timeout and go to the page
                page.goto(url, wait_until='domcontentloaded', timeout=60000)

                # Wait for content to load (or timeout after 5 seconds)
                try:
                    page.wait_for_selector('article, p', timeout=5000)
                except:
                    pass  # Continue even if selector not found

                # Get the page content
                html = page.content()
                browser.close()

                # Parse with BeautifulSoup
                soup = BeautifulSoup(html, 'html.parser')

                # Remove script, style, and navigation elements
                for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside']):
                    element.decompose()

                content = ""

                # Method 1: Look for article tag
                article_body = soup.find('article')
                if article_body:
                    paragraphs = article_body.find_all('p')
                    content = ' '.join([p.get_text(strip=True) for p in paragraphs[:40]])

                # Method 2: Look for main content containers
                if not content or len(content) < 500:
                    containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                        term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                    ))
                    for container in containers[:8]:
                        paragraphs = container.find_all('p')
                        if paragraphs:
                            content = ' '.join([p.get_text(strip=True) for p in paragraphs[:40]])
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
                    content = ' '.join(good_paragraphs[:40])

                return content[:10000] if content else ""

        except PlaywrightTimeout:
            print(f"  ⚠ Playwright timeout")
            return ""
        except Exception as e:
            print(f"  ⚠ Playwright error: {str(e)[:50]}")
            return ""

    def get_article_content(self, url):
        """Fetch comprehensive article content for detailed summarization"""
        try:
            response = requests.get(url, headers=self.headers, timeout=20)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Remove script, style, and navigation elements
            for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside']):
                element.decompose()

            content = ""

            # Method 1: Look for article tag (most reliable)
            article_body = soup.find('article')
            if article_body:
                paragraphs = article_body.find_all('p')
                content = ' '.join([p.get_text(strip=True) for p in paragraphs[:40]])

            # Method 2: Look for main content containers
            if not content or len(content) < 500:
                containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                    term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                ))
                for container in containers[:8]:
                    paragraphs = container.find_all('p')
                    if paragraphs:
                        content = ' '.join([p.get_text(strip=True) for p in paragraphs[:40]])
                        if len(content) > 500:
                            break

            # Method 3: Get all substantial paragraphs
            if not content or len(content) < 500:
                all_paragraphs = soup.find_all('p')
                # Filter paragraphs - keep those with substantial content
                good_paragraphs = [
                    p.get_text(strip=True)
                    for p in all_paragraphs
                    if len(p.get_text(strip=True)) > 50
                ]
                content = ' '.join(good_paragraphs[:40])

            # If still no content, try Playwright as backup (for JavaScript-rendered pages)
            if not content or len(content) < 100:
                print(f"  → Trying Playwright backup...")
                content = self.get_article_content_playwright(url)

            # Return extensive content for comprehensive summaries (200+ words needs 1500+ words input)
            return content[:10000] if content else ""

        except Exception as e:
            print(f"  ⚠ Content fetch error: {str(e)[:50]}")
            # Try Playwright as backup
            print(f"  → Trying Playwright backup...")
            return self.get_article_content_playwright(url)

    def call_minai(self, prompt, max_tokens=200):
        """Call 1min.ai API (using GPT-4o-mini)"""
        try:
            response = requests.post(
                'https://api.1min.ai/api/features?isStreaming=false',
                headers={
                    'API-KEY': self.minai_key,
                    'Content-Type': 'application/json'
                },
                json={
                    'type': 'CHAT_WITH_AI',
                    'model': 'gpt-4o-mini',
                    'promptObject': {
                        'prompt': prompt
                    }
                },
                timeout=30
            )

            if response.status_code == 200:
                result = response.json()
                # Extract response: aiRecord -> aiRecordDetail -> resultObject[0]
                if 'aiRecord' in result:
                    ai_record = result['aiRecord']
                    if 'aiRecordDetail' in ai_record:
                        detail = ai_record['aiRecordDetail']
                        if 'resultObject' in detail:
                            result_obj = detail['resultObject']
                            if isinstance(result_obj, list) and len(result_obj) > 0:
                                return result_obj[0].strip()
                return None
            else:
                return None

        except Exception as e:
            return None

    def call_anthropic(self, prompt, max_tokens=200):
        """Call Anthropic Claude API"""
        try:
            response = requests.post(
                'https://api.anthropic.com/v1/messages',
                headers={
                    'x-api-key': self.anthropic_key,
                    'anthropic-version': '2023-06-01',
                    'Content-Type': 'application/json'
                },
                json={
                    'model': 'claude-3-5-haiku-20241022',
                    'max_tokens': max_tokens,
                    'messages': [
                        {'role': 'user', 'content': prompt}
                    ]
                },
                timeout=30
            )

            if response.status_code == 200:
                result = response.json()
                if 'content' in result and len(result['content']) > 0:
                    return result['content'][0]['text'].strip()
                return None
            else:
                return None

        except Exception as e:
            return None

    def call_deepseek(self, prompt, max_tokens=200):
        """Call DeepSeek API (kept for reference)"""
        try:
            response = requests.post(
                'https://api.deepseek.com/v1/chat/completions',
                headers={
                    'Authorization': f'Bearer {self.deepseek_key}',
                    'Content-Type': 'application/json'
                },
                json={
                    'model': 'deepseek-chat',
                    'messages': [
                        {'role': 'system', 'content': 'You are a business news summarizer. Provide concise, factual summaries.'},
                        {'role': 'user', 'content': prompt}
                    ],
                    'temperature': 0.3,
                    'max_tokens': max_tokens
                },
                timeout=30
            )

            if response.status_code == 200:
                return response.json()['choices'][0]['message']['content'].strip()
            else:
                return None

        except Exception as e:
            return None

    def call_openai(self, prompt, max_tokens=200):
        """Call OpenAI API"""
        try:
            response = requests.post(
                'https://api.openai.com/v1/chat/completions',
                headers={
                    'Authorization': f'Bearer {self.openai_key}',
                    'Content-Type': 'application/json'
                },
                json={
                    'model': 'gpt-4o-mini',
                    'messages': [
                        {'role': 'system', 'content': 'You are a business news summarizer. Provide concise, factual summaries.'},
                        {'role': 'user', 'content': prompt}
                    ],
                    'temperature': 0.3,
                    'max_tokens': max_tokens
                },
                timeout=30
            )

            if response.status_code == 200:
                return response.json()['choices'][0]['message']['content'].strip()
            else:
                return None

        except Exception as e:
            return None

    def summarize_with_ai(self, title, content):
        """Summarize using AI providers in configured order - comprehensive 200+ word summary"""
        if not content or len(content) < 100:
            return None

        prompt = f"""You are an expert business news analyst. Create a concise, informative summary of this article.

Article Title: {title}

Article Content:
{content}

Instructions for the summary:
1. Write between 200-300 words (aim for 250 words)
2. Include the most important facts, figures, and key statistics
3. Name all important people, companies, and organizations
4. Explain the core context and main points
5. Describe the key implications
6. Use clear, engaging language
7. Be concise and focused - every sentence should add value
8. Stay within the 200-300 word limit

Write a summary (200-300 words):"""

        # Try providers in configured order
        for provider in self.provider_order:
            result = None

            if provider == 'anthropic' and self.anthropic_key:
                result = self.call_anthropic(prompt, max_tokens=500)
                provider_name = "Claude"
            elif provider == 'minai' and self.minai_key:
                result = self.call_minai(prompt, max_tokens=500)
                provider_name = "1min.ai (GPT-4o-mini)"
            elif provider == 'deepseek' and self.deepseek_key:
                result = self.call_deepseek(prompt, max_tokens=500)
                provider_name = "DeepSeek"
            elif provider == 'openai' and self.openai_key:
                result = self.call_openai(prompt, max_tokens=500)
                provider_name = "OpenAI"
            else:
                continue  # Provider not configured, skip

            if result:
                word_count = len(result.split())
                if word_count > 350:
                    print(f"  ⚠ Summary too long ({word_count} words), truncating...")
                    words = result.split()[:300]
                    result = ' '.join(words)
                    word_count = 300
                print(f"  ✓ {provider_name} generated {word_count} word summary")
                if word_count < 150:
                    print(f"  ⚠ Summary might be too short")
                return result
            else:
                print(f"  → {provider_name} failed, trying next provider...")

        return None

    def categorize_with_ai(self, title, summary):
        """Categorize using AI providers in configured order"""
        if not summary:
            return ['Global Business']

        categories_list = list(self.categories_cache.keys())
        prompt = f"""Categorize this article into the most relevant categories from: {', '.join(categories_list)}

Title: {title}
Summary: {summary}

Return ONLY category names separated by commas (up to 3):"""

        # Try providers in configured order
        for provider in self.provider_order:
            result = None

            if provider == 'anthropic' and self.anthropic_key:
                result = self.call_anthropic(prompt, max_tokens=50)
            elif provider == 'minai' and self.minai_key:
                result = self.call_minai(prompt, max_tokens=50)
            elif provider == 'deepseek' and self.deepseek_key:
                result = self.call_deepseek(prompt, max_tokens=50)
            elif provider == 'openai' and self.openai_key:
                result = self.call_openai(prompt, max_tokens=50)
            else:
                continue  # Provider not configured, skip

            if result:
                suggested = [cat.strip() for cat in result.split(',')]
                valid = [cat for cat in suggested if cat in self.categories_cache]
                return valid[:3] if valid else ['Global Business']

        return ['Global Business']

    def update_article(self, article_id, summary, categories, fulltext=None):
        """Update article with summary, categories, and fullText"""
        try:
            with self.db_lock:
                conn = mysql.connector.connect(**self.db_config)
                cursor = conn.cursor()

                # Update summary and fullText with success tracking
                if fulltext:
                    cursor.execute("""
                        UPDATE articles
                        SET summary = %s,
                            `fullText` = %s,
                            summary_date = NOW(),
                            isSummaryFailed = 'N'
                        WHERE id = %s
                    """, (summary, fulltext, article_id))
                else:
                    cursor.execute("""
                        UPDATE articles
                        SET summary = %s,
                            summary_date = NOW(),
                            isSummaryFailed = 'N'
                        WHERE id = %s
                    """, (summary, article_id))

                for category_name in categories:
                    if category_name in self.categories_cache:
                        category_id = self.categories_cache[category_name]['id']
                        cursor.execute("""
                            INSERT IGNORE INTO article_categories (article_id, category_id)
                            VALUES (%s, %s)
                        """, (article_id, category_id))

                conn.commit()
                cursor.close()
                conn.close()
                return True

        except Error as e:
            return False

    def mark_article_failed(self, article_id):
        """Mark article as failed to summarize"""
        try:
            with self.db_lock:
                conn = mysql.connector.connect(**self.db_config)
                cursor = conn.cursor()

                cursor.execute("""
                    UPDATE articles
                    SET isSummaryFailed = 'Y'
                    WHERE id = %s
                """, (article_id,))

                conn.commit()
                cursor.close()
                conn.close()
                return True

        except Error as e:
            return False

    def process_article(self, article):
        """Process a single article"""
        try:
            print(f"Processing: {article['title'][:60]}...")

            # Get content
            content = self.get_article_content(article['url'])
            if not content:
                print(f"  ⊘ No content")
                self.mark_article_failed(article['id'])
                return False

            # Summarize
            summary = self.summarize_with_ai(article['title'], content)
            if not summary:
                print(f"  ⊘ No summary")
                self.mark_article_failed(article['id'])
                return False

            # Categorize
            categories = self.categorize_with_ai(article['title'], summary)

            # Save fullText (limit to 50KB)
            fulltext = content[:50000] if content and len(content) > 200 else None

            # Update database
            if self.update_article(article['id'], summary, categories, fulltext):
                fulltext_note = f" + {len(fulltext)} chars" if fulltext else ""
                print(f"  ✓ Done - {', '.join(categories)}{fulltext_note}")
                return True

            self.mark_article_failed(article['id'])
            return False

        except Exception as e:
            print(f"  ✗ Error: {e}")
            self.mark_article_failed(article['id'])
            return False

    def run(self, batch_size=20, max_workers=5):
        """Run parallel processing"""
        print("=" * 60)
        print(f"Parallel Article Summarizer ({' → '.join(self.provider_order)})")
        print("=" * 60)

        if not self.connect_db():
            return

        articles = self.get_unsummarized_articles(batch_size)

        if not articles:
            print("\n✓ No articles need summarization")
            return

        print(f"\n📝 Processing {len(articles)} articles with {max_workers} parallel workers...")

        successful = 0
        start_time = time.time()

        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = {executor.submit(self.process_article, article): article for article in articles}

            for future in as_completed(futures):
                if future.result():
                    successful += 1

        elapsed = time.time() - start_time

        print("\n" + "=" * 60)
        print(f"✓ Processed {successful}/{len(articles)} articles in {elapsed:.1f}s")
        print("=" * 60)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    batch_size = int(sys.argv[1]) if len(sys.argv) > 1 else 20
    max_workers = int(sys.argv[2]) if len(sys.argv) > 2 else 5

    summarizer = ParallelSummarizer()
    summarizer.run(batch_size, max_workers)
