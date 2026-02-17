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
import env_loader  # Auto-loads .env and ~/.env_AI
import time
import json
from concurrent.futures import ThreadPoolExecutor, as_completed
from threading import Lock
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

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
        """Get articles that need summaries, including failed articles eligible for retry with exponential backoff"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT a.id, a.title, a.url, a.fullArticle, a.summary_retry_count
            FROM articles a
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE (a.summary IS NULL OR a.summary = '')
            AND (s.enabled = 1 OR s.id IS NULL)
            AND (
                -- Never attempted or not marked as failed
                (a.isSummaryFailed IS NULL OR a.isSummaryFailed != 'Y')
                OR
                -- Failed but eligible for retry based on exponential backoff
                (a.isSummaryFailed = 'Y' AND (
                    -- Retry 1: after 1 hour (retry_count = 1)
                    (a.summary_retry_count = 1 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                    OR
                    -- Retry 2: after 6 hours (retry_count = 2)
                    (a.summary_retry_count = 2 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 6 HOUR))
                    OR
                    -- Retry 3: after 24 hours (retry_count = 3)
                    (a.summary_retry_count = 3 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR))
                    OR
                    -- Retry 4+: after 7 days (retry_count >= 4)
                    (a.summary_retry_count >= 4 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 7 DAY))
                ))
            )
            ORDER BY a.scraped_at DESC
            LIMIT %s
        """, (limit,))
        articles = cursor.fetchall()
        cursor.close()
        return articles

    def get_article_from_google_cache(self, url):
        """Fetch article content from Google Cache as last resort"""
        try:
            # Google Cache URL format
            cache_url = f"https://webcache.googleusercontent.com/search?q=cache:{url}"

            response = requests.get(cache_url, headers=self.headers, timeout=20)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Remove script, style, and navigation elements
            for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside']):
                element.decompose()

            content = ""

            # Method 1: Look for article tag
            article_body = soup.find('article')
            if article_body:
                paragraphs = article_body.find_all('p')
                content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])

            # Method 2: Look for main content containers
            if not content or len(content) < 500:
                containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                    term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                ))
                for container in containers[:8]:
                    paragraphs = container.find_all('p')
                    if paragraphs:
                        content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])
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
                content = '\n\n'.join(good_paragraphs[:40])

            return content[:10000] if content else ""

        except Exception as e:
            return ""

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
                    content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])

                # Method 2: Look for main content containers
                if not content or len(content) < 500:
                    containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                        term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                    ))
                    for container in containers[:8]:
                        paragraphs = container.find_all('p')
                        if paragraphs:
                            content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])
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
                    content = '\n\n'.join(good_paragraphs[:40])

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
                content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])

            # Method 2: Look for main content containers
            if not content or len(content) < 500:
                containers = soup.find_all(['div', 'section', 'main'], class_=lambda x: x and any(
                    term in str(x).lower() for term in ['content', 'article', 'post', 'body', 'text', 'story']
                ))
                for container in containers[:8]:
                    paragraphs = container.find_all('p')
                    if paragraphs:
                        content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])
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
                content = '\n\n'.join(good_paragraphs[:40])

            # If still no content, try Playwright as backup (for JavaScript-rendered pages)
            if not content or len(content) < 100:
                print(f"  → Trying Playwright backup...")
                content = self.get_article_content_playwright(url)

            # If Playwright failed, try Google Cache as last resort
            if not content or len(content) < 100:
                print(f"  → Trying Google Cache backup...")
                content = self.get_article_from_google_cache(url)

            # Return extensive content for comprehensive summaries (200+ words needs 1500+ words input)
            return content[:10000] if content else ""

        except Exception as e:
            print(f"  ⚠ Content fetch error: {str(e)[:50]}")
            # Try Playwright as backup
            print(f"  → Trying Playwright backup...")
            content = self.get_article_content_playwright(url)

            # If Playwright failed, try Google Cache as last resort
            if not content or len(content) < 100:
                print(f"  → Trying Google Cache backup...")
                content = self.get_article_from_google_cache(url)

            return content

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
                # Check for AI failure patterns
                failure_patterns = [
                    'I apologize, but',
                    'I cannot provide a summary',
                    'I cannot create a summary',
                    'cannot be created',
                    'cannot be generated',
                    'does not contain substantial information',
                    'appears to be incomplete',
                    'appears to be corrupted',
                    'appears to be a privacy policy',
                    'insufficient information',
                    'privacy policy and consent form',
                    'privacy policy or consent',
                    'consent form',
                    'corrupted or unreadable',
                    'article content appears to be',
                    'article text appears to be',
                    'provided text appears to be',
                    'provided text is not',
                    'text is not the article',
                    'is not the article content',
                    'article content is absent',
                    'actual article content is absent',
                    'without the actual article',
                    'article content is corrupted',
                    'content is corrupted'
                ]

                if any(pattern.lower() in result.lower() for pattern in failure_patterns):
                    print(f"  ⊘ {provider_name} returned failure message, trying next provider...")
                    continue  # Try next provider

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

    def has_paywall(self, content):
        """Detect if content indicates a paywall"""
        if not content:
            return False

        content_lower = content.lower()

        # CNBC Versant paywall blocker
        if 'this site is now part ofversant' in content_lower or 'part of versant' in content_lower:
            return True

        paywall_patterns = [
            'subscribe to read', 'subscribers only', 'subscriber exclusive',
            'premium content', 'create a free account', 'sign in to continue',
            'subscription required', 'become a member', 'unlock this article',
            'register to read', 'paywall', 'exclusive to subscribers'
        ]

        for pattern in paywall_patterns:
            if pattern in content_lower:
                return True

        # Very short content with account/signin keywords
        if len(content) < 300:
            if any(kw in content_lower for kw in ['subscribe', 'sign in', 'log in', 'member only']):
                return True

        return False

    def update_article(self, article_id, summary, categories, fulltext=None):
        """Update article with summary, categories, and fullArticle"""
        try:
            with self.db_lock:
                conn = mysql.connector.connect(**self.db_config)
                cursor = conn.cursor()
                cursor.execute("SET time_zone = '-08:00'")

                # Detect paywall
                has_paywall = 'Y' if fulltext and self.has_paywall(fulltext) else 'N'

                # If paywall detected, clear summary and fullArticle, mark as failed
                if has_paywall == 'Y':
                    cursor.execute("""
                        UPDATE articles
                        SET summary = NULL,
                            `fullArticle` = NULL,
                            hasPaywall = 'Y',
                            summary_date = NULL,
                            isSummaryFailed = 'Y'
                        WHERE id = %s
                    """, (article_id,))
                    print(f"  ⚠ Paywall detected - cleared content and marked as failed")
                # Update summary and fullArticle with success tracking
                elif fulltext:
                    cursor.execute("""
                        UPDATE articles
                        SET summary = %s,
                            `fullArticle` = %s,
                            hasPaywall = %s,
                            summary_date = NOW(),
                            isSummaryFailed = 'N',
                            summary_retry_count = 0,
                            summary_last_attempt = NULL
                        WHERE id = %s
                    """, (summary, fulltext, has_paywall, article_id))
                else:
                    cursor.execute("""
                        UPDATE articles
                        SET summary = %s,
                            summary_date = NOW(),
                            isSummaryFailed = 'N',
                            summary_retry_count = 0,
                            summary_last_attempt = NULL
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

    def mark_article_failed(self, article_id, retry_count=0):
        """Mark article as failed and track retry attempts with exponential backoff"""
        try:
            with self.db_lock:
                conn = mysql.connector.connect(**self.db_config)
                cursor = conn.cursor()
                cursor.execute("SET time_zone = '-08:00'")

                # Increment retry count and update last attempt time
                cursor.execute("""
                    UPDATE articles
                    SET isSummaryFailed = 'Y',
                        summary_retry_count = %s + 1,
                        summary_last_attempt = NOW()
                    WHERE id = %s
                """, (retry_count, article_id))

                conn.commit()
                cursor.close()
                conn.close()
                return True

        except Error as e:
            return False

    def process_article(self, article):
        """Process a single article"""
        try:
            retry_count = article.get('summary_retry_count', 0)
            retry_note = f" (retry {retry_count})" if retry_count > 0 else ""
            print(f"Processing: {article['title'][:60]}...{retry_note}")

            # Start with existing fullArticle if available
            has_existing = article.get('fullArticle') and len(article['fullArticle']) > 200
            content = article['fullArticle'] if has_existing else None

            # Always try to fetch from URL (to get fresh content)
            url_content = self.get_article_content(article['url'])

            if url_content and len(url_content) > 200:
                # Use fresh content from URL
                print(f"  → Fetched from URL ({len(url_content)} chars)")
                content = url_content
            elif has_existing:
                # Fallback to existing fullArticle
                print(f"  → Using existing fullArticle ({len(content)} chars)")
            else:
                # No content available
                print(f"  ⊘ No content")
                self.mark_article_failed(article['id'], retry_count)
                return False

            # Summarize
            summary = self.summarize_with_ai(article['title'], content)
            if not summary:
                print(f"  ⊘ No summary")
                # Don't mark as failed if we have fullArticle - might be AI issue
                if not has_existing:
                    self.mark_article_failed(article['id'], retry_count)
                return False

            # Categorize
            categories = self.categorize_with_ai(article['title'], summary)

            # Force-include Sports category for sports sources (ESPN=17, NY Athletic=18, AP Sports=19)
            if article.get('source_id') in [17, 18, 19] and 'Sports' not in categories:
                categories.insert(0, 'Sports')  # Add Sports as first category

            # Save fullArticle (limit to 50KB)
            fulltext = content[:50000] if content and len(content) > 200 else None

            # Update database - also reset retry counter on success
            if self.update_article(article['id'], summary, categories, fulltext):
                fulltext_note = f" + {len(fulltext)} chars" if fulltext else ""
                print(f"  ✓ Done - {', '.join(categories)}{fulltext_note}")
                return True

            self.mark_article_failed(article['id'], retry_count)
            return False

        except Exception as e:
            print(f"  ✗ Error: {e}")
            retry_count = article.get('summary_retry_count', 0)
            self.mark_article_failed(article['id'], retry_count)
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
