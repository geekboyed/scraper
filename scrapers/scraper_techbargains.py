#!/usr/bin/env python3
"""
Tech Bargains Custom Scraper - Uses Playwright for Cloudflare bypass
Extracts tech deals, saves to deals table
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

class TechBargainsScraper:
    def __init__(self):
        self.source_id = 52  # Tech Bargains source ID
        self.base_url = "https://www.techbargains.com"
        self.deals_url = "https://www.techbargains.com/"

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

    # Category mappings - Tech Bargains is primarily electronics
    CATEGORY_KEYWORDS = {
        50: [  # Electronics - primary category for this site
            'phone', 'laptop', 'tablet', 'tv', 'computer', 'headphone',
            'camera', 'gaming', 'console', 'speaker', 'watch', 'tech',
            'electronics', 'gadget', 'monitor', 'keyboard', 'mouse',
            'ssd', 'hard drive', 'ram', 'gpu', 'cpu', 'router'
        ],
        49: [  # Food (rare on this site)
            'food', 'snack', 'kitchen', 'appliance',
        ],
        52: [  # Gardening (rare on this site)
            'garden', 'outdoor', 'tool',
        ],
    }
    DEFAULT_CATEGORY = 50  # Electronics (default for Tech Bargains)

    def categorize_deal(self, product_name):
        """Determine deal category based on product name keywords.

        Returns the category_id matching the first keyword found,
        or DEFAULT_CATEGORY (Electronics) if none match.
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
        """Extract numeric price from text like '$14.99' or '$499' at the END of the string"""
        if not text:
            return None
        # Look for price at the end: "$49.99", "$1,299", "$499"
        match = re.search(r'\$(\d+(?:,\d{3})*(?:\.\d{2})?)\s*$', text)
        if match:
            return float(match.group(1).replace(',', ''))
        # Try without decimal if not found
        match = re.search(r'\$(\d+(?:,\d{3})*)\s*$', text)
        if match:
            return float(match.group(1).replace(',', ''))
        return None

    def extract_discount(self, text):
        """Extract discount percentage from text"""
        if not text:
            return None
        match = re.search(r'(\\d+(?:\\.\\d+)?)\\s*%\\s*off', text, re.IGNORECASE)
        return int(match.group(1)) if match else None

    def scrape_deals(self):
        """Scrape deals using Playwright"""
        deals = []

        print(f"\\n{'='*60}")
        print(f"Tech Bargains Scraper (Playwright)")
        print(f"{'='*60}\\n")

        try:
            with sync_playwright() as p:
                print("🌐 Launching browser...")
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox', '--disable-blink-features=AutomationControlled']
                )

                context = browser.new_context(
                    user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    viewport={'width': 1920, 'height': 1080}
                )

                page = context.new_page()

                print(f"📡 Loading {self.deals_url}...")
                print("  ⏳ Waiting for Cloudflare challenge...")

                try:
                    page.goto(self.deals_url, wait_until='domcontentloaded', timeout=90000)

                    # Wait extra time for Cloudflare to complete
                    page.wait_for_timeout(10000)

                    # Check if we passed Cloudflare
                    if 'Just a moment' in page.content():
                        print("  ⚠ Cloudflare challenge still active, waiting longer...")
                        page.wait_for_timeout(15000)

                    print("⏳ Waiting for deals to render...")
                    # Try multiple selectors for deal content
                    try:
                        page.wait_for_selector('.deal, .item, article, [class*=\"deal\"]', timeout=15000)
                    except:
                        print("  ⚠ Deal selector timeout, continuing...")

                    page.wait_for_timeout(3000)

                    print("🔍 Extracting deals from page...")

                    # Tech Bargains uses: <div class="row deal bg-white...">
                    deal_elements = page.query_selector_all('div.deal.bg-white')

                    if deal_elements:
                        print(f"  → Found {len(deal_elements)} deal elements")

                    if not deal_elements:
                        print("  ✗ No deal elements found")
                        print("  📄 Page content preview:")
                        content = page.content()[:1000]
                        print(f"    {content}")
                        browser.close()
                        return deals

                    print(f"\\n📦 Processing {len(deal_elements)} potential deals...\\n")

                    for idx, element in enumerate(deal_elements[:50], 1):
                        try:
                            # Get deal title from h3 (includes price in title)
                            title_elem = element.query_selector('h3')
                            if not title_elem:
                                continue

                            full_title = title_elem.inner_text().strip()
                            if not full_title or len(full_title) < 10:
                                continue

                            # The title has price embedded, e.g., "Product Name $49.99"
                            title = full_title

                            # Get URL from h3 > a
                            link_elem = title_elem.query_selector('a')
                            deal_url = None
                            if link_elem:
                                href = link_elem.get_attribute('href')
                                if href:
                                    deal_url = href if href.startswith('http') else (self.base_url + href)

                            # Extract price from title (embedded at the end)
                            # Format: "Product Name $49.99" or "Product Name $1,299.99"
                            price = self.extract_price(full_title)
                            price_text = None
                            if price:
                                price_text = f"${price:.2f}"

                            # Look for discount percentage
                            discount_pct = self.extract_discount(full_title)

                            # Get image
                            img_elem = element.query_selector('img')
                            image_url = None
                            if img_elem:
                                image_url = img_elem.get_attribute('src') or img_elem.get_attribute('data-src')
                                if image_url and not image_url.startswith('http'):
                                    image_url = 'https:' + image_url if image_url.startswith('//') else (self.base_url + image_url)

                            # Get description
                            description = None
                            desc_elem = element.query_selector('.description, .excerpt, p')
                            if desc_elem:
                                description = desc_elem.inner_text().strip()[:500]

                            # Skip if no title
                            if not title:
                                continue

                            # Skip very short titles
                            if len(title) < 10:
                                continue

                            # Skip if no price found
                            if not price and not price_text:
                                continue

                            deal = {
                                'product_name': title[:500],
                                'deal_url': deal_url,
                                'discount_percentage': discount_pct,
                                'description': description or f"{title} tech deal",
                                'image_url': image_url,
                                'deal_type': 'tech_deal',
                                'sale_price': price,
                                'price_text': price_text
                            }

                            deals.append(deal)

                            print(f"[{idx}] ✓ {title[:60]}...")
                            if price:
                                print(f"     💵 ${price:.2f}")
                            elif price_text:
                                print(f"     💵 {price_text}")
                            if discount_pct:
                                print(f"     💰 {discount_pct}% off")

                        except Exception as e:
                            continue

                    browser.close()

                except PlaywrightTimeout:
                    print("  ✗ Page load timeout - Cloudflare may be blocking")
                    browser.close()
                    return deals

        except Exception as e:
            print(f"✗ Scraping error: {e}")
            return deals

        print(f"\\n✓ Extracted {len(deals)} deals from Tech Bargains\\n")
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
                        deal.get('deal_type', 'tech_deal'),
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
    scraper = TechBargainsScraper()
    scraper.run()
