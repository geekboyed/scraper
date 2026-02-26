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

    def should_scrape(self):
        """Check if enough time has passed since last scrape"""
        if os.environ.get('FORCE_SCRAPE') == '1':
            return True  # Bypass rate limit (manual scrape)
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT scrape_delay_minutes, last_scraped_at,
                       TIMESTAMPDIFF(MINUTE, last_scraped_at, NOW()) as minutes_since
                FROM sources
                WHERE id = %s
            """, (self.source_id,))
            result = cursor.fetchone()
            cursor.close()

            if not result or not result['last_scraped_at']:
                return True  # Never scraped before

            delay_minutes = result['scrape_delay_minutes'] or 10
            minutes_since = result['minutes_since'] or 999999

            if minutes_since < delay_minutes:
                wait_minutes = delay_minutes - minutes_since
                logger.info(f"⏳ Rate limit: Wait {wait_minutes} more minutes (delay: {delay_minutes}m)")
                return False

            return True
        except Error as e:
            logger.warning(f"Error checking rate limit: {e}")
            return True  # Allow scraping if check fails

    def update_source_stats(self):
        """Update source statistics after scraping"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE sources
                SET last_scraped = NOW(),
                    last_scraped_at = NOW()
                WHERE id = %s
            """, (self.source_id,))
            self.connection.commit()
            cursor.close()
            logger.info("✓ Updated source statistics")
        except Error as e:
            logger.warning(f"Error updating source stats: {e}")

    # Category mappings for deal subcategorization
    CATEGORY_KEYWORDS = {
        56: [  # Entertainment & Media — before Electronics so PS5/Xbox route here
            'playstation', 'ps5', 'ps4', 'xbox series', 'xbox one', 'nintendo switch',
            'steam deck', 'game pass', 'video game', 'blu-ray', '4k uhd',
            'dvd', 'vinyl record', 'board game', 'trading card',
        ],
        61: [  # Computers
            'laptop', 'notebook', 'ultrabook', 'chromebook',
            'desktop pc', 'desktop computer', 'all-in-one pc',
            'macbook', 'mac mini', 'mac pro', 'imac',
            'gaming pc', 'gaming laptop', 'gaming desktop',
            'computer build', 'mini pc',
            'processor', 'cpu', 'intel core', 'amd ryzen', 'amd threadripper',
            'motherboard', 'gpu', 'graphics card', 'nvidia rtx', 'amd radeon',
            'ddr4', 'ddr5', 'memory module', 'ddr ram',
            'ssd', 'nvme', 'm.2 drive', 'hard drive', 'hdd', '2.5" ssd',
            'computer case', 'pc case', 'atx case',
            'cpu cooler', 'liquid cooling', 'air cooler',
            'power supply', 'psu', '80+ gold',
            'monitor', '4k monitor', 'gaming monitor', '144hz', '240hz',
            'keyboard', 'mechanical keyboard', 'gaming keyboard',
            'mouse', 'gaming mouse', 'wireless mouse',
            'webcam', 'usb hub', 'laptop bag', 'laptop stand',
        ],
        50: [  # Electronics
            'phone', 'tablet', 'television', 'oled', 'qled',
            'headphone', 'earbuds', 'airpods',
            'camera', 'speaker', 'smartwatch', 'fitness tracker', 'smart tv',
            'tech', 'electronics', 'gadget', 'microphone', 'smart home', 'alexa', 'echo', 'google home',
            'nest', 'ring doorbell', 'drone', 'projector', 'dash cam',
            'router', 'wifi', 'modem', 'network switch',
            'hdmi', 'thunderbolt', 'charger', 'power bank',
            'printer', 'scanner', 'surge protector',
            'iphone', 'ipad', 'android', 'pixel phone',
            'samsung galaxy', 'lg oled', 'tcl tv',
        ],
        53: [  # Clothing & Apparel
            'shirt', 'pants', 'jacket', 'dress', 'shoe', 'boot', 'sneaker',
            'sock', 'underwear', 'hoodie', 'coat', 'sweater', 'jeans', 'shorts',
            'legging', 'swimsuit', 'hat', 'cap', 'glove', 'scarf', 'vest',
            'blazer', 'suit', 'polo', 'clothing', 'apparel', 'fashion',
            'nike ', 'adidas', 'under armour', 'north face', 'columbia jacket',
            'patagonia', 'new balance', "levi's", 'gap ', 'old navy',
        ],
        55: [  # Health & Beauty
            'vitamin', 'supplement', 'probiotic', 'collagen', 'omega-3',
            'toothpaste', 'toothbrush', 'shampoo', 'conditioner', 'lotion',
            'skincare', 'moisturizer', 'sunscreen', 'face wash', 'serum',
            'deodorant', 'razor', 'hair dryer', 'beard',
            'first aid', 'bandage', 'ibuprofen', 'tylenol', 'advil', 'zyrtec',
            'lip balm', 'protein powder', 'creatine', 'pre-workout',
            'allergy relief', 'cold medicine', 'cough syrup',
            'body wash', 'hand sanitizer', 'dental floss', 'mouthwash',
            'blood pressure monitor', 'thermometer', 'pulse oximeter',
        ],
        54: [  # Home & Kitchen
            'vacuum', 'robot vacuum', 'mop', 'broom', 'cleaning supply',
            'blender', 'air fryer', 'instant pot', 'slow cooker', 'coffee maker',
            'espresso', 'keurig', 'nespresso',
            'knife set', 'cutting board', 'nonstick pan', 'cast iron', 'skillet',
            'bedding', 'pillow', 'mattress', 'sheet set', 'blanket', 'comforter',
            'furniture', 'office chair', 'standing desk', 'bookshelf',
            'storage bin', 'organizer', 'drawer', 'lamp', 'led strip',
            'curtain', 'area rug', 'bath towel', 'shower curtain',
            'ceiling fan', 'air purifier', 'humidifier', 'dehumidifier', 'space heater',
            'trash can', 'toilet paper', 'paper towel', 'dish soap',
            'picture frame', 'wall art', 'welcome mat',
        ],
        49: [  # Food & Grocery
            'food', 'snack', 'cereal', 'coffee bean', 'grocery',
            'burger', 'pizza', 'candy', 'chocolate', 'cookie', 'chips',
            'popcorn', 'energy drink', 'soda', 'sparkling water', 'juice',
            'tea bag', 'sauce', 'seasoning', 'olive oil', 'spice',
            'almond', 'cashew', 'jerky', 'granola bar', 'trail mix',
            'frozen meal', 'instant noodle', 'ramen',
            'baking mix', 'flour', 'sugar',
        ],
        57: [  # Sports & Outdoors
            'gym equipment', 'yoga mat', 'dumbbell', 'barbell', 'weight plate',
            'treadmill', 'elliptical', 'stationary bike', 'rowing machine',
            'hiking boot', 'camping', 'backpacking', 'tent', 'sleeping bag',
            'mountain bike', 'road bike', 'cycling', 'bicycle',
            'golf club', 'tennis racket', 'pickleball', 'basketball',
            'fishing rod', 'hunting', 'archery',
            'ski', 'snowboard', 'surfboard',
            'resistance band', 'jump rope', 'pull-up bar',
            'water bottle', 'hydration pack',
            'rock climbing', 'kayak', 'canoe', 'paddle board',
            'sports bag', 'athletic',
        ],
        58: [  # Tools & Hardware
            'power drill', 'circular saw', 'jigsaw', 'reciprocating saw',
            'screwdriver set', 'wrench set', 'socket set', 'hex key',
            'hammer', 'plier', 'wire stripper', 'multimeter',
            'cordless tool', 'dewalt', 'milwaukee tool', 'makita', 'ryobi',
            'ladder', 'workbench', 'toolbox', 'tool bag',
            'measuring tape', 'laser level', 'stud finder',
            'air compressor', 'nail gun', 'staple gun',
            'extension cord', 'power strip',
            'sandpaper', 'paint roller', 'caulk gun',
            'pipe wrench', 'pipe fitting',
        ],
        59: [  # Automotive
            'motor oil', 'engine oil', 'oil filter', 'air filter', 'cabin filter',
            'car wash kit', 'car wax', 'detailing',
            'car floor mat', 'seat cover', 'car cover',
            'jump starter', 'battery charger booster',
            'wiper blade', 'windshield', 'car sun shade',
            'brake pad', 'rotor',
            'car phone mount', 'car charger',
            'tow strap', 'trailer hitch',
            'tire inflator', 'tire pressure',
            'truck bed', 'cargo net', 'roof rack',
        ],
        52: [  # Gardening & Outdoor Home
            'garden hose', 'plant pot', 'garden tool', 'lawn mower',
            'weed eater', 'leaf blower', 'chainsaw', 'hedge trimmer',
            'grass seed', 'fertilizer', 'soil', 'mulch', 'compost',
            'sprinkler', 'drip irrigation', 'watering can',
            'patio furniture', 'adirondack', 'hammock',
            'outdoor grill', 'bbq', 'smoker', 'fire pit',
            'shed', 'pergola', 'raised garden bed',
            'bird feeder', 'bird bath',
        ],
        60: [  # Memberships & Services
            'gift card', 'membership', 'subscription',
            'amazon prime', "sam's club", 'costco', 'walmart+',
            'disney+', 'hbo max', 'peacock', 'paramount+',
            'spotify premium', 'apple music', 'youtube premium',
            'annual plan', 'prepaid card', 'visa gift',
            'xbox game pass', 'playstation plus', 'nintendo online',
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
            for kw in keywords:
                # Use word-boundary matching to avoid substring false positives
                # (e.g. 'ram' in 'ceramic', 'monitor' in 'monitoring')
                if re.search(r'\b' + re.escape(kw.strip()) + r'\b', name_lower):
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
                    # Sponsored deals use /azpp redirect — find actual thread link
                    if href and '/azpp' in href:
                        alt_link = card.query_selector('a[href*="/f/"]')
                        if alt_link:
                            href = alt_link.get_attribute('href')
                    deal_url = self.make_absolute_url(href)
                    if not deal_url:
                        continue

                    # --- Source deal ID from data attribute or URL ---
                    source_deal_id = card.get_attribute('data-threadid')
                    if not source_deal_id:
                        source_deal_id = self.extract_deal_id_from_url(href)
                    # If still an ad URL, construct thread URL from ID
                    if source_deal_id and deal_url and '/azpp' in deal_url:
                        deal_url = f"{self.base_url}/f/{source_deal_id}"

                    # --- Image ---
                    img_elem = card.query_selector('img.dealCard__image')
                    image_url = self.make_absolute_url(img_elem.get_attribute('src')) if img_elem else None

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

                    # --- Store name (sidebar) ---
                    store = None
                    for store_sel in [
                        '.sidebarDealsRedesign__store',
                        '.sidebarDealsRedesign__storeName',
                        '.sidebarDealsRedesign__merchant',
                        'a[data-type="store"]',
                    ]:
                        store_elem = item.query_selector(store_sel)
                        if store_elem:
                            store = store_elem.inner_text().strip() or None
                            if store:
                                break

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
                        'store_name': store,
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

        # Check rate limiting
        if not self.should_scrape():
            if self.connection and self.connection.is_connected():
                self.connection.close()
            return 0

        deals = self.scrape_deals()
        saved = self.save_deals(deals)

        # Update source statistics
        self.update_source_stats()

        if self.connection and self.connection.is_connected():
            self.connection.close()

        return saved


if __name__ == "__main__":
    scraper = SlickdealsScraper()
    scraper.run()
