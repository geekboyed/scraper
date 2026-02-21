#!/usr/bin/env python3
"""
Fix Bad Article Titles Using AI
Detects photographer credits and other bad titles, generates proper titles from summaries
"""

import os
import sys
import mysql.connector
from mysql.connector import Error
import env_loader
import requests

class TitleFixer:
    def __init__(self):
        # Database configuration
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        # Load AI provider order
        provider_order = os.getenv('AI_PROVIDER_ORDER', 'deepseek,anthropic').split(',')
        self.provider_order = [p.strip() for p in provider_order]

        # API Keys
        self.deepseek_key = os.getenv('DEEPSEEK_API_KEY')
        self.anthropic_key = os.getenv('ANTHROPIC_API_KEY')

        # Models
        self.deepseek_model = os.getenv('DEEPSEEK_MODEL', 'deepseek-chat')
        self.anthropic_model = os.getenv('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001')

        print(f"✓ AI Provider Order: {' → '.join(self.provider_order)}")
        if self.deepseek_key:
            print(f"✓ DeepSeek configured ({self.deepseek_model})")
        if self.anthropic_key:
            print(f"✓ Anthropic configured ({self.anthropic_model})")

        self.connection = None

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

    def is_bad_title(self, title):
        """
        Detect if title is likely a photographer credit or other bad title
        """
        if not title:
            return True

        title_lower = title.lower()

        # Photographer credit patterns
        photo_patterns = [
            'getty images',
            '/getty',
            '/ap',
            '/afp',
            '/reuters',
            'washington post/getty',
            '/bloomberg',
            '/invision',
            'cnn',  # Title ending with just "CNN"
            'the associated press',
            'sipa usa',
        ]

        # Check if title is likely a photo credit
        for pattern in photo_patterns:
            if pattern in title_lower:
                # If title is mostly photo credits (less than 30 chars with photo pattern)
                if len(title) < 50 or title.count('/') >= 2:
                    return True

        # Very short titles (likely incomplete)
        if len(title) < 15:
            return True

        return False

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
                        {'role': 'system', 'content': 'You are a headline writer. Create clear, engaging news headlines.'},
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
            else:
                return None
        except Exception as e:
            return None

    def generate_title(self, summary, old_title=None):
        """Generate a proper title from summary using AI"""
        if not summary or len(summary) < 50:
            return None

        prompt = f"""Based on this article summary, write a clear, concise news headline (under 100 characters).
The headline should capture the main news point.

Summary:
{summary[:500]}

Write ONLY the headline, nothing else:"""

        # Try providers in order
        for provider in self.provider_order:
            result = None

            if provider == 'deepseek' and self.deepseek_key:
                result = self.call_deepseek(prompt, max_tokens=50)
                provider_name = "DeepSeek"
            elif provider == 'anthropic' and self.anthropic_key:
                result = self.call_anthropic(prompt, max_tokens=50)
                provider_name = "Claude"
            else:
                continue

            if result:
                # Clean up the result
                result = result.strip('"').strip("'").strip()

                # Validate length
                if len(result) > 15 and len(result) < 200:
                    print(f"  ✓ {provider_name} generated title")
                    return result
                else:
                    print(f"  ⚠ {provider_name} title invalid length ({len(result)} chars)")
                    continue

        return None

    def get_bad_title_articles(self, limit=50):
        """Get articles with bad titles that have summaries"""
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, title, summary, url
            FROM articles
            WHERE summary IS NOT NULL
            AND summary != ''
            AND LENGTH(summary) > 100
            ORDER BY scraped_at DESC
            LIMIT %s
        """, (limit * 3,))  # Get more to filter

        articles = cursor.fetchall()
        cursor.close()

        # Filter for bad titles
        bad_title_articles = []
        for article in articles:
            if self.is_bad_title(article['title']):
                bad_title_articles.append(article)
                if len(bad_title_articles) >= limit:
                    break

        return bad_title_articles

    def update_title(self, article_id, new_title):
        """Update article title in database"""
        cursor = self.connection.cursor()
        cursor.execute("""
            UPDATE articles
            SET title = %s
            WHERE id = %s
        """, (new_title, article_id))
        self.connection.commit()
        cursor.close()

    def run(self, limit=20):
        """Run title fixing"""
        print("=" * 80)
        print("Bad Title Fixer (AI-Powered)")
        print("=" * 80)

        if not self.connect_db():
            return

        articles = self.get_bad_title_articles(limit)

        if not articles:
            print("\n✓ No articles with bad titles found!")
            return

        print(f"\nFound {len(articles)} articles with bad titles")
        print("-" * 80)

        fixed = 0
        skipped = 0

        for article in articles:
            article_id = article['id']
            old_title = article['title']
            summary = article['summary']

            print(f"\n[{article_id}]")
            print(f"  OLD: {old_title}")

            # Generate new title
            new_title = self.generate_title(summary, old_title)

            if not new_title:
                print(f"  ✗ Failed to generate title")
                skipped += 1
                continue

            # Update database
            self.update_title(article_id, new_title)
            print(f"  NEW: {new_title}")
            fixed += 1

        print("\n" + "=" * 80)
        print(f"✓ Fixed: {fixed} titles")
        print(f"  Skipped: {skipped} titles")
        print("=" * 80)

        if self.connection and self.connection.is_connected():
            self.connection.close()

if __name__ == "__main__":
    limit = int(sys.argv[1]) if len(sys.argv) > 1 else 20

    fixer = TitleFixer()
    fixer.run(limit)
