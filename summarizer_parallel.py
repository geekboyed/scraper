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
# from google import genai  # Gemini disabled
# from google.genai import types
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

        # Model configuration from environment
        self.anthropic_model = os.getenv('ANTHROPIC_MODEL', 'claude-sonnet-4-6')
        self.deepseek_model = os.getenv('DEEPSEEK_MODEL', 'deepseek-chat')
        self.openai_model = os.getenv('OPENAI_MODEL', 'gpt-4o-mini')

        # Display configuration
        print(f"✓ AI Provider Order: {' → '.join(self.provider_order)}")
        if self.anthropic_key:
            print(f"✓ Anthropic API configured ({self.anthropic_model})")
        if self.minai_key:
            print("✓ 1min.ai API configured (GPT-4o-mini)")
        if self.deepseek_key:
            print(f"✓ DeepSeek API configured ({self.deepseek_model})")
        if self.openai_key:
            print(f"✓ OpenAI API configured ({self.openai_model})")

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
        """Load categories into cache (level 1 = parent, level 2 = child)"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, description, level, parentID FROM categories")
        for row in cursor.fetchall():
            self.categories_cache[row['name']] = row
        cursor.close()
        level2_count = sum(1 for c in self.categories_cache.values() if c.get('level') == 2)
        print(f"✓ Loaded {len(self.categories_cache)} categories ({level2_count} assignable)")

    def get_unsummarized_articles(self, limit=10):
        """Get articles that need summaries, including failed articles eligible for retry with exponential backoff"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT a.id, a.title, a.url, a.fullArticle, a.summary_retry_count, s.mainCategory, s.name as source_name
            FROM articles a
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE (a.summary IS NULL OR a.summary = '')
            AND (s.isActive = 'Y' OR s.id IS NULL)
            AND (
                -- Never attempted or not marked as failed
                (a.isSummaryFailed IS NULL OR a.isSummaryFailed != 'Y')
                OR
                -- Failed but eligible for retry (only if article is less than 1 day old)
                (a.isSummaryFailed = 'Y'
                    AND a.scraped_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    AND (
                        -- Retry 1: after 1 hour (retry_count = 1)
                        (a.summary_retry_count = 1 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                        OR
                        -- Retry 2: after 6 hours (retry_count = 2)
                        (a.summary_retry_count = 2 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 6 HOUR))
                        OR
                        -- Retry 3: after 12 hours (retry_count = 3)
                        (a.summary_retry_count = 3 AND a.summary_last_attempt < DATE_SUB(NOW(), INTERVAL 12 HOUR))
                    )
                )
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
        # Force Playwright for JavaScript-heavy sites
        if 'yahoo.com' in url.lower() or 'cnbc.com' in url.lower():
            print(f"  → Using Playwright for JavaScript-heavy site")
            return self.get_article_content_playwright(url)

        try:
            response = requests.get(url, headers=self.headers, timeout=20)
            response.raise_for_status()

            soup = BeautifulSoup(response.content, 'html.parser')

            # Remove script, style, and navigation elements
            for element in soup(['script', 'style', 'nav', 'header', 'footer', 'aside']):
                element.decompose()

            content = ""

            # Method 1a: CNBC-specific selectors (high priority for CNBC articles)
            if 'cnbc.com' in url.lower():
                cnbc_selectors = [
                    'div.ArticleBody-articleBody',
                    'div.RenderKeyPoints-list',
                    'div.group',
                    'div[class*="ArticleBody"]',
                    'div[class*="article-body"]',
                ]
                for selector in cnbc_selectors:
                    elements = soup.select(selector)
                    if elements:
                        paragraphs = []
                        for elem in elements:
                            paragraphs.extend(elem.find_all('p'))
                        if paragraphs:
                            content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])
                            if len(content) > 500:
                                print(f"  → Using CNBC selector: {selector}")
                                break

            # Method 1b: Yahoo Finance-specific selectors
            if not content and 'yahoo.com' in url.lower():
                yahoo_selectors = [
                    'div.caas-body',
                    'div.article-body',
                    'div[class*="caas-body"]',
                    'div[class*="article-wrap"]',
                    'article div.body',
                ]
                for selector in yahoo_selectors:
                    elements = soup.select(selector)
                    if elements:
                        paragraphs = []
                        for elem in elements:
                            paragraphs.extend(elem.find_all('p'))
                        if paragraphs:
                            content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])
                            if len(content) > 500:
                                print(f"  → Using Yahoo Finance selector: {selector}")
                                break

            # Method 2: Look for article tag (most reliable)
            if not content or len(content) < 500:
                article_body = soup.find('article')
                if article_body:
                    paragraphs = article_body.find_all('p')
                    content = '\n\n'.join([p.get_text(strip=True) for p in paragraphs[:40]])

            # Method 4: Look for main content containers
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

            # Method 5: Get all substantial paragraphs
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

            # Check if content is cookie consent/privacy policy text
            if content and self.is_cookie_consent_content(content):
                print(f"  ⊘ Cookie consent/privacy policy content detected, rejecting")
                return ""

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

            # Check if content is cookie consent/privacy policy text
            if content and self.is_cookie_consent_content(content):
                print(f"  ⊘ Cookie consent/privacy policy content detected, rejecting")
                return ""

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
                    'model': self.anthropic_model,
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
        """Call DeepSeek API"""
        try:
            response = requests.post(
                'https://api.deepseek.com/v1/chat/completions',
                headers={
                    'Authorization': f'Bearer {self.deepseek_key}',
                    'Content-Type': 'application/json'
                },
                json={
                    'model': self.deepseek_model,
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
                    'model': self.openai_model,
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

    def _categorize_sports(self, title, summary):
        """Categorize sports-source articles with a sports-focused prompt"""
        text = f"{title} {summary}".lower()

        # Build only sports categories for the prompt
        sports_cats = []
        business_cats = []
        for name, data in self.categories_cache.items():
            if data.get('level') != 2:
                continue
            parent_id = data.get('parentID')
            for pname, pdata in self.categories_cache.items():
                if pdata.get('id') == parent_id and pdata.get('level') == 1:
                    if pname == 'Sports':
                        desc = data.get('description', '')
                        sports_cats.append(f"{name}: {desc}" if desc else name)
                    elif pname == 'Business':
                        desc = data.get('description', '')
                        business_cats.append(f"{name}: {desc}" if desc else name)
                    break

        prompt = f"""You are a sports news classifier. Assign 1-2 categories to this sports article.

SPORTS CATEGORIES (use EXACT names before the colon):
{chr(10).join('  - ' + c for c in sorted(sports_cats))}

BUSINESS CATEGORIES (use ONLY if article is primarily about business):
{chr(10).join('  - ' + c for c in sorted(business_cats))}

RULES:
1. Return 1-2 categories maximum. Prefer 1 specific category.
2. NBA: ONLY for NBA league, teams, or players. NOT for college basketball.
3. NFL: ONLY for NFL league, teams, or players. NOT for college football.
4. Golden State Warriors: ONLY for articles specifically about the Warriors.
5. 49ers: ONLY for articles specifically about the 49ers.
6. College Sports: For NCAA/university athletics of any sport.
7. Olympics & International: For Olympic Games, World Cup, international competitions.
8. Professional Sports: For NHL, MLB, MLS, boxing, MMA, UFC, tennis, golf, and pro sports WITHOUT their own category. NEVER combine with NBA or NFL.
9. Sports Business: ONLY for business aspects (contracts, revenue, team sales, labor). NOT for game results or athletic performance.
10. Sports News: General catch-all. Use ONLY if no specific category fits.
11. NEVER assign both a specific league AND Professional Sports.
12. NEVER assign Sports News alongside a more specific category.
13. You may assign one business category (e.g., Markets & Finance, Legal & Regulatory) if the article is genuinely about business.

Article Title: {title}
Article Summary: {summary}

Return ONLY category names separated by commas (1-2 categories):"""

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
                continue

            if result:
                suggested = [cat.strip() for cat in result.split(',')]
                valid = [cat for cat in suggested
                         if cat in self.categories_cache
                         and self.categories_cache[cat].get('level') == 2]
                return valid[:2] if valid else ['Sports News']

        return ['Sports News']

    def categorize_with_ai(self, title, summary, main_category=None):
        """Categorize using AI providers in configured order (only assigns level-2 categories)"""
        if not summary:
            return ['Global Business']

        # For sports sources, use sports-specific categorization
        if main_category == 'Sports':
            return self._categorize_sports(title, summary)

        # Build category list grouped by parent for clarity - only level 2 (assignable) categories
        parent_groups = {}
        for name, data in self.categories_cache.items():
            if data.get('level') != 2:
                continue
            parent_id = data.get('parentID')
            # Find parent name
            parent_name = None
            for pname, pdata in self.categories_cache.items():
                if pdata.get('id') == parent_id and pdata.get('level') == 1:
                    parent_name = pname
                    break
            if parent_name:
                if parent_name not in parent_groups:
                    parent_groups[parent_name] = []
                desc = data.get('description', '')
                parent_groups[parent_name].append(f"{name}: {desc}" if desc else name)

        # Build structured category listing
        category_listing = ""
        for parent in ['Business', 'Technology', 'Sports', 'General']:
            if parent in parent_groups:
                category_listing += f"\n[{parent}]\n"
                for cat in sorted(parent_groups[parent]):
                    category_listing += f"  - {cat}\n"

        prompt = f"""You are a strict news article classifier. Assign 1-3 categories to this article. You MUST follow ALL rules below.

AVAILABLE CATEGORIES (grouped by parent, use ONLY the exact names before the colon):
{category_listing}
STRICT RULES - READ CAREFULLY:

1. RETURN 1-3 CATEGORIES MAXIMUM. Prefer fewer, more accurate categories over more categories.
2. Return ONLY exact category names from the list above, separated by commas.
3. Each article needs at minimum 1 category but no more than 3.

CATEGORY SELECTION RULES:

SPORTS ARTICLES:
- NBA: ONLY for articles specifically about the NBA league or NBA teams/players (Lakers, Celtics, etc.)
- NFL: ONLY for articles specifically about the NFL league or NFL teams/players (Cowboys, Patriots, etc.)
- Golden State Warriors: ONLY for articles specifically about the Warriors team
- 49ers: ONLY for articles specifically about the San Francisco 49ers team
- College Sports: ONLY for NCAA/university athletics
- Olympics & International: ONLY for Olympic Games, World Cup, international competitions
- Professional Sports: Use for NHL, MLB, MLS, boxing, MMA, UFC, tennis, golf, and other pro sports that do NOT have their own category. Do NOT combine with NBA or NFL (they are already professional).
- Sports Business: ONLY when the article focuses on the BUSINESS side of sports (contracts, revenue, team sales, sponsorships, labor disputes). Do NOT use for game results, scores, or athletic performance.
- Sports News: General sports catch-all. Use ONLY if no more specific sports category applies.

BUSINESS ARTICLES:
- Markets & Finance: Stock markets, trading, banking, investment, interest rates, IPOs, earnings reports
- Economy: GDP, inflation, employment data, economic policy, recession, Federal Reserve monetary policy
- Mergers & Acquisitions: Corporate mergers, acquisitions, buyouts, takeovers
- Global Business: International trade, tariffs, geopolitics affecting business, foreign markets
- Leadership: CEO appointments, executive changes, corporate governance
- Legal & Regulatory: Lawsuits, antitrust, regulations, government investigations, compliance
- Startups: New companies, venture capital funding rounds, entrepreneurship
- Retail: Consumer goods, e-commerce, brick-and-mortar retail, consumer spending
- Energy: Oil, gas, renewable energy, utilities, energy policy
- Healthcare: Pharma, biotech, hospitals, health insurance, medical devices, drug approvals
- Media & Entertainment: Streaming, publishing, film, TV, music industry business
- Labor & Workforce: Unions, strikes, layoffs, hiring trends, workplace policy
- Supply Chain: Logistics, shipping, trade routes, supply chain disruptions

TECHNOLOGY ARTICLES:
- Artificial Intelligence & ML: AI models, machine learning, LLMs, ChatGPT, AI companies, AI policy
- Cybersecurity: Hacking, data breaches, security software, encryption, privacy violations
- Cloud & Infrastructure: AWS, Azure, Google Cloud, data centers, server infrastructure
- Hardware & Semiconductors: Chips, processors, NVIDIA, Intel, AMD, chip manufacturing
- Software & Applications: Enterprise software, SaaS, developer tools, app releases
- Consumer Technology: Smartphones, gadgets, apps, social media platforms, consumer electronics
- Blockchain & Crypto: Bitcoin, Ethereum, cryptocurrency exchanges, DeFi, NFTs, blockchain
- Robotics & Automation: Industrial robots, autonomous systems, warehouse automation
- Automotive Technology: Electric vehicles, Tesla, self-driving cars, EV batteries
- Telecom & 5G: Wireless carriers, 5G networks, telecommunications infrastructure

GENERAL NEWS:
- Politics: Government policy, elections, legislation (use ONLY if politics is the primary focus)
- US News: Domestic US events not primarily about business or politics
- World News: International events not primarily about business
- Science: Scientific research, space exploration, discoveries
- Education: Schools, universities, education policy
- Environment & Climate: Climate change, sustainability, environmental regulations

CRITICAL ANTI-OVERLAP RULES:
- NEVER assign both a specific league (NBA, NFL) AND Professional Sports to the same article.
- NEVER assign Sports News alongside a more specific sports category.
- NEVER assign categories from different parent groups unless the article genuinely spans both (e.g., a tech company earnings report could be Markets & Finance + Artificial Intelligence & ML).
- An article about an NBA team's finances gets NBA + Sports Business, NOT NBA + Professional Sports + Sports Business + Sports News.
- An article about Olympic skiing gets Olympics & International, NOT Olympics & International + Professional Sports + Sports News.

Article Title: {title}
Article Summary: {summary}

Return ONLY the category names separated by commas (1-3 categories, exact names only):"""

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
                # Only accept level-2 categories
                valid = [cat for cat in suggested
                         if cat in self.categories_cache
                         and self.categories_cache[cat].get('level') == 2]
                return valid[:3] if valid else ['Global Business']

        return ['Global Business']

    def is_cookie_consent_content(self, content):
        """Detect if content is primarily cookie consent/privacy policy text instead of article content"""
        if not content:
            return False

        content_lower = content.lower()

        # Strong indicators - directly cookie/consent related, unlikely in real articles
        strong_patterns = [
            'cookie consent', 'cookie policy', 'cookie settings', 'cookie preferences',
            'manage cookies', 'accept cookies', 'reject cookies', 'accept all cookies',
            'we use cookies', 'this site uses cookies', 'uses cookies to',
            'consent to the use', 'consent preferences',
            'third-party cookies', 'third party cookies',
            'strictly necessary cookies', 'functional cookies',
            'performance cookies', 'targeting cookies', 'advertising cookies',
            'analytics cookies',
            # Region blocks and access restrictions
            'content not available in your region',
            'not available in your location',
            'access denied from your location',
            'content is not available in',
            'service is not available',
            'this content is currently unavailable',
            'yahoo is part of the yahoo family',
            'oath and our partners',
        ]

        # Weaker indicators - may appear in legitimate privacy-related articles
        weak_patterns = [
            'privacy policy notice', 'privacy settings', 'your privacy choices',
            'your privacy rights', 'manage your privacy',
            'tracking technology', 'tracking technologies',
            'opt-out instructions', 'opt out of',
            'data processing', 'personal data',
            'gdpr', 'ccpa', 'california consumer privacy',
            'legitimate interest', 'legitimate business interest',
            'continue to yahoo',
            'sign in to continue',
            'create an account',
        ]

        strong_matches = sum(1 for p in strong_patterns if p in content_lower)
        weak_matches = sum(1 for p in weak_patterns if p in content_lower)

        # Any 2+ strong matches = cookie content
        if strong_matches >= 2:
            return True

        # 1 strong + 2 weak = cookie content
        if strong_matches >= 1 and weak_matches >= 2:
            return True

        # For short content (<2000 chars), 1 strong + 1 weak is suspicious
        if len(content) < 2000 and strong_matches >= 1 and weak_matches >= 1:
            return True

        return False

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

    def process_article(self, article, counter=""):
        """Process a single article"""
        try:
            retry_count = article.get('summary_retry_count', 0)
            retry_note = f" (retry {retry_count})" if retry_count > 0 else ""
            source_name = article.get('source_name', 'Unknown')
            counter_str = f"[{counter}] " if counter else ""
            print(f"{counter_str}[{source_name}] {article['title'][:50]}...{retry_note}")

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
                # Fallback to existing fullArticle, but check for cookie content
                if self.is_cookie_consent_content(content):
                    print(f"  ⊘ Existing fullArticle is cookie consent/privacy policy content")
                    self.mark_article_failed(article['id'], retry_count)
                    return False
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

            # Categorize with source awareness
            categories = self.categorize_with_ai(article['title'], summary, article.get('mainCategory'))

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

    def run(self, batch_size=30, max_workers=5):
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
        total_articles = len(articles)

        with ThreadPoolExecutor(max_workers=max_workers) as executor:
            futures = {executor.submit(self.process_article, article, f"{i+1}/{total_articles}"): article for i, article in enumerate(articles)}

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
    batch_size = int(sys.argv[1]) if len(sys.argv) > 1 else 30
    max_workers = int(sys.argv[2]) if len(sys.argv) > 2 else 5

    summarizer = ParallelSummarizer()
    summarizer.run(batch_size, max_workers)
