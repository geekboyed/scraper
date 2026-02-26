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
from urllib.parse import urlparse, parse_qs, unquote

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

    # Category mappings - Tech Bargains is primarily electronics
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
        50: [  # Electronics - primary category for this site
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
    DEFAULT_CATEGORY = 50  # Electronics (default for Tech Bargains)

    def categorize_deal(self, product_name):
        """Determine deal category based on product name keywords.

        Returns the category_id matching the first keyword found,
        or DEFAULT_CATEGORY (Electronics) if none match.
        """
        name_lower = product_name.lower()
        for category_id, keywords in self.CATEGORY_KEYWORDS.items():
            for kw in keywords:
                # Use word-boundary matching to avoid substring false positives
                # (e.g. 'ram' in 'ceramic', 'monitor' in 'monitoring')
                if re.search(r'\b' + re.escape(kw.strip()) + r'\b', name_lower):
                    return category_id
        return self.DEFAULT_CATEGORY

    KNOWN_MERCHANTS = {
        'amazon': 'Amazon', 'walmart': 'Walmart', 'bestbuy': 'Best Buy',
        'newegg': 'Newegg', 'woot': 'Woot', 'costco': 'Costco',
        'target': 'Target', 'homedepot': 'Home Depot', 'lowes': "Lowe's",
        'ebay': 'eBay', 'bhphotovideo': 'B&H Photo', 'adorama': 'Adorama',
        'staples': 'Staples', 'officedepot': 'Office Depot',
        'microcenter': 'Micro Center', 'gamestop': 'GameStop',
        'dell': 'Dell', 'hp': 'HP', 'lenovo': 'Lenovo', 'asus': 'ASUS',
        'samsung': 'Samsung', 'acer': 'Acer',
    }

    def extract_merchant_from_url(self, tracking_url):
        """Extract merchant name from TechBargains tracking URL's url= parameter"""
        if not tracking_url:
            return None
        try:
            params = parse_qs(urlparse(tracking_url).query)
            if 'url' not in params:
                return None
            merchant_url = unquote(params['url'][0])
            netloc = urlparse(merchant_url).netloc.lower().replace('www.', '')
            parts = netloc.split('.')
            key = parts[-2] if len(parts) >= 2 else parts[0]
            return self.KNOWN_MERCHANTS.get(key, key.title())
        except Exception:
            return None

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

                            # Get image - check all img tags for a non-placeholder src
                            image_url = None
                            for img_elem in element.query_selector_all('img'):
                                src = (img_elem.get_attribute('data-src') or
                                       img_elem.get_attribute('data-lazy-src') or
                                       img_elem.get_attribute('data-original') or
                                       img_elem.get_attribute('src'))
                                if src and 'image-default' not in src and 'placeholder' not in src:
                                    if not src.startswith('http'):
                                        src = 'https:' + src if src.startswith('//') else (self.base_url + src)
                                    image_url = src
                                    break

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

                            store_name = self.extract_merchant_from_url(deal_url)

                            deal = {
                                'product_name': title[:500],
                                'deal_url': deal_url,
                                'discount_percentage': discount_pct,
                                'description': description or f"{title} tech deal",
                                'image_url': image_url,
                                'deal_type': 'tech_deal',
                                'sale_price': price,
                                'price_text': price_text,
                                'store_name': store_name,
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
                        deal.get('deal_type', 'tech_deal'),
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

        print(f"\\n{'='*60}")
        print(f"✓ Saved {saved} new deals")
        print(f"{'='*60}\\n")

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
    scraper = TechBargainsScraper()
    scraper.run()
