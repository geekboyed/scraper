#!/usr/bin/env python3
"""
Add Images to Deals - Searches and adds images to deals without pictures
Uses Google Custom Search API or scrapes Google Images as fallback
"""

import os
import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import env_loader  # Auto-loads .env and ~/.env_AI

import mysql.connector
from mysql.connector import Error
import requests
from bs4 import BeautifulSoup
import re
import time
from urllib.parse import quote_plus, urljoin, urlparse, parse_qs, unquote
import json

class DealImageAdder:
    def __init__(self):
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

        self.connection = None
        self.google_api_key = os.getenv('GOOGLE_API_KEY')
        self.google_cse_id = os.getenv('GOOGLE_CSE_ID')

        # User agent for scraping
        self.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language': 'en-US,en;q=0.9',
        }

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

    def clean_product_name(self, product_name):
        """Extract clean product name for better image search"""
        # Remove price information
        cleaned = re.sub(r'\$[\d,]+\.?\d*', '', product_name)

        # Remove size/quantity info like "24-Pack", "32-Oz"
        cleaned = re.sub(r'\d+[-\s]?(Pack|Oz|Ct|Inch|in|GB|TB|ml|L)\b', '', cleaned, flags=re.IGNORECASE)

        # Remove shipping info
        cleaned = re.sub(r'(?:Free Shipping|w/\s*Prime|on \$\d+).*$', '', cleaned, flags=re.IGNORECASE)

        # Remove S&S (Subscribe & Save)
        cleaned = re.sub(r'w/\s*S&S', '', cleaned, flags=re.IGNORECASE)

        # Remove size variations in parentheses like "(Grey/Pink 6.5,7,8.5)"
        cleaned = re.sub(r'\([^)]*\)', '', cleaned)

        # Remove extra whitespace
        cleaned = ' '.join(cleaned.split())

        # Take first 60 chars for better search results
        if len(cleaned) > 60:
            cleaned = cleaned[:60].rsplit(' ', 1)[0]

        return cleaned.strip()

    def search_google_images_api(self, query):
        """Search using Google Custom Search API"""
        if not self.google_api_key or not self.google_cse_id:
            return None

        try:
            url = "https://www.googleapis.com/customsearch/v1"
            params = {
                'key': self.google_api_key,
                'cx': self.google_cse_id,
                'q': query,
                'searchType': 'image',
                'num': 1,
                'imgSize': 'medium',
                'safe': 'off'
            }

            response = requests.get(url, params=params, timeout=10)

            if response.status_code == 200:
                data = response.json()
                if 'items' in data and len(data['items']) > 0:
                    return data['items'][0]['link']

            return None

        except Exception as e:
            print(f"  Google API error: {e}")
            return None

    def scrape_google_images(self, query):
        """Fallback: Scrape Google Images directly"""
        try:
            search_url = f"https://www.google.com/search?q={quote_plus(query)}&tbm=isch"

            response = requests.get(search_url, headers=self.headers, timeout=10)

            if response.status_code != 200:
                return None

            soup = BeautifulSoup(response.text, 'html.parser')

            # Try to find image URLs in various formats
            # Method 1: Look for img tags with data-src
            images = soup.find_all('img')
            for img in images:
                src = img.get('data-src') or img.get('src')
                if src and src.startswith('http') and 'gstatic' not in src:
                    # Validate it's a real image URL
                    if self.is_valid_image_url(src):
                        return src

            # Method 2: Parse JavaScript data (use get_text() as fallback when .string is None)
            scripts = soup.find_all('script')
            for script in scripts:
                text = script.string or script.get_text()
                if text and 'data:image' not in text:
                    # Look for image URLs in the script
                    matches = re.findall(r'https?://[^"\s\]]+\.(?:jpg|jpeg|png|webp)', text)
                    for match in matches:
                        if 'gstatic' not in match and self.is_valid_image_url(match):
                            return match

            # Method 3: Full page text scan (catches URLs in JSON body blocks)
            skip_domains = {'gstatic.com', 'encrypted-tbn', 'google.com/images'}
            matches = re.findall(r'https?://[^"\s\]]+\.(?:jpg|jpeg|png|webp)', response.text)
            for match in matches:
                if not any(d in match for d in skip_domains):
                    if self.is_valid_image_url(match):
                        return match

            return None

        except Exception as e:
            print(f"  Scraping error: {e}")
            return None

    def is_valid_image_url(self, url):
        """Check if URL is a valid image"""
        try:
            # Skip Google's static images and icons
            skip_domains = ['gstatic.com', 'google.com/images', 'encrypted-tbn']
            if any(domain in url for domain in skip_domains):
                return False

            # Check if URL ends with image extension
            if re.search(r'\.(jpg|jpeg|png|webp)(?:\?|$)', url, re.IGNORECASE):
                return True

            # Try HEAD request to check content type
            response = requests.head(url, headers=self.headers, timeout=5, allow_redirects=True)
            content_type = response.headers.get('content-type', '').lower()

            return 'image' in content_type

        except:
            return False

    def find_image_for_deal(self, deal):
        """Find an appropriate image for a deal"""
        product_name = deal['product_name']
        deal_url = deal.get('deal_url', '') or ''
        source_id = deal.get('source_id')

        # Slickdeals: try CDN image on deal page, then follow merchant redirect
        SLICKDEALS_SOURCE_IDS = {49, 53, 54}
        if source_id in SLICKDEALS_SOURCE_IDS and deal_url:
            image_url = self.get_slickdeals_image(deal_url)
            if image_url:
                print(f"  ✓ Found via Slickdeals page")
                return image_url

        # All sources: follow deal_url to target page first (most direct)
        if deal_url:
            image_url = self.get_image_from_target_page(deal_url)
            if image_url:
                print(f"  ✓ Found via deal target page")
                return image_url

        # Fall back to Google search
        clean_name = self.clean_product_name(product_name)
        print(f"  Searching Google: {clean_name[:50]}...")

        if self.google_api_key and self.google_cse_id:
            image_url = self.search_google_images_api(clean_name)
            if image_url:
                print(f"  ✓ Found via Google API")
                return image_url

        image_url = self.scrape_google_images(clean_name)
        if image_url:
            print(f"  ✓ Found via Google scrape")
            return image_url

        print(f"  ✗ No image found")
        return None

    def get_image_from_target_page(self, url):
        """Follow deal URL to final page (through redirects) and extract product image."""
        SKIP_DOMAINS = {'befrugal.com', 'techbargains.com', 'thefreebieguy.com'}

        # TechBargains tracking URLs embed the merchant URL in a `url=` param — extract directly
        if 'cc.techbargains.com' in url:
            qs = parse_qs(urlparse(url).query)
            merchant_url = qs.get('url', [None])[0]
            if merchant_url:
                url = unquote(merchant_url)

        try:
            resp = requests.get(url, headers=self.headers, timeout=10, allow_redirects=True)
            if resp.status_code != 200:
                return None

            final_url = resp.url

            # Skip deal aggregator pages (not product pages)
            if any(d in final_url for d in SKIP_DOMAINS):
                return None

            soup = BeautifulSoup(resp.text, 'html.parser')

            # 1. og:image — most reliable for product pages
            og = soup.find('meta', property='og:image')
            if og and og.get('content'):
                src = og['content']
                if src.startswith('//'):
                    src = 'https:' + src
                return src

            # 2. twitter:image
            tw = soup.find('meta', attrs={'name': 'twitter:image'})
            if tw and tw.get('content'):
                return tw['content']

            # 3. Amazon-specific image extraction
            if 'amazon.com' in final_url:
                # First: #landingImage tag (most reliable)
                img = soup.find('img', id='landingImage')
                if img:
                    src = img.get('data-old-hires') or img.get('src')
                    if src and 'm.media-amazon.com' in src:
                        return src
                # Fallback: hiRes/large keys in script tags (filter to image extensions only)
                img_ext_re = re.compile(r'\.(jpg|jpeg|png|webp)(\.|$)', re.IGNORECASE)
                for script in soup.find_all('script', type=False):
                    text = script.string or ''
                    match = re.search(r'"hiRes"\s*:\s*"(https://m\.media-amazon\.com/images/I/[^"]+)"', text)
                    if not match:
                        match = re.search(r'"large"\s*:\s*"(https://m\.media-amazon\.com/images/I/[^"]+)"', text)
                    if match and img_ext_re.search(match.group(1)):
                        return match.group(1)

            # 4. JSON-LD structured data (works on many product pages)
            for script in soup.find_all('script', type='application/ld+json'):
                try:
                    import json
                    data = json.loads(script.string or '{}')
                    # Handle list or dict
                    items = data if isinstance(data, list) else [data]
                    for item in items:
                        img = item.get('image')
                        if isinstance(img, str) and img.startswith('http'):
                            return img
                        if isinstance(img, list) and img:
                            return img[0]
                        if isinstance(img, dict):
                            return img.get('url', '')
                except Exception:
                    pass

            # 5. First substantial <img> with a real image URL (skip icons/logos)
            skip_kw = ['logo', 'icon', 'sprite', 'avatar', 'banner', 'pixel', '1x1',
                       'warranty', 'badge', 'star', 'rating', 'spinner']
            for img in soup.find_all('img', src=True):
                src = img['src']
                if not src.startswith('http'):
                    src = urljoin(final_url, src)
                if re.search(r'\.(jpg|jpeg|png|webp)(\?|$)', src, re.IGNORECASE):
                    if not any(kw in src.lower() for kw in skip_kw):
                        return src

        except Exception as e:
            print(f"  Target page fetch error: {e}")
        return None

    def update_deal_image(self, deal_id, image_url):
        """Update deal with image URL and mark as auto-found"""
        try:
            cursor = self.connection.cursor()
            cursor.execute("""
                UPDATE deals
                SET image_url = %s,
                    image_auto_found = 'Y'
                WHERE id = %s
            """, (image_url, deal_id))

            self.connection.commit()
            cursor.close()
            return True

        except Error as e:
            print(f"  ✗ DB error: {e}")
            return False

    def get_slickdeals_image(self, deal_url):
        """Fetch image from a Slickdeals deal page, or follow the merchant redirect."""
        if not deal_url or 'slickdeals.net' not in deal_url:
            return None
        try:
            resp = requests.get(deal_url, headers=self.headers, timeout=10, allow_redirects=True)
            if resp.status_code != 200:
                return None
            soup = BeautifulSoup(resp.text, 'html.parser')

            # 1. Primary: large deal image hosted on Slickdeals CDN
            img = soup.find('img', class_='dealImage__image')
            if img:
                src = img.get('src') or img.get('data-src')
                if src:
                    return src

            # 2. Any slickdealscdn image (thumbnails etc.)
            for tag in soup.find_all('img'):
                src = tag.get('src') or tag.get('data-src') or ''
                if 'slickdealscdn.com' in src and 'avatar' not in src:
                    return src

            # 3. No image on Slickdeals page — follow the merchant redirect link
            merchant_link = soup.find('a', href=re.compile(r'slickdeals\.net/click'))
            if merchant_link:
                click_url = merchant_link['href']
                if not click_url.startswith('http'):
                    click_url = 'https://slickdeals.net' + click_url
                merchant_resp = requests.get(click_url, headers=self.headers,
                                             timeout=10, allow_redirects=True)
                if merchant_resp.status_code == 200:
                    return self.get_image_from_target_page(merchant_resp.url)

        except Exception as e:
            print(f"  Slickdeals fetch error: {e}")
        return None

    def get_deals_without_images(self, limit=50):
        """Get deals without images (including known placeholder URLs)"""
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT id, product_name, store_name, price_text, deal_url, source_id
                FROM deals
                WHERE (
                    image_url IS NULL
                    OR image_url = ''
                    OR image_url LIKE '%image-default%'
                    OR image_url LIKE '%placeholder%'
                    OR image_url LIKE '%no-image%'
                )
                AND is_active = 'Y'
                ORDER BY posted_at DESC
                LIMIT %s
            """, (limit,))

            deals = cursor.fetchall()
            cursor.close()
            return deals

        except Error as e:
            print(f"✗ Error fetching deals: {e}")
            return []

    def run(self, limit=50, delay=2):
        """Main workflow"""
        print("=" * 60)
        print("Deal Image Adder")
        print("=" * 60)

        if not self.connect_db():
            return

        # Check API availability
        if self.google_api_key and self.google_cse_id:
            print("✓ Google Custom Search API configured")
        else:
            print("⚠ No Google API key - using scraping fallback")
            print("  Set GOOGLE_API_KEY and GOOGLE_CSE_ID in ~/.env_AI for better results")

        print(f"\n🔍 Finding deals without images...")
        deals = self.get_deals_without_images(limit)

        if not deals:
            print("No deals without images found")
            return

        print(f"✓ Found {len(deals)} deals without images")
        print(f"\n📸 Adding images (delay: {delay}s between searches)...\n")

        added = 0
        failed = 0

        for i, deal in enumerate(deals, 1):
            print(f"[{i}/{len(deals)}] {deal['product_name'][:60]}...")

            # Find image
            image_url = self.find_image_for_deal(deal)

            if image_url:
                # Update deal
                if self.update_deal_image(deal['id'], image_url):
                    added += 1
                else:
                    failed += 1
            else:
                failed += 1

            # Rate limiting delay
            if i < len(deals):
                time.sleep(delay)

        print("\n" + "=" * 60)
        print(f"✓ Added images: {added}")
        print(f"✗ Failed: {failed}")
        print("=" * 60)

        if self.connection:
            self.connection.close()

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description='Add images to deals without pictures')
    parser.add_argument('--limit', type=int, default=50, help='Number of deals to process (default: 50)')
    parser.add_argument('--delay', type=int, default=2, help='Delay in seconds between searches (default: 2)')

    args = parser.parse_args()

    adder = DealImageAdder()
    adder.run(limit=args.limit, delay=args.delay)
