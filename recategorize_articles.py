#!/usr/bin/env python3
"""
Recategorize Articles Script
Re-categorizes articles in a given level-1 category tree using AI,
based on their existing summaries.

Usage:
    python3 recategorize_articles.py <level1_category_id> [--dry-run] [--days N]

Examples:
    python3 recategorize_articles.py 24          # Recategorize Business articles from last 1 day
    python3 recategorize_articles.py 25 --dry-run  # Preview Technology recategorization
    python3 recategorize_articles.py 26 --days 3   # Recategorize Sports articles from last 3 days
"""

import os
import sys
import argparse
import requests
import mysql.connector
from mysql.connector import Error
import env_loader  # Auto-loads .env and ~/.env_AI
import time


class ArticleRecategorizer:
    def __init__(self):
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

        # API Keys
        self.minai_key = os.getenv('MINAI_API_KEY')
        self.anthropic_key = os.getenv('ANTHROPIC_API_KEY')
        self.deepseek_key = os.getenv('DEEPSEEK_API_KEY')
        self.openai_key = os.getenv('OPENAI_API_KEY')

        # Model configuration
        self.anthropic_model = os.getenv('ANTHROPIC_MODEL', 'claude-sonnet-4-6')
        self.deepseek_model = os.getenv('DEEPSEEK_MODEL', 'deepseek-chat')
        self.openai_model = os.getenv('OPENAI_MODEL', 'gpt-4o-mini')

        print(f"AI Provider Order: {' -> '.join(self.provider_order)}")

        if not any([self.minai_key, self.anthropic_key, self.deepseek_key, self.openai_key]):
            print("ERROR: No API keys found!")
            sys.exit(1)

        self.connection = None
        self.categories_cache = {}

    def connect_db(self):
        """Establish database connection"""
        try:
            self.connection = mysql.connector.connect(**self.db_config)
            if self.connection.is_connected():
                cursor = self.connection.cursor()
                cursor.execute("SET time_zone = '-08:00'")
                cursor.close()
                print("Connected to MySQL database")
                self.load_categories()
                return True
        except Error as e:
            print(f"Error connecting to MySQL: {e}")
            return False

    def load_categories(self):
        """Load categories into cache"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("SELECT id, name, description, level, parentID FROM categories")
        for row in cursor.fetchall():
            self.categories_cache[row['name']] = row
        cursor.close()
        level2_count = sum(1 for c in self.categories_cache.values() if c.get('level') == 2)
        print(f"Loaded {len(self.categories_cache)} categories ({level2_count} assignable)")

    def get_parent_category(self, parent_id):
        """Get parent category info by ID"""
        for name, data in self.categories_cache.items():
            if data['id'] == parent_id and data.get('level') == 1:
                return data
        return None

    def get_articles_in_category_tree(self, parent_id, days=1):
        """Find articles from the last N days that belong to the given category tree"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT a.id, a.title, a.summary, s.mainCategory, a.scraped_at
            FROM articles a
            JOIN article_categories ac ON a.id = ac.article_id
            JOIN categories c ON ac.category_id = c.id AND c.level = 2
            LEFT JOIN sources s ON a.source_id = s.id
            WHERE c.parentID = %s
            AND a.scraped_at >= DATE_SUB(NOW(), INTERVAL %s DAY)
            AND a.summary IS NOT NULL AND a.summary != ''
            GROUP BY a.id
            ORDER BY a.scraped_at DESC
        """, (parent_id, days))
        articles = cursor.fetchall()
        cursor.close()
        return articles

    def get_current_categories(self, article_id):
        """Get current category assignments for an article"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT c.id, c.name, c.level, c.parentID
            FROM article_categories ac
            JOIN categories c ON ac.category_id = c.id
            WHERE ac.article_id = %s
        """, (article_id,))
        categories = cursor.fetchall()
        cursor.close()
        return categories

    # ---- AI provider methods (same as summarizer_parallel.py) ----

    def call_minai(self, prompt, max_tokens=50):
        """Call 1min.ai API"""
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
                if 'aiRecord' in result:
                    ai_record = result['aiRecord']
                    if 'aiRecordDetail' in ai_record:
                        detail = ai_record['aiRecordDetail']
                        if 'resultObject' in detail:
                            result_obj = detail['resultObject']
                            if isinstance(result_obj, list) and len(result_obj) > 0:
                                return result_obj[0].strip()
            return None
        except Exception:
            return None

    def call_anthropic(self, prompt, max_tokens=50):
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
        except Exception:
            return None

    def call_deepseek(self, prompt, max_tokens=50):
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
                        {'role': 'user', 'content': prompt}
                    ],
                    'temperature': 0.3,
                    'max_tokens': max_tokens
                },
                timeout=30
            )
            if response.status_code == 200:
                return response.json()['choices'][0]['message']['content'].strip()
            return None
        except Exception:
            return None

    def call_openai(self, prompt, max_tokens=50):
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
                        {'role': 'user', 'content': prompt}
                    ],
                    'temperature': 0.3,
                    'max_tokens': max_tokens
                },
                timeout=30
            )
            if response.status_code == 200:
                return response.json()['choices'][0]['message']['content'].strip()
            return None
        except Exception:
            return None

    def _categorize_sports(self, title, summary):
        """Categorize sports-source articles with a sports-focused prompt"""
        # Build only sports and business categories for the prompt
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
9. Sports Business: ONLY for business aspects (contracts, revenue, team sales, sponsorships, labor). NOT for game results or athletic performance.
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
        """Categorize using AI providers in configured order (only assigns level-2 categories).
        Uses the same improved prompt logic as summarizer_parallel.py."""
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
                return valid[:3] if valid else ['Global Business']

        return ['Global Business']

    def update_article_categories(self, article_id, new_categories):
        """Remove old category assignments and insert new ones"""
        cursor = self.connection.cursor()
        # Delete existing category assignments
        cursor.execute("DELETE FROM article_categories WHERE article_id = %s", (article_id,))
        # Insert new ones
        for category_name in new_categories:
            if category_name in self.categories_cache:
                category_id = self.categories_cache[category_name]['id']
                cursor.execute(
                    "INSERT IGNORE INTO article_categories (article_id, category_id) VALUES (%s, %s)",
                    (article_id, category_id)
                )
        self.connection.commit()
        cursor.close()

    def run(self, parent_id, days=1, dry_run=False):
        """Run recategorization for a given parent category tree"""
        print("=" * 60)
        print("Article Recategorizer")
        print("=" * 60)

        if not self.connect_db():
            return

        parent = self.get_parent_category(parent_id)
        if not parent:
            print(f"ERROR: No level-1 category found with ID {parent_id}")
            print("\nAvailable level-1 categories:")
            for name, data in sorted(self.categories_cache.items(), key=lambda x: x[1]['id']):
                if data.get('level') == 1:
                    print(f"  [{data['id']}] {name}")
            return

        print(f"Parent category: [{parent['id']}] {parent['name']}")
        print(f"Lookback period: {days} day(s)")
        if dry_run:
            print("MODE: DRY RUN (no changes will be made)")
        print()

        articles = self.get_articles_in_category_tree(parent_id, days)
        if not articles:
            print(f"No articles found in '{parent['name']}' category tree from the last {days} day(s)")
            return

        print(f"Found {len(articles)} articles to recategorize\n")

        changed = 0
        unchanged = 0
        errors = 0
        start_time = time.time()

        for i, article in enumerate(articles, 1):
            title_short = article['title'][:70]
            print(f"[{i}/{len(articles)}] {title_short}...")

            # Get current categories
            current_cats = self.get_current_categories(article['id'])
            current_names = sorted([c['name'] for c in current_cats if c.get('level') == 2])

            # Get new AI categorization based on summary
            try:
                new_names = self.categorize_with_ai(
                    article['title'],
                    article['summary'],
                    article.get('mainCategory')
                )
            except Exception as e:
                print(f"  ERROR: {e}")
                errors += 1
                continue

            new_names_sorted = sorted(new_names)

            if current_names == new_names_sorted:
                print(f"  UNCHANGED: {', '.join(current_names)}")
                unchanged += 1
            else:
                print(f"  OLD: {', '.join(current_names)}")
                print(f"  NEW: {', '.join(new_names_sorted)}")
                if not dry_run:
                    try:
                        self.update_article_categories(article['id'], new_names)
                        print(f"  UPDATED")
                    except Exception as e:
                        print(f"  ERROR updating: {e}")
                        errors += 1
                        continue
                else:
                    print(f"  WOULD UPDATE (dry run)")
                changed += 1

        elapsed = time.time() - start_time
        print("\n" + "=" * 60)
        print(f"Recategorization complete in {elapsed:.1f}s")
        print(f"  Total:     {len(articles)}")
        print(f"  Changed:   {changed}")
        print(f"  Unchanged: {unchanged}")
        print(f"  Errors:    {errors}")
        if dry_run:
            print(f"  (DRY RUN - no changes were saved)")
        print("=" * 60)

        if self.connection and self.connection.is_connected():
            self.connection.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Recategorize articles in a level-1 category tree using AI",
        epilog="Examples:\n"
               "  python3 recategorize_articles.py 24            # Business\n"
               "  python3 recategorize_articles.py 25 --dry-run  # Technology (preview)\n"
               "  python3 recategorize_articles.py 26 --days 3   # Sports, last 3 days\n",
        formatter_class=argparse.RawDescriptionHelpFormatter
    )
    parser.add_argument("category_id", type=int, help="Level-1 parent category ID")
    parser.add_argument("--dry-run", action="store_true", help="Preview changes without saving")
    parser.add_argument("--days", type=int, default=1, help="Number of days to look back (default: 1)")

    args = parser.parse_args()

    recategorizer = ArticleRecategorizer()
    recategorizer.run(args.category_id, days=args.days, dry_run=args.dry_run)
