#!/usr/bin/env python3
"""
Bulk Update Featured Images for Existing WordPress Posts
Updates all imported posts with new banner URLs (removes resize/filter parameters)
"""

import json
import asyncio
import aiohttp
import sys
import time
import re
from datetime import datetime
from typing import List, Dict, Any
import logging

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('banner_update_log.txt', encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class BannerImageUpdater:
    def __init__(self, wordpress_url: str, api_key: str):
        self.wordpress_url = wordpress_url.rstrip('/')
        self.api_key = api_key
        self.api_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/import-post"
        self.status_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/status"
        
        # Performance settings
        self.max_concurrent = 10  # Conservative for updates
        self.request_timeout = 60
        self.batch_size = 20
        self.retry_attempts = 3
        self.request_delay = 0.5  # Slower for updates
        
        # Statistics
        self.updated = 0
        self.failed = 0
        self.skipped = 0
        self.failed_posts = []
        self.start_time = None
        
        # Rate limiting
        self.semaphore = asyncio.Semaphore(self.max_concurrent)
    
    def update_banner_url(self, url: str) -> str:
        """Remove resize/filter parameters from banner URL"""
        if not url:
            return url
        
        # Pattern to match and remove the resize/filter parameters
        # Matches: /640x480/filters:format(webp)/
        pattern = r'/\d+x\d+/filters:format\(webp\)/'
        updated_url = re.sub(pattern, '/', url)
        
        return updated_url
    
    async def test_connection(self) -> bool:
        """Test API connection"""
        try:
            timeout = aiohttp.ClientTimeout(total=10)
            async with aiohttp.ClientSession(timeout=timeout) as session:
                async with session.get(self.status_endpoint, headers={'X-API-Key': self.api_key}) as response:
                    if response.status == 200:
                        data = await response.json()
                        logger.info("✅ Connected to WordPress API")
                        logger.info(f"   WordPress Version: {data.get('wordpress_version', 'Unknown')}")
                        logger.info(f"   Plugin Version: {data.get('plugin_version', 'Unknown')}")
                        logger.info(f"   Previously Imported: {data.get('imported_posts', 0)} posts")
                        return True
                    else:
                        logger.error(f"❌ API connection failed: {response.status}")
                        return False
        except Exception as e:
            logger.error(f"❌ Connection error: {e}")
            return False
    
    async def update_single_post(self, session: aiohttp.ClientSession, post_data: Dict[str, Any], post_index: int) -> str:
        """Update a single post with new banner URL"""
        async with self.semaphore:
            # Update banner URLs in post data
            original_banner_url = post_data.get('banner_url', '')
            original_media_banner_path = ''
            
            if post_data.get('media_file_banner') and post_data['media_file_banner'].get('path'):
                original_media_banner_path = post_data['media_file_banner']['path']
            
            # Update URLs
            if original_banner_url:
                post_data['banner_url'] = self.update_banner_url(original_banner_url)
            
            if original_media_banner_path:
                post_data['media_file_banner']['path'] = self.update_banner_url(original_media_banner_path)
            
            # Check if any URLs were actually updated
            url_changed = (
                (original_banner_url and post_data.get('banner_url') != original_banner_url) or
                (original_media_banner_path and post_data.get('media_file_banner', {}).get('path') != original_media_banner_path)
            )
            
            if not url_changed:
                self.skipped += 1
                logger.info(f"[{post_index}] SKIPPED: {post_data.get('title', 'Unknown')} (no URL changes needed)")
                return 'skipped'
            
            for attempt in range(self.retry_attempts):
                try:
                    # Add delay between requests
                    if self.request_delay > 0:
                        await asyncio.sleep(self.request_delay)
                    
                    payload = {
                        'post_data': post_data,
                        'api_key': self.api_key,
                        'force_replace': True  # Always force replace for updates
                    }
                    
                    async with session.post(self.api_endpoint, json=payload) as response:
                        if response.status == 200:
                            result = await response.json()
                            if result.get('success'):
                                self.updated += 1
                                logger.info(f"[{post_index}] UPDATED: {post_data.get('title', 'Unknown')}")
                                if original_banner_url != post_data.get('banner_url'):
                                    logger.info(f"   Banner URL: {original_banner_url} -> {post_data.get('banner_url')}")
                                if original_media_banner_path != post_data.get('media_file_banner', {}).get('path'):
                                    logger.info(f"   Media Banner: {original_media_banner_path} -> {post_data.get('media_file_banner', {}).get('path')}")
                                return 'updated'
                            else:
                                error_msg = result.get('error', 'Unknown error')
                                if attempt < self.retry_attempts - 1:
                                    wait_time = 2 ** attempt
                                    logger.warning(f"[{post_index}] RETRY {attempt + 1}: {post_data.get('title', 'Unknown')} - {error_msg}")
                                    await asyncio.sleep(wait_time)
                                    continue
                                else:
                                    logger.error(f"[{post_index}] FINAL FAILURE: {post_data.get('title', 'Unknown')} - {error_msg}")
                        else:
                            error_text = f"HTTP {response.status}"
                            try:
                                error_response = await response.json()
                                if error_response.get('error'):
                                    error_text += f": {error_response['error']}"
                            except:
                                error_text += f": {await response.text()}"
                            
                            if attempt < self.retry_attempts - 1:
                                wait_time = 2 ** attempt
                                logger.warning(f"[{post_index}] RETRY {attempt + 1}: {post_data.get('title', 'Unknown')} - {error_text}")
                                await asyncio.sleep(wait_time)
                                continue
                            else:
                                logger.error(f"[{post_index}] FINAL FAILURE: {post_data.get('title', 'Unknown')} - {error_text}")
                
                except Exception as e:
                    if attempt < self.retry_attempts - 1:
                        wait_time = 2 ** attempt
                        logger.warning(f"[{post_index}] EXCEPTION RETRY {attempt + 1}: {post_data.get('title', 'Unknown')} - {str(e)}")
                        await asyncio.sleep(wait_time)
                        continue
                    else:
                        logger.error(f"[{post_index}] EXCEPTION FINAL FAILURE: {post_data.get('title', 'Unknown')} - {str(e)}")
            
            # If we get here, all retries failed
            self.failed += 1
            self.failed_posts.append({
                'index': post_index,
                'title': post_data.get('title', 'Unknown'),
                'error': error_text if 'error_text' in locals() else 'Unknown error after all retries',
                'attempt': self.retry_attempts
            })
            return 'failed'
    
    async def process_batch(self, session: aiohttp.ClientSession, batch_posts: List[Dict[str, Any]], start_index: int) -> None:
        """Process a batch of posts concurrently"""
        tasks = []
        for i, post_data in enumerate(batch_posts):
            post_index = start_index + i
            task = self.update_single_post(session, post_data, post_index)
            tasks.append(task)
        
        # Execute all tasks in the batch concurrently
        await asyncio.gather(*tasks, return_exceptions=True)
    
    def log_progress(self, processed: int, total: int) -> None:
        """Log current progress with time estimates"""
        if self.start_time is None:
            return
        
        elapsed = time.time() - self.start_time
        if elapsed < 1:
            return
        
        rate = processed / elapsed
        remaining = total - processed
        eta_seconds = remaining / rate if rate > 0 else 0
        eta_minutes = eta_seconds / 60
        
        percentage = (processed / total) * 100
        
        logger.info(f"\n📊 PROGRESS: {processed}/{total} ({percentage:.1f}%)")
        logger.info(f"   UPDATED: {self.updated}")
        logger.info(f"   SKIPPED: {self.skipped}")
        logger.info(f"   FAILED: {self.failed}")
        logger.info(f"   RATE: {rate:.1f} posts/sec")
        logger.info(f"   ETA: {eta_minutes:.1f} minutes")
        logger.info("")
    
    async def update_from_json(self, json_file_path: str) -> bool:
        """Update all posts from JSON file with new banner URLs"""
        try:
            logger.info(f"📖 Reading JSON file: {json_file_path}")
            with open(json_file_path, 'r', encoding='utf-8-sig') as f:
                posts_data = json.load(f)
            
            if not isinstance(posts_data, list):
                logger.error("❌ JSON file must contain an array of posts")
                return False
            
            total_posts = len(posts_data)
            logger.info(f"📊 Found {total_posts} posts to process")
            
            # Filter posts that need URL updates
            posts_to_update = []
            for i, post in enumerate(posts_data):
                banner_url = post.get('banner_url', '')
                media_banner_path = post.get('media_file_banner', {}).get('path', '')
                
                if (banner_url and '/filters:format(webp)/' in banner_url) or \
                   (media_banner_path and '/filters:format(webp)/' in media_banner_path):
                    posts_to_update.append((i, post))
            
            if not posts_to_update:
                logger.info("✅ No posts need banner URL updates")
                return True
            
            logger.info(f"📊 {len(posts_to_update)} posts need banner URL updates")
            
            # Test connection first
            if not await self.test_connection():
                return False
            
            logger.info(f"\n🚀 Starting banner image updates with {self.max_concurrent} concurrent connections")
            logger.info(f"Batch size: {self.batch_size} posts")
            logger.info(f"Request delay: {self.request_delay}s")
            logger.info("")
            
            self.start_time = time.time()
            
            # Configure session
            timeout = aiohttp.ClientTimeout(total=self.request_timeout)
            connector = aiohttp.TCPConnector(
                limit=self.max_concurrent * 2,
                limit_per_host=self.max_concurrent,
                ttl_dns_cache=300,
                use_dns_cache=True,
                keepalive_timeout=60,
                enable_cleanup_closed=True
            )
            
            async with aiohttp.ClientSession(
                timeout=timeout,
                connector=connector,
                headers={
                    'Content-Type': 'application/json',
                    'X-API-Key': self.api_key,
                    'User-Agent': 'BannerImageUpdater/1.0'
                }
            ) as session:
                
                # Process posts in batches
                for i in range(0, len(posts_to_update), self.batch_size):
                    batch_posts = [post[1] for post in posts_to_update[i:i + self.batch_size]]
                    start_index = posts_to_update[i][0]
                    
                    logger.info(f"Processing update batch {i//self.batch_size + 1}/{(len(posts_to_update) + self.batch_size - 1)//self.batch_size}")
                    
                    await self.process_batch(session, batch_posts, start_index)
                    
                    # Log progress every batch
                    processed = min(i + self.batch_size, len(posts_to_update))
                    self.log_progress(processed, len(posts_to_update))
            
            # Final summary
            total_time = time.time() - self.start_time
            final_rate = len(posts_to_update) / total_time if total_time > 0 else 0
            
            logger.info(f"\n🏁 Banner update completed in {total_time/60:.1f} minutes ({total_time:.1f} seconds)")
            logger.info(f"📊 Final Statistics:")
            logger.info(f"   UPDATED: {self.updated}")
            logger.info(f"   SKIPPED: {self.skipped}")
            logger.info(f"   FAILED: {self.failed}")
            logger.info(f"   AVERAGE RATE: {final_rate:.1f} posts/second")
            
            # Save failed posts log
            if self.failed_posts:
                failed_log = f"banner_update_failed_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
                with open(failed_log, 'w', encoding='utf-8') as f:
                    json.dump(self.failed_posts, f, indent=2, ensure_ascii=False)
                logger.info(f"💾 Failed updates saved to: {failed_log}")
            
            success_rate = ((self.updated + self.skipped) / len(posts_to_update)) * 100
            logger.info(f"📊 Update success rate: {success_rate:.1f}%")
            
            return success_rate >= 95  # Consider 95%+ success rate as successful
            
        except Exception as e:
            logger.error(f"❌ Update failed: {e}")
            return False

def main():
    if len(sys.argv) < 4:
        print("Usage: python bulk_update_banners.py <wordpress_url> <api_key> <json_file> [max_concurrent]")
        print("")
        print("Example:")
        print("  python bulk_update_banners.py https://climaterural.com/ your-api-key posts.json 10")
        print("")
        print("This script will:")
        print("  - Update all posts with new banner URLs (remove resize/filter parameters)")
        print("  - Replace existing featured images with new ones")
        print("  - Delete old featured images to save space")
        sys.exit(1)
    
    wordpress_url = sys.argv[1]
    api_key = sys.argv[2]
    json_file = sys.argv[3]
    max_concurrent = int(sys.argv[4]) if len(sys.argv) > 4 else 10
    
    # Create updater instance
    updater = BannerImageUpdater(wordpress_url, api_key)
    updater.max_concurrent = max_concurrent
    updater.semaphore = asyncio.Semaphore(max_concurrent)
    
    logger.info(f"🔄 Starting banner image bulk update")
    logger.info(f"WordPress URL: {wordpress_url}")
    logger.info(f"JSON file: {json_file}")
    logger.info(f"Max concurrent: {max_concurrent}")
    
    # Run the update
    try:
        success = asyncio.run(updater.update_from_json(json_file))
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        logger.info("\n⏹️ Update cancelled by user")
        sys.exit(2)
    except Exception as e:
        logger.error(f"💥 Fatal error during update: {e}")
        sys.exit(3)

if __name__ == "__main__":
    main()
