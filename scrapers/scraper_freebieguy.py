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
import logging

# Set up logging
LOG_DIR = '/var/www/html/scraper/logs'
os.makedirs(LOG_DIR, exist_ok=True)
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, 'scraper_freebieguy.log')),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

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

    # Common retailers to match against title text
    KNOWN_RETAILERS = [
        'Amazon', 'Walmart', 'Target', 'Best Buy', 'Costco', "Sam's Club",
        'Walgreens', 'CVS', 'Rite Aid', 'Dollar General', 'Dollar Tree',
        'Home Depot', "Lowe's", 'Wayfair', 'Overstock', 'Zulily',
        'Kohl\'s', 'Macy\'s', 'Nordstrom', 'JCPenney', 'Old Navy', 'Gap',
        'Banana Republic', 'Anthropologie', 'H&M', 'Forever 21',
        'Nike', 'Adidas', 'Under Armour', 'Reebok', 'New Balance',
        'Sephora', 'Ulta', 'Bath & Body Works',
        'GameStop', 'Newegg', 'B&H', 'Adorama', 'Staples',
        'Burger King', 'McDonald\'s', 'Wendy\'s', 'Subway', 'Chick-fil-A',
        'Starbucks', 'Dunkin', 'Pizza Hut', 'Domino\'s', 'Chipotle',
        'eBay', 'Etsy', 'Chewy', 'PetSmart', 'Petco',
        'REI', 'Dick\'s Sporting Goods', 'Academy Sports',
        'Michael Kors', 'Coach', 'Kate Spade', 'Levi\'s',
    ]

    # Month names to exclude from merchant extraction
    MONTH_NAMES = {'jan', 'feb', 'mar', 'apr', 'may', 'jun',
                   'jul', 'aug', 'sep', 'oct', 'nov', 'dec'}

    def extract_merchant_from_title(self, title, description=None):
        """Extract merchant from deal title patterns like 'on Amazon', 'at Walmart'"""
        if not title:
            return None
        text = title + ' ' + (description or '')
        # 1. Check known retailers first (most reliable)
        text_lower = text.lower()
        for retailer in self.KNOWN_RETAILERS:
            if retailer.lower() in text_lower:
                return retailer
        # 2. Match "on/at/from/via StoreName" pattern
        match = re.search(
            r'\b(?:on|at|from|via)\s+([A-Z][A-Za-z\'s&. ]{1,30}?)(?:\s*[\(\|!\.,]|\s+(?:only|for|just|reg|sale|\+)|$)',
            text
        )
        if match:
            candidate = match.group(1).strip().rstrip('.')
            if len(candidate) >= 3 and candidate.lower() not in self.MONTH_NAMES:
                return candidate
        return None

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

        logger.info("=" * 60)
        logger.info("Freebie Guy Scraper starting")
        logger.info("=" * 60)

        try:
            with sync_playwright() as p:
                logger.info("Launching browser...")
                browser = p.chromium.launch(
                    headless=True,
                    args=['--no-sandbox']
                )

                page = browser.new_page(
                    user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    viewport={'width': 1920, 'height': 1080}
                )

                logger.info(f"Loading {self.deals_url}...")
                page.goto(self.deals_url, wait_until='domcontentloaded', timeout=60000)

                # Wait for content
                logger.info("Waiting for deals to render...")
                try:
                    page.wait_for_selector('article, .entry, .post', timeout=10000)
                except:
                    logger.warning("Article selector timeout, continuing...")

                page.wait_for_timeout(3000)

                logger.info("Extracting deals from page...")

                # WordPress typically uses article elements
                deal_elements = page.query_selector_all('article.entry, article.post')

                if not deal_elements:
                    logger.warning("No deal elements found")
                    browser.close()
                    return deals

                logger.info(f"Processing {len(deal_elements)} potential deals...")

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

                        store_name = self.extract_merchant_from_title(title, description)

                        deal = {
                            'product_name': title[:500],
                            'deal_url': deal_url,
                            'discount_percentage': discount_pct,
                            'description': description or f"{title} - Free sample or deal",
                            'image_url': image_url,
                            'deal_type': 'freebie',
                            'sale_price': price,
                            'price_text': price_text,
                            'store_name': store_name,
                        }

                        deals.append(deal)

                        logger.info(
                            f"  [{idx}] {title[:60]}... "
                            f"{'FREE' if price == 0 else f'${price:.2f}' if price is not None else '-'}"
                            f"{f' ({discount_pct}% off)' if discount_pct else ''}"
                        )

                    except Exception as e:
                        logger.debug(f"  [{idx}] Error extracting deal: {e}")
                        continue

                browser.close()

        except Exception as e:
            logger.error(f"Scraping error: {e}")
            return deals

        logger.info(f"Extracted {len(deals)} total deals")
        return deals

    def save_deals(self, deals):
        """Save deals to database"""
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
                    hash_text = f"{deal['product_name']}{deal.get('deal_url', '')}"
                    content_hash = self.generate_hash(hash_text)

                    # Check duplicates
                    cursor.execute("SELECT id FROM deals WHERE content_hash = %s", (content_hash,))
                    if cursor.fetchone():
                        duplicates += 1
                        continue

                    # Insert
                    cursor.execute("""
                        INSERT INTO deals
                        (source_id, product_name, deal_url, price, price_text,
                         discount_percentage, description, image_url, deal_type, store_name, content_hash, scraped_at)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
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
                        deal.get('store_name'),
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
    scraper = FreebieGuyScraper()
    scraper.run()
