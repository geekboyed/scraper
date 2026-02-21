#!/usr/bin/env python3
"""
Slickdeals Scraper - Uses Playwright for JavaScript rendering
Extracts deals from main grid (185+) and sidebar popular deals (10)
Saves to deals table with full metadata (votes, comments, prices, store, timestamps)
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI

import mysql.connector
from mysql.connector import Error
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout
from datetime import datetime
import hashlib
import re
import logging

# Set up logging
LOG_DIR = '/var/www/html/scraper/logs'
os.makedirs(LOG_DIR, exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, 'scraper_slickdeals.log')),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)


class SlickdealsScraper:
    def __init__(self):
        self.source_id = 49  # Slickdeals source ID in sources table
        self.source_name = "Slickdeals"
        self.base_url = "https://www.slickdeals.net"

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
                logger.info("Connected to MySQL database")
                return True
        except Error as e:
            logger.error(f"Error connecting to MySQL: {e}")
            return False

    # Category mappings for deal subcategorization
    CATEGORY_KEYWORDS = {
        50: [  # Electronics
            'phone', 'laptop', 'tablet', 'tv', 'computer', 'headphone',
            'camera', 'gaming', 'console', 'speaker', 'watch', 'tech',
        ],
        49: [  # Food
            'food', 'snack', 'cereal', 'coffee', 'grocery', 'meal',
            'restaurant', 'burger', 'pizza', 'kitchen', 'cookware', 'appliance',
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

    def extract_price(self, price_text):
        """Extract numeric price from text like '$99.99' or '$1,299.00'"""
        if not price_text:
            return None
        cleaned = price_text.replace(',', '').strip()
        match = re.search(r'\$\s*(\d+(?:\.\d{1,2})?)', cleaned)
        return float(match.group(1)) if match else None

    def extract_int(self, text):
        """Extract integer from text, handling commas (e.g. '1,234' -> 1234)"""
        if not text:
            return None
        cleaned = text.replace(',', '').strip()
        match = re.search(r'(\d+)', cleaned)
        return int(match.group(1)) if match else None

    def extract_deal_id_from_url(self, url):
        """Extract Slickdeals thread ID from URL like /f/19230157-some-deal-title"""
        if not url:
            return None
        match = re.search(r'/f/(\d+)', url)
        return match.group(1) if match else None

    def make_absolute_url(self, href):
        """Convert relative URL to absolute"""
        if not href:
            return None
        if href.startswith('http'):
            return href
        return self.base_url + href

    def scrape_main_deals(self, page):
        """Scrape main deal grid from homepage"""
        deals = []

        logger.info("Scraping main deal grid...")

        try:
            main_items = page.query_selector_all(
                'ul.frontpageGrid.frontpageSlickdealsGrid li.frontpageGrid__feedItem'
            )
            logger.info(f"Found {len(main_items)} items in main grid")

            for idx, item in enumerate(main_items, 1):
                try:
                    card = item.query_selector('.dealCard')
                    if not card:
                        continue

                    # --- Title ---
                    title_elem = card.query_selector('a.dealCard__title')
                    title = title_elem.inner_text().strip() if title_elem else None
                    if not title:
                        continue

                    # --- URL ---
                    href = title_elem.get_attribute('href') if title_elem else None
                    deal_url = self.make_absolute_url(href)
                    if not deal_url:
                        continue

                    # --- Source deal ID from data attribute or URL ---
                    source_deal_id = card.get_attribute('data-threadid')
                    if not source_deal_id:
                        source_deal_id = self.extract_deal_id_from_url(href)

                    # --- Image ---
                    img_elem = card.query_selector('img.dealCard__image')
                    image_url = img_elem.get_attribute('src') if img_elem else None

                    # --- Price ---
                    price = None
                    price_text = None
                    price_elem = card.query_selector('.dealCard__price')
                    if price_elem:
                        price_text = price_elem.inner_text().strip()
                        price = self.extract_price(price_text)

                    # --- Original price ---
                    original_price = None
                    orig_elem = card.query_selector('.dealCard__originalPrice')
                    if orig_elem:
                        orig_text = orig_elem.inner_text().strip()
                        original_price = self.extract_price(orig_text)

                    # --- Discount percentage (calculated) ---
                    discount_pct = None
                    if price and original_price and original_price > 0 and price < original_price:
                        discount_pct = round(((original_price - price) / original_price) * 100)

                    # --- Store name ---
                    store = None
                    store_elem = card.query_selector('a.dealCard__storeLink')
                    if store_elem:
                        store = store_elem.inner_text().strip()

                    # --- Description / deal note (e.g. "w/ Subscribe & Save") ---
                    description = None
                    note_elem = card.query_selector('.dealCard__note')
                    if note_elem:
                        description = note_elem.inner_text().strip()

                    # --- Badge / category (New, Promoted, etc.) ---
                    category = None
                    badge_elem = card.query_selector('.dealCard__badge')
                    if badge_elem:
                        category = badge_elem.inner_text().strip()

                    # --- Vote count ---
                    votes_up = None
                    vote_elem = card.query_selector('.dealCardSocialControls__voteCount')
                    if vote_elem:
                        votes_up = self.extract_int(vote_elem.inner_text())

                    # --- Comments count ---
                    comments_count = None
                    comment_elem = card.query_selector('.dealCardSocialControls__commentsLink')
                    if comment_elem:
                        comments_count = self.extract_int(comment_elem.inner_text())

                    # --- Posted timestamp ---
                    posted_at = None
                    timestamp_text = card.get_attribute('lastpostat')
                    if timestamp_text:
                        try:
                            posted_at = datetime.fromisoformat(timestamp_text)
                        except (ValueError, TypeError):
                            pass

                    deal = {
                        'product_name': title[:500],
                        'deal_url': deal_url,
                        'price': price,
                        'price_text': price_text,
                        'original_price': original_price,
                        'discount_percentage': discount_pct,
                        'store_name': store,
                        'image_url': image_url,
                        'description': description,
                        'category': category,
                        'votes_up': votes_up,
                        'comments_count': comments_count,
                        'posted_at': posted_at,
                        'source_deal_id': source_deal_id,
                        'deal_type': 'product',
                    }
                    deals.append(deal)

                    logger.info(
                        f"  [{idx}] {title[:60]}... "
                        f"${price or '-'} | {store or '?'} | "
                        f"+{votes_up or 0} votes, {comments_count or 0} comments"
                    )

                except Exception as e:
                    logger.debug(f"  [{idx}] Error extracting deal: {e}")
                    continue

        except Exception as e:
            logger.error(f"Error scraping main deals: {e}")

        return deals

    def scrape_sidebar_deals(self, page):
        """Scrape 'Popular Deals' from sidebar"""
        deals = []

        logger.info("Scraping sidebar popular deals...")

        try:
            sidebar_items = page.query_selector_all(
                'div.sidebarDealsRedesign li.sidebarDealsRedesign__deal'
            )
            logger.info(f"Found {len(sidebar_items)} sidebar deals")

            for idx, item in enumerate(sidebar_items, 1):
                try:
                    # --- Title + URL ---
                    link_elem = item.query_selector('a.sidebarDealsRedesign__dealTitleLink')
                    if not link_elem:
                        continue

                    title = link_elem.inner_text().strip()
                    if not title:
                        continue

                    href = link_elem.get_attribute('href')
                    deal_url = self.make_absolute_url(href)
                    if not deal_url:
                        continue

                    # --- Source deal ID from URL ---
                    source_deal_id = self.extract_deal_id_from_url(href)

                    # --- Vote count ---
                    votes_up = None
                    vote_elem = item.query_selector('.sidebarDealsRedesign__voteCount')
                    if vote_elem:
                        votes_up = self.extract_int(vote_elem.inner_text())

                    # --- Comment count ---
                    comments_count = None
                    comment_elem = item.query_selector('.sidebarDealsRedesign__socialCommentCount')
                    if comment_elem:
                        comments_count = self.extract_int(comment_elem.inner_text())

                    # --- Price (extracted from title text) ---
                    price = None
                    price_text = None
                    price_match = re.search(r'\$\s*(\d+(?:,\d{3})*(?:\.\d{1,2})?)', title)
                    if price_match:
                        price_text = price_match.group(0)
                        price = float(price_match.group(1).replace(',', ''))

                    deal = {
                        'product_name': title[:500],
                        'deal_url': deal_url,
                        'price': price,
                        'price_text': price_text,
                        'original_price': None,
                        'discount_percentage': None,
                        'store_name': None,
                        'image_url': None,
                        'description': None,
                        'category': 'Popular',
                        'votes_up': votes_up,
                        'comments_count': comments_count,
                        'posted_at': None,
                        'source_deal_id': source_deal_id,
                        'deal_type': 'product',
                    }
                    deals.append(deal)

                    logger.info(
                        f"  [sidebar {idx}] {title[:60]}... "
                        f"${price or '-'} | +{votes_up or 0} votes"
                    )

                except Exception as e:
                    logger.debug(f"  [sidebar {idx}] Error: {e}")
                    continue

        except Exception as e:
            logger.error(f"Error scraping sidebar deals: {e}")

        return deals

    def scrape_deals(self):
        """Scrape all deals using Playwright"""
        deals = []

        logger.info("=" * 60)
        logger.info("Slickdeals Scraper starting")
        logger.info("=" * 60)

        try:
            with sync_playwright() as p:
                logger.info("Launching browser...")
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox', '--disable-blink-features=AutomationControlled']
                )

                page = browser.new_page(
                    user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    viewport={'width': 1920, 'height': 1080}
                )

                logger.info(f"Loading {self.base_url}...")
                page.goto(self.base_url, wait_until='domcontentloaded', timeout=60000)

                # Wait for deal content to load
                logger.info("Waiting for deals to render...")
                try:
                    page.wait_for_selector(
                        'ul.frontpageGrid.frontpageSlickdealsGrid li.frontpageGrid__feedItem',
                        timeout=15000
                    )
                except PlaywrightTimeout:
                    logger.warning("Main grid selector timeout, continuing anyway...")

                # Allow dynamic content to finish loading
                page.wait_for_timeout(3000)

                # Scrape main deal grid
                main_deals = self.scrape_main_deals(page)
                deals.extend(main_deals)

                # Scrape sidebar popular deals
                sidebar_deals = self.scrape_sidebar_deals(page)
                deals.extend(sidebar_deals)

                browser.close()

                logger.info(f"Extracted {len(deals)} total deals")
                logger.info(f"  Main grid: {len(main_deals)}")
                logger.info(f"  Sidebar:   {len(sidebar_deals)}")

        except PlaywrightTimeout:
            logger.error("Playwright timeout loading page")
        except Exception as e:
            logger.error(f"Scraping error: {e}")

        return deals

    def save_deals(self, deals):
        """Save deals to database with all metadata"""
        if not deals:
            logger.info("No deals to save")
            return 0

        logger.info(f"Saving {len(deals)} deals to database...")
        saved = 0
        duplicates = 0
        errors = 0

        try:
            cursor = self.connection.cursor()

            for idx, deal in enumerate(deals, 1):
                try:
                    # Generate hash using source_deal_id when available for stable dedup
                    hash_input = deal.get('source_deal_id') or f"{deal['product_name']}{deal['deal_url']}"
                    content_hash = self.generate_hash(hash_input)

                    # Check for duplicates
                    cursor.execute(
                        "SELECT id FROM deals WHERE content_hash = %s",
                        (content_hash,)
                    )

                    if cursor.fetchone():
                        duplicates += 1
                        continue

                    # Format posted_at for MySQL
                    posted_at = None
                    if deal.get('posted_at'):
                        posted_at = deal['posted_at'].strftime('%Y-%m-%d %H:%M:%S')

                    # Insert new deal with all metadata
                    cursor.execute("""
                        INSERT INTO deals
                        (source_id, product_name, deal_url, price, price_text,
                         original_price, discount_percentage, store_name,
                         image_url, description, votes_up, comments_count,
                         deal_type, category, source_deal_id,
                         posted_at, content_hash, scraped_at)
                        VALUES (%s, %s, %s, %s, %s,
                                %s, %s, %s,
                                %s, %s, %s, %s,
                                %s, %s, %s,
                                %s, %s, NOW())
                    """, (
                        self.source_id,
                        deal['product_name'],
                        deal['deal_url'],
                        deal.get('price'),
                        deal.get('price_text'),
                        deal.get('original_price'),
                        deal.get('discount_percentage'),
                        deal.get('store_name'),
                        deal.get('image_url'),
                        deal.get('description'),
                        deal.get('votes_up'),
                        deal.get('comments_count'),
                        deal.get('deal_type', 'product'),
                        deal.get('category'),
                        deal.get('source_deal_id'),
                        posted_at,
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

                    logger.info(
                        f"  [{idx}/{len(deals)}] Saved: {deal['product_name'][:50]}..."
                    )

                except Exception as e:
                    errors += 1
                    logger.error(f"  [{idx}/{len(deals)}] Error: {str(e)[:80]}")
                    self.connection.rollback()
                    continue

            cursor.close()

        except Exception as e:
            logger.error(f"Database error: {e}")
            return saved

        logger.info("=" * 60)
        logger.info(f"Saved {saved} new deals")
        if duplicates > 0:
            logger.info(f"Skipped {duplicates} duplicates")
        if errors > 0:
            logger.warning(f"Encountered {errors} errors")
        logger.info("=" * 60)

        return saved

    def run(self):
        """Main execution"""
        if not self.connect_db():
            return 0

        deals = self.scrape_deals()
        saved = self.save_deals(deals)

        if self.connection and self.connection.is_connected():
            self.connection.close()

        return saved


if __name__ == "__main__":
    scraper = SlickdealsScraper()
    scraper.run()
