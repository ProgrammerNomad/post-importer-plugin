#!/usr/bin/env python3
"""
Complete Image URL Update Tool - Async Version
Updates both featured images and content images for all WordPress posts
Transforms image URLs on-the-fly and replaces existing images to avoid duplicates
"""

import asyncio
import aiohttp
import json
import re
import sys
import time
import logging
from datetime import datetime
from typing import List, Dict, Any

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('image_update_log.txt', encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

class AsyncImageUpdater:
    def __init__(self, wordpress_url: str, api_key: str, max_concurrent: int = 15):
        self.wordpress_url = wordpress_url.rstrip('/')
        self.api_key = api_key
        self.update_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/update-images"
        self.status_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/status"
        
        # Performance settings
        self.max_concurrent = max_concurrent
        self.request_timeout = 120  # 2 minutes timeout per request
        self.retry_attempts = 3
        self.request_delay = 0.3  # Delay between requests
        
        # Statistics
        self.updated = 0
        self.failed = 0
        self.skipped = 0
        self.failed_posts = []
        self.start_time = None
        
        # Rate limiting
        self.semaphore = asyncio.Semaphore(self.max_concurrent)
    
    def transform_image_url(self, url: str) -> str:
        """Transform image URL by removing resize/filter parameters"""
        if not url:
            return url
        
        # Pattern to match and remove: /640x480/filters:format(webp)/
        pattern = r'/\d+x\d+/filters:format\(webp\)/'
        transformed_url = re.sub(pattern, '/', url)
        
        # Also handle variations like /fit-in/640x480/filters:format(webp)/
        pattern2 = r'/fit-in/\d+x\d+/filters:format\(webp\)/'
        transformed_url = re.sub(pattern2, '/fit-in/', transformed_url)
        
        return transformed_url
    
    def transform_post_data(self, post_data: Dict[str, Any]) -> Dict[str, Any]:
        """Transform all image URLs in post data"""
        # Transform featured image URLs
        if 'banner_url' in post_data and post_data['banner_url']:
            original_url = post_data['banner_url']
            post_data['banner_url'] = self.transform_image_url(original_url)
            if original_url != post_data['banner_url']:
                logger.debug(f"Transformed banner_url: {original_url} -> {post_data['banner_url']}")
        
        # Transform media_file_banner path
        if 'media_file_banner' in post_data and post_data['media_file_banner']:
            if 'path' in post_data['media_file_banner'] and post_data['media_file_banner']['path']:
                original_path = post_data['media_file_banner']['path']
                post_data['media_file_banner']['path'] = self.transform_image_url(original_path)
                if original_path != post_data['media_file_banner']['path']:
                    logger.debug(f"Transformed media_file_banner.path: {original_path} -> {post_data['media_file_banner']['path']}")
        
        # Transform content images
        if 'content' in post_data and post_data['content']:
            post_data['content'] = self.transform_content_images(post_data['content'])
        
        return post_data
    
    def transform_content_images(self, content: str) -> str:
        """Transform image URLs in post content"""
        if not content:
            return content
        
        # Find all img tags and transform their src URLs
        def replace_src(match):
            full_tag = match.group(0)
            src_url = match.group(1)
            transformed_url = self.transform_image_url(src_url)
            
            if transformed_url != src_url:
                logger.debug(f"Transformed content image: {src_url} -> {transformed_url}")
                return full_tag.replace(src_url, transformed_url)
            return full_tag
        
        # Pattern to match img tags with src attribute
        img_pattern = r'<img[^>]+src=["\']([^"\']+)["\'][^>]*>'
        transformed_content = re.sub(img_pattern, replace_src, content)
        
        return transformed_content
    
    async def test_connection(self) -> bool:
        """Test API connection"""
        try:
            timeout = aiohttp.ClientTimeout(total=10)
            async with aiohttp.ClientSession(timeout=timeout) as session:
                async with session.get(
                    self.status_endpoint,
                    headers={'X-API-Key': self.api_key}
                ) as response:
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
        """Update a single post with transformed image URLs"""
        async with self.semaphore:
            title = post_data.get('title', 'Unknown')
            
            for attempt in range(self.retry_attempts):
                try:
                    # Transform image URLs
                    transformed_data = self.transform_post_data(post_data.copy())
                    
                    # Prepare payload
                    payload = {
                        'post_data': transformed_data,
                        'api_key': self.api_key,
                        'force_replace': True,  # Always replace images
                        'update_images_only': True  # New flag for image-only updates
                    }
                    
                    # Make request
                    async with session.post(
                        self.update_endpoint,
                        json=payload,
                        headers={
                            'Content-Type': 'application/json',
                            'X-API-Key': self.api_key
                        }
                    ) as response:
                        
                        response_text = await response.text()
                        
                        if response.status == 200:
                            result = await response.json()
                            if result.get('success'):
                                logger.info(f"✅ [{post_index:4d}] Updated images: {title}")
                                self.updated += 1
                                return 'updated'
                            else:
                                error = result.get('error', 'Unknown error')
                                logger.warning(f"⚠️  [{post_index:4d}] API Error: {title} - {error}")
                                if attempt == self.retry_attempts - 1:
                                    self.failed += 1
                                    self.failed_posts.append({
                                        'index': post_index,
                                        'title': title,
                                        'error': f"API Error: {error}",
                                        'attempt': attempt + 1
                                    })
                                    return 'failed'
                        else:
                            error_msg = f"HTTP {response.status}: {response_text[:200]}"
                            logger.warning(f"⚠️  [{post_index:4d}] HTTP RETRY {attempt + 1}: {title} - {error_msg}")
                            
                            if attempt == self.retry_attempts - 1:
                                logger.error(f"❌ [{post_index:4d}] HTTP FINAL FAILURE: {title} - {error_msg}")
                                self.failed += 1
                                self.failed_posts.append({
                                    'index': post_index,
                                    'title': title,
                                    'error': error_msg,
                                    'attempt': attempt + 1
                                })
                                return 'failed'
                    
                    # Wait before retry
                    if attempt < self.retry_attempts - 1:
                        await asyncio.sleep(2 ** attempt)  # Exponential backoff
                
                except Exception as e:
                    error_msg = f"Exception: {str(e)}"
                    logger.warning(f"⚠️  [{post_index:4d}] RETRY {attempt + 1}: {title} - {error_msg}")
                    
                    if attempt == self.retry_attempts - 1:
                        logger.error(f"❌ [{post_index:4d}] FINAL FAILURE: {title} - {error_msg}")
                        self.failed += 1
                        self.failed_posts.append({
                            'index': post_index,
                            'title': title,
                            'error': error_msg,
                            'attempt': attempt + 1
                        })
                        return 'failed'
                    
                    await asyncio.sleep(2 ** attempt)
            
            # Add small delay between requests
            if self.request_delay > 0:
                await asyncio.sleep(self.request_delay)
            
            return 'failed'
    
    async def process_batch(self, session: aiohttp.ClientSession, batch_posts: List[Dict[str, Any]], start_index: int) -> None:
        """Process a batch of posts concurrently"""
        tasks = []
        for i, post_data in enumerate(batch_posts):
            post_index = start_index + i + 1
            task = asyncio.create_task(
                self.update_single_post(session, post_data, post_index)
            )
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
        logger.info(f"   ✅ UPDATED: {self.updated}")
        logger.info(f"   ⏭️  SKIPPED: {self.skipped}")
        logger.info(f"   ❌ FAILED: {self.failed}")
        logger.info(f"   📈 RATE: {rate:.1f} posts/sec")
        logger.info(f"   ⏱️  ETA: {eta_minutes:.1f} minutes")
        logger.info("")
    
    async def update_all_images(self, json_file_path: str) -> bool:
        """Update images for all posts from JSON file"""
        try:
            logger.info(f"📖 Reading JSON file: {json_file_path}")
            with open(json_file_path, 'r', encoding='utf-8-sig') as f:
                posts_data = json.load(f)
            
            if not isinstance(posts_data, list):
                logger.error("❌ JSON file must contain an array of posts")
                return False
            
            total_posts = len(posts_data)
            logger.info(f"📊 Found {total_posts} posts to update")
            
            # Test connection first
            if not await self.test_connection():
                return False
            
            logger.info(f"\n🚀 Starting image update with {self.max_concurrent} concurrent connections")
            logger.info(f"📦 Batch size: 50 posts")
            logger.info(f"⏱️  Request delay: {self.request_delay}s")
            logger.info(f"🔄 Retry attempts: {self.retry_attempts}")
            logger.info("")
            
            self.start_time = time.time()
            
            # Configure session with optimized settings
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
                    'User-Agent': 'AsyncImageUpdater/1.0'
                }
            ) as session:
                
                # Process posts in batches
                batch_size = 50
                for i in range(0, total_posts, batch_size):
                    batch_posts = posts_data[i:i + batch_size]
                    batch_num = (i // batch_size) + 1
                    total_batches = (total_posts + batch_size - 1) // batch_size
                    
                    logger.info(f"🔄 Processing batch {batch_num}/{total_batches}")
                    
                    await self.process_batch(session, batch_posts, i)
                    
                    # Log progress every batch
                    processed = min(i + batch_size, total_posts)
                    self.log_progress(processed, total_posts)
            
            # Final summary
            total_time = time.time() - self.start_time
            final_rate = total_posts / total_time if total_time > 0 else 0
            
            logger.info(f"\n🎉 IMAGE UPDATE COMPLETED!")
            logger.info(f"⏱️  Total time: {total_time/60:.1f} minutes ({total_time:.1f} seconds)")
            logger.info(f"📊 Final Statistics:")
            logger.info(f"   ✅ UPDATED: {self.updated}")
            logger.info(f"   ⏭️  SKIPPED: {self.skipped}")
            logger.info(f"   ❌ FAILED: {self.failed}")
            logger.info(f"   📈 AVERAGE RATE: {final_rate:.1f} posts/second")
            
            # Save failed posts log
            if self.failed_posts:
                failed_log = f"failed_image_updates_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
                with open(failed_log, 'w', encoding='utf-8') as f:
                    json.dump(self.failed_posts, f, indent=2, ensure_ascii=False)
                logger.info(f"💾 Failed posts saved to: {failed_log}")
            
            success_rate = ((self.updated + self.skipped) / total_posts) * 100
            logger.info(f"🎯 Success rate: {success_rate:.1f}%")
            
            return success_rate >= 80  # Consider 80%+ success rate as successful
            
        except Exception as e:
            logger.error(f"❌ Update failed: {e}")
            return False

def main():
    if len(sys.argv) < 4:
        print("🖼️  Complete Image URL Update Tool - Async Version")
        print("=" * 60)
        print()
        print("This tool will:")
        print("✅ Transform image URLs (remove 640x480/filters:format(webp)/)")
        print("✅ Update featured images for all posts")
        print("✅ Update content images in post content")
        print("✅ Replace existing images (no duplicates)")
        print("✅ Use fast async processing")
        print()
        print("Usage:")
        print("  python update_all_images_async.py <wordpress_url> <api_key> <json_file> [max_concurrent]")
        print()
        print("Examples:")
        print("  python update_all_images_async.py https://climaterural.com/ your-api-key posts.json")
        print("  python update_all_images_async.py https://climaterural.com/ your-api-key posts.json 20")
        print()
        print("Parameters:")
        print("  wordpress_url   : Your WordPress site URL")
        print("  api_key        : API key for authentication")
        print("  json_file      : Path to JSON file with posts")
        print("  max_concurrent : Maximum concurrent requests (default: 15)")
        print()
        sys.exit(1)
    
    wordpress_url = sys.argv[1]
    api_key = sys.argv[2]
    json_file = sys.argv[3]
    max_concurrent = int(sys.argv[4]) if len(sys.argv) > 4 else 15
    
    # Create updater instance
    updater = AsyncImageUpdater(wordpress_url, api_key, max_concurrent)
    
    logger.info("🖼️  Starting Complete Image URL Update Process")
    logger.info("=" * 60)
    logger.info(f"WordPress URL: {wordpress_url}")
    logger.info(f"JSON file: {json_file}")
    logger.info(f"Max concurrent: {max_concurrent}")
    logger.info(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    logger.info("")
    
    # Run the async update
    try:
        success = asyncio.run(updater.update_all_images(json_file))
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        logger.info("\n⏹️  Update cancelled by user")
        sys.exit(2)
    except Exception as e:
        logger.error(f"💥 Fatal error: {e}")
        sys.exit(3)

if __name__ == "__main__":
    main()
