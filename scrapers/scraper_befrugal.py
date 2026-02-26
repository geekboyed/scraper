#!/usr/bin/env python3
"""
BeFrugal Custom Scraper - Uses Playwright for JavaScript rendering
Extracts cashback deals and coupons, saves to deals table
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

class BeFrugalScraper:
    def __init__(self):
        self.source_id = 50  # BeFrugal source ID
        self.base_url = "https://www.befrugal.com"
        self.deals_url = "https://www.befrugal.com/deals/"

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
                print(f"⏳ Rate limit: Wait {wait_minutes} more minutes (delay: {delay_minutes}m)")
                return False

            return True
        except Error as e:
            print(f"⚠ Error checking rate limit: {e}")
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
            print("✓ Updated source statistics")
        except Error as e:
            print(f"⚠ Error updating source stats: {e}")

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

    KNOWN_STORE_NAMES = {
        'walmart': 'Walmart', 'amazon': 'Amazon', 'target': 'Target',
        'bestbuy': 'Best Buy', 'macys': "Macy's", 'kohls': "Kohl's",
        'homedepot': 'Home Depot', 'lowes': "Lowe's", 'ebay': 'eBay',
        'nordstrom': 'Nordstrom', 'oldnavy': 'Old Navy', 'gap': 'Gap',
        'bananarepublic': 'Banana Republic', 'anthropologie': 'Anthropologie',
        'sephora': 'Sephora', 'ulta': 'Ulta', 'cvs': 'CVS', 'walgreens': 'Walgreens',
        'costco': 'Costco', 'samsclub': "Sam's Club", 'adidas': 'Adidas',
        'nike': 'Nike', 'underarmour': 'Under Armour', 'hokaoneone': 'HOKA',
        'mountainhardwear': 'Mountain Hardwear', 'patagonia': 'Patagonia',
        'rei': 'REI', 'dickssportinggoods': "Dick's Sporting Goods",
        'gamestop': 'GameStop', 'newegg': 'Newegg', 'staples': 'Staples',
        '13deals': '13 Deals', 'zulily': 'Zulily', 'wayfair': 'Wayfair',
        'overstock': 'Overstock', 'jcpenney': 'JCPenney', 'macysbackstage': "Macy's Backstage",
    }

    def extract_store_from_url(self, url):
        """Extract store name from BeFrugal URL: /deals/ind/{store-slug}/{id}/"""
        if not url:
            return None
        match = re.search(r'/deals/ind/([^/]+)/', url)
        if not match:
            return None
        slug = match.group(1).lower()
        if slug in self.KNOWN_STORE_NAMES:
            return self.KNOWN_STORE_NAMES[slug]
        # Generic: replace hyphens with spaces, title-case
        return slug.replace('-', ' ').replace('_', ' ').title()

    def generate_hash(self, text):
        """Generate hash for duplicate detection"""
        return int(hashlib.md5(text.encode()).hexdigest()[:16], 16)

    def extract_cashback(self, text):
        """Extract cashback percentage from text like '10% Cash Back'"""
        if not text:
            return None
        match = re.search(r'(\d+(?:\.\d+)?)\s*%', text)
        return int(match.group(1)) if match else None

    def extract_price(self, price_text):
        """Extract numeric price from text like '$14.99'"""
        if not price_text:
            return None
        match = re.search(r'\$?(\d+(?:\.\d+)?)', price_text)
        return float(match.group(1)) if match else None

    def scrape_deals(self):
        """Scrape deals using Playwright"""
        deals = []

        print(f"\n{'='*60}")
        print(f"BeFrugal Scraper (Playwright)")
        print(f"{'='*60}\n")

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
                    page.wait_for_selector('.deal-card, .store-card, a[href*="/store/"], .partnerlogoscale', timeout=10000)
                except:
                    print("  ⚠ Deal selector timeout, continuing...")

                page.wait_for_timeout(3000)

                print("🔍 Extracting deals from page...")

                # Find deals using divs with numeric IDs and panel--main class
                # This is the actual structure: <div id="25616377"><div class="panel--top"><div class="panel--main">...
                deal_elements = page.query_selector_all('div[id] .panel--main')

                if deal_elements:
                    print(f"  → Found {len(deal_elements)} elements with selector: div[id] .panel--main")
                else:
                    # Fallback: look for divs with numeric IDs
                    all_divs = page.query_selector_all('div[id]')
                    deal_elements = []
                    for div in all_divs:
                        div_id = div.get_attribute('id')
                        # Check if ID is numeric (deal IDs are numeric)
                        if div_id and div_id.isdigit():
                            deal_elements.append(div)

                    if deal_elements:
                        print(f"  → Found {len(deal_elements)} elements with numeric IDs")

                if not deal_elements:
                    print("  ✗ No deal elements found")
                    browser.close()
                    return deals

                print(f"\n📦 Processing {len(deal_elements)} potential deals...\n")

                for idx, element in enumerate(deal_elements[:100], 1):
                    try:
                        # Get deal title from span.panel--title (correct BeFrugal selector)
                        title = None
                        title_elem = element.query_selector('span.panel--title')
                        if title_elem:
                            title = title_elem.inner_text().strip()

                        # If no title found, try alternative selectors
                        if not title:
                            for selector in ['span.deal-panel-title', '.deal-panel-title span', 'a span', 'h3', 'h4']:
                                elem = element.query_selector(selector)
                                if elem:
                                    text = elem.inner_text().strip()
                                    if text and 5 < len(text) < 200:
                                        title = text
                                        break

                        # Get URL from link in deal-content
                        deal_url = None
                        link_elem = element.query_selector('a[href*="/deals/ind/"]')
                        if not link_elem:
                            link_elem = element.query_selector('a[href]')
                        if link_elem:
                            href = link_elem.get_attribute('href')
                            if href:
                                deal_url = href if href.startswith('http') else (self.base_url + href)

                        # Get sale price from span.card--price
                        sale_price = None
                        sale_price_elem = element.query_selector('span.card--price')
                        if sale_price_elem:
                            price_text = sale_price_elem.inner_text().strip()
                            sale_price = self.extract_price(price_text)

                        # Get original price from span.panel--list-price
                        original_price = None
                        original_price_elem = element.query_selector('span.panel--list-price')
                        if original_price_elem:
                            price_text = original_price_elem.inner_text().strip()
                            original_price = self.extract_price(price_text)

                        # Get cashback percentage from a.txt-cb-link (correct BeFrugal selector)
                        cashback_text = None
                        discount_pct = None
                        cashback_elem = element.query_selector('a.txt-cb-link')
                        if cashback_elem:
                            text = cashback_elem.inner_text().strip()
                            if text and '%' in text:
                                cashback_text = text
                                discount_pct = self.extract_cashback(text)

                        # Fallback to other cashback selectors
                        if not cashback_text:
                            for selector in ['.txt-highlight', '[class*="cashback"]', '[class*="percent"]', '.panel--bottom']:
                                elem = element.query_selector(selector)
                                if elem:
                                    text = elem.inner_text().strip()
                                    if '%' in text:
                                        cashback_text = text
                                        discount_pct = self.extract_cashback(text)
                                        if discount_pct:
                                            break

                        # Get image
                        img_elem = element.query_selector('img')
                        image_url = img_elem.get_attribute('src') if img_elem else None
                        # Ensure full URL for images
                        if image_url and not image_url.startswith('http'):
                            image_url = 'https:' + image_url if image_url.startswith('//') else (self.base_url + image_url)

                        # Skip if no title
                        if not title:
                            continue

                        # Skip generic nav links and UI elements
                        title_lower = title.lower()
                        skip_keywords = [
                            'home', 'deals', 'stores', 'coupons', 'blog',
                            'today', 'sort by', 'browse', 'category',
                            'see more', 'all deals', 'hot deals', 'trending',
                            'just added', 'cashback', 'offer', 'coupon'
                        ]

                        # Check if title contains skip keywords
                        if any(keyword in title_lower for keyword in skip_keywords):
                            continue

                        # Skip very short titles (likely UI elements)
                        if len(title) < 8:
                            continue

                        store_name = self.extract_store_from_url(deal_url)

                        deal = {
                            'product_name': title[:500],
                            'deal_url': deal_url,
                            'discount_percentage': discount_pct,
                            'description': cashback_text or f"{title} cashback deal",
                            'image_url': image_url,
                            'deal_type': 'cashback',
                            'sale_price': sale_price,
                            'original_price': original_price,
                            'price_text': None,
                            'store_name': store_name,
                        }

                        # Generate price_text field (e.g., "$14-$45" or "$14")
                        if sale_price and original_price:
                            deal['price_text'] = f"${sale_price:.2f}-${original_price:.2f}"
                        elif sale_price:
                            deal['price_text'] = f"${sale_price:.2f}"

                        deals.append(deal)

                        print(f"[{idx}] ✓ {title[:60]}...")
                        if sale_price:
                            print(f"     💵 ${sale_price:.2f}" + (f" (orig: ${original_price:.2f})" if original_price else ""))
                        if cashback_text:
                            print(f"     💰 {cashback_text}")

                    except Exception as e:
                        continue

                browser.close()

        except Exception as e:
            print(f"✗ Scraping error: {e}")
            return deals

        print(f"\n✓ Extracted {len(deals)} deals from BeFrugal\n")
        return deals

    def save_deals(self, deals):
        """Save deals to database"""
        if not deals:
            print("No deals to save")
            return 0

        print(f"💾 Saving {len(deals)} deals...\n")
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
                        (source_id, product_name, deal_url, price, price_text, original_price,
                         discount_percentage, description, image_url, deal_type, store_name, content_hash, scraped_at)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                    """, (
                        self.source_id,
                        deal['product_name'],
                        deal.get('deal_url'),
                        deal.get('sale_price'),
                        deal.get('price_text'),
                        deal.get('original_price'),
                        deal.get('discount_percentage'),
                        deal.get('description'),
                        deal.get('image_url'),
                        deal.get('deal_type', 'cashback'),
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
                    print(f"[{idx}/{len(deals)}] ✓ Saved")

                except Exception as e:
                    self.connection.rollback()
                    continue

            cursor.close()

        except Exception as e:
            print(f"✗ Database error: {e}")
            return saved

        print(f"\n{'='*60}")
        print(f"✓ Saved {saved} new deals")
        print(f"{'='*60}\n")

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
    scraper = BeFrugalScraper()
    scraper.run()
