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
from urllib.parse import quote_plus, urljoin
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

            # Method 2: Parse JavaScript data
            scripts = soup.find_all('script')
            for script in scripts:
                if script.string and 'data:image' not in script.string:
                    # Look for image URLs in the script
                    matches = re.findall(r'https?://[^"\s]+\.(?:jpg|jpeg|png|webp)', script.string)
                    for match in matches:
                        if 'gstatic' not in match and self.is_valid_image_url(match):
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

    def find_image_for_deal(self, product_name):
        """Find an appropriate image for a deal"""
        # Clean the product name
        clean_name = self.clean_product_name(product_name)

        print(f"  Searching: {clean_name[:50]}...")

        # Try Google Custom Search API first
        if self.google_api_key and self.google_cse_id:
            image_url = self.search_google_images_api(clean_name)
            if image_url:
                print(f"  ✓ Found via API")
                return image_url

        # Fallback to scraping
        image_url = self.scrape_google_images(clean_name)
        if image_url:
            print(f"  ✓ Found via scraping")
            return image_url

        print(f"  ✗ No image found")
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

    def get_deals_without_images(self, limit=50):
        """Get deals without images"""
        try:
            cursor = self.connection.cursor(dictionary=True)
            cursor.execute("""
                SELECT id, product_name, store_name, price_text
                FROM deals
                WHERE (image_url IS NULL OR image_url = '')
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
            image_url = self.find_image_for_deal(deal['product_name'])

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
