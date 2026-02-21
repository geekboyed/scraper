#!/usr/bin/env python3
"""
freebie Guy Custom Scraper - Uses Playwright for JavaScript rendering
Extracts freebies and deals, saves to deals table
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader

import mysql.connector
from mysql.connector import Error
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
import hashlib
import re
from datetime import datetime

class FreebieGuyScraper:
    def __init__(self):
        self.source_id = 51  # freebie Guy source ID
        self.base_url = "https://thefreebieguy.com"
        self.deals_url = "https://thefreebieguy.com/"

        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS'),
            'connect_timeout': 10,
            'autocommit': False
        }

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

    # Category mappings for deal subcategorization
    CATEGORY_KEYWORDS = {
        50: [  # Electronics
            'phone', 'laptop', 'tablet', 'tv', 'computer', 'headphone',
            'camera', 'gaming', 'console', 'speaker', 'watch', 'tech',
            'electronics', 'gadget'
        ],
        49: [  # Food
            'food', 'snack', 'cereal', 'coffee', 'grocery', 'meal',
            'kitchen', 'cookware', 'appliance', 'restaurant', 'drink',
            'sample', 'free food'
        ],
        52: [  # Gardening
            'garden', 'plant', 'lawn', 'outdoor', 'patio', 'grill',
            'bbq', 'seed', 'tool',
        ],
    }
    DEFAULT_CATEGORY = 48  # General Deals

    def categorize_deal(self, product_name):
        """Determine deal category based on product name keywords.

        Returns the category_id matching the first keyword found,
        or DEFAULT_CATEGORY (General Deals) if none match.
        """
        name_lower = product_name.lower()
        for category_id, keywords in self.CATEGORY_KEYWORDS.items():
            if any(kw in name_lower for kw in keywords):
                return category_id
        return self.DEFAULT_CATEGORY

    def generate_hash(self, text):
        """Generate hash for duplicate detection"""
        return int(hashlib.md5(text.encode()).hexdigest()[:16], 16)

    def extract_price(self, text):
        """Extract numeric price from text like '$14.99', 'Only $7.xx', or 'FREE'"""
        if not text:
            return None
        # Handle FREE items
        if 'free' in text.lower():
            return 0.00

        # Handle ".xx" format (variable pricing) - extract base price
        # Examples: "$7.xx", "Only $11.xx", "Just $15.xx"
        match = re.search(r'\$(\d+)\.xx', text, re.IGNORECASE)
        if match:
            return float(match.group(1))

        # Handle regular prices: "$14.99", "Only $20", "From $15"
        match = re.search(r'\$(\d+(?:\.\d{2})?)', text)
        return float(match.group(1)) if match else None

    def extract_discount(self, text):
        """Extract discount percentage from text"""
        if not text:
            return None
        match = re.search(r'(\\d+(?:\\.\\d+)?)\\s*%', text)
        return int(match.group(1)) if match else None

    def scrape_deals(self):
        """Scrape deals using Playwright"""
        deals = []

        print(f"\\n{'='*60}")
        print(f"freebie Guy Scraper (Playwright)")
        print(f"{'='*60}\\n")

        try:
            with sync_playwright() as p:
                print("🌐 Launching browser...")
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox']
                )

                page = browser.new_page(
                    user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    viewport={'width': 1920, 'height': 1080}
                )

                print(f"📡 Loading {self.deals_url}...")
                page.goto(self.deals_url, wait_until='domcontentloaded', timeout=60000)

                # Wait for content
                print("⏳ Waiting for deals to render...")
                try:
                    page.wait_for_selector('article, .entry, .post', timeout=10000)
                except:
                    print("  ⚠ Article selector timeout, continuing...")

                page.wait_for_timeout(3000)

                print("🔍 Extracting deals from page...")

                # WordPress typically uses article elements
                deal_elements = page.query_selector_all('article.entry, article.post')

                if not deal_elements:
                    print("  ✗ No deal elements found")
                    browser.close()
                    return deals

                print(f"\\n📦 Processing {len(deal_elements)} potential deals...\\n")

                for idx, element in enumerate(deal_elements[:50], 1):
                    try:
                        # Get deal title from h2 or h3 in header
                        title = None
                        for selector in ['h2.entry-title a', 'h3.entry-title a', 'h2 a', 'h3 a', '.entry-title']:
                            title_elem = element.query_selector(selector)
                            if title_elem:
                                title = title_elem.inner_text().strip()
                                break

                        # Get URL
                        deal_url = None
                        link_elem = element.query_selector('h2.entry-title a, h3.entry-title a, a.entry-title-link')
                        if link_elem:
                            href = link_elem.get_attribute('href')
                            if href:
                                deal_url = href if href.startswith('http') else (self.base_url + href)

                        # Get image
                        img_elem = element.query_selector('img.wp-post-image, img')
                        image_url = None
                        if img_elem:
                            image_url = img_elem.get_attribute('src')
                            if image_url and not image_url.startswith('http'):
                                image_url = 'https:' + image_url if image_url.startswith('//') else (self.base_url + image_url)

                        # Get excerpt/description
                        desc_elem = element.query_selector('.entry-summary, .entry-content, p')
                        description = desc_elem.inner_text().strip()[:500] if desc_elem else None

                        # Try to extract price from title or description
                        price = None
                        discount_pct = None
                        price_text = None

                        if title:
                            # Try to extract price
                            price = self.extract_price(title)
                            discount_pct = self.extract_discount(title)

                            # Set price_text
                            if price is not None:
                                if price == 0.00:
                                    price_text = "FREE"
                                else:
                                    price_text = f"${price:.2f}"

                        # Skip if no title
                        if not title:
                            continue

                        # Skip very short titles (likely UI elements)
                        if len(title) < 10:
                            continue

                        deal = {
                            'product_name': title[:500],
                            'deal_url': deal_url,
                            'discount_percentage': discount_pct,
                            'description': description or f"{title} - Free sample or deal",
                            'image_url': image_url,
                            'deal_type': 'freebie',
                            'sale_price': price,
                            'price_text': price_text
                        }

                        deals.append(deal)

                        print(f"[{idx}] ✓ {title[:60]}...")
                        if price is not None:
                            print(f"     💵 {'FREE' if price == 0 else f'${price:.2f}'}")
                        if discount_pct:
                            print(f"     💰 {discount_pct}% off")

                    except Exception as e:
                        continue

                browser.close()

        except Exception as e:
            print(f"✗ Scraping error: {e}")
            return deals

        print(f"\\n✓ Extracted {len(deals)} deals from freebie Guy\\n")
        return deals

    def save_deals(self, deals):
        """Save deals to database"""
        if not deals:
            print("No deals to save")
            return 0

        print(f"💾 Saving {len(deals)} deals...\\n")
        saved = 0

        try:
            cursor = self.connection.cursor()

            for idx, deal in enumerate(deals, 1):
                try:
                    hash_text = f"{deal['product_name']}{deal.get('deal_url', '')}"
                    content_hash = self.generate_hash(hash_text)

                    # Check duplicates
                    cursor.execute("SELECT id FROM deals WHERE content_hash = %s", (content_hash,))
                    if cursor.fetchone():
                        print(f"[{idx}/{len(deals)}] ⊘ Duplicate")
                        continue

                    # Insert
                    cursor.execute("""
                        INSERT INTO deals
                        (source_id, product_name, deal_url, price, price_text,
                         discount_percentage, description, image_url, deal_type, content_hash, scraped_at)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                    """, (
                        self.source_id,
                        deal['product_name'],
                        deal.get('deal_url'),
                        deal.get('sale_price'),
                        deal.get('price_text'),
                        deal.get('discount_percentage'),
                        deal.get('description'),
                        deal.get('image_url'),
                        deal.get('deal_type', 'freebie'),
                        content_hash
                    ))

                    deal_id = cursor.lastrowid

                    # Categorize based on product name keywords
                    category_id = self.categorize_deal(deal['product_name'])
                    cursor.execute(
                        "INSERT IGNORE INTO deal_categories (deal_id, category_id) VALUES (%s, %s)",
                        (deal_id, category_id)
                    )

                    self.connection.commit()
                    saved += 1
                    print(f"[{idx}/{len(deals)}] ✓ Saved")

                except Exception as e:
                    self.connection.rollback()
                    continue

            cursor.close()

        except Exception as e:
            print(f"✗ Database error: {e}")
            return saved

        print(f"\\n{'='*60}")
        print(f"✓ Saved {saved} new deals")
        print(f"{'='*60}\\n")

        return saved

    def run(self):
        """Main execution"""
        if not self.connect_db():
            return

        deals = self.scrape_deals()
        saved = self.save_deals(deals)

        if self.connection and self.connection.is_connected():
            self.connection.close()

        return saved

if __name__ == "__main__":
    scraper = FreebieGuyScraper()
    scraper.run()
