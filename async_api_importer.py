#!/usr/bin/env python3
"""
Asynchronous WordPress Post Importer - High Performance Version
Designed to import 10,000+ posts in 1-2 hours using concurrent requests
"""
import asyncio
import aiohttp
import json
import time
import sys
from datetime import datetime
from typing import List, Dict, Any
import logging

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('import_log.txt'),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger(__name__)

class AsyncWordPressImporter:
    def __init__(self, wordpress_url: str, api_key: str, force_replace: bool = False):
        self.wordpress_url = wordpress_url.rstrip('/')
        self.api_key = api_key
        self.force_replace = force_replace
        self.api_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/import-post"
        self.status_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/status"
        
        # Performance settings
        self.max_concurrent = 20  # Maximum concurrent requests
        self.request_timeout = 60  # Timeout per request in seconds
        self.batch_size = 100  # Posts to process per batch
        self.retry_attempts = 3  # Retry failed requests
        
        # Statistics
        self.imported = 0
        self.failed = 0
        self.skipped = 0
        self.failed_posts = []
        self.start_time = None
        
        # Rate limiting
        self.semaphore = asyncio.Semaphore(self.max_concurrent)
        self.request_delay = 0.1  # Small delay between requests (100ms)
    
    async def test_connection(self) -> bool:
        """Test API connection"""
        try:
            timeout = aiohttp.ClientTimeout(total=10)
            async with aiohttp.ClientSession(timeout=timeout) as session:
                params = {'api_key': self.api_key}
                async with session.get(self.status_endpoint, params=params) as response:
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
    
    async def import_single_post(self, session: aiohttp.ClientSession, post_data: Dict[str, Any], post_index: int) -> str:
        """Import a single post with retry logic"""
        async with self.semaphore:  # Limit concurrent requests
            for attempt in range(self.retry_attempts):
                try:
                    payload = {
                        'post_data': post_data,
                        'api_key': self.api_key,
                        'force_replace': self.force_replace
                    }
                    
                    async with session.post(self.api_endpoint, json=payload) as response:
                        if response.status == 200:
                            result = await response.json()
                            if result.get('success'):
                                status = result.get('result', 'unknown')
                                if status == 'imported':
                                    self.imported += 1
                                    logger.info(f"✅ [{post_index}] Imported: {post_data.get('title', 'Unknown')}")
                                    return 'imported'
                                elif status == 'skipped':
                                    self.skipped += 1
                                    logger.info(f"⏭️  [{post_index}] Skipped: {post_data.get('title', 'Unknown')}")
                                    return 'skipped'
                                else:
                                    self.failed += 1
                                    error = result.get('error', 'Unknown error')
                                    logger.warning(f"❌ [{post_index}] Failed: {post_data.get('title', 'Unknown')} - {error}")
                                    self.failed_posts.append({
                                        'index': post_index,
                                        'title': post_data.get('title', 'Unknown'),
                                        'error': error,
                                        'attempt': attempt + 1
                                    })
                                    return 'failed'
                            else:
                                error = result.get('error', 'Unknown error')
                                if attempt < self.retry_attempts - 1:
                                    logger.warning(f"🔄 [{post_index}] Retry {attempt + 1}: {post_data.get('title', 'Unknown')} - {error}")
                                    await asyncio.sleep(1 * (attempt + 1))  # Exponential backoff
                                    continue
                                else:
                                    self.failed += 1
                                    logger.error(f"❌ [{post_index}] Final failure: {post_data.get('title', 'Unknown')} - {error}")
                                    self.failed_posts.append({
                                        'index': post_index,
                                        'title': post_data.get('title', 'Unknown'),
                                        'error': error,
                                        'attempt': attempt + 1
                                    })
                                    return 'failed'
                        else:
                            error_text = f"HTTP {response.status}"
                            try:
                                error_response = await response.json()
                                if error_response.get('error'):
                                    error_text += f": {error_response['error']}"
                            except:
                                error_text += f": {await response.text()}"
                            
                            if attempt < self.retry_attempts - 1:
                                logger.warning(f"🔄 [{post_index}] HTTP Retry {attempt + 1}: {post_data.get('title', 'Unknown')} - {error_text}")
                                await asyncio.sleep(2 * (attempt + 1))  # Exponential backoff
                                continue
                            else:
                                self.failed += 1
                                logger.error(f"❌ [{post_index}] HTTP Final failure: {post_data.get('title', 'Unknown')} - {error_text}")
                                self.failed_posts.append({
                                    'index': post_index,
                                    'title': post_data.get('title', 'Unknown'),
                                    'error': error_text,
                                    'attempt': attempt + 1
                                })
                                return 'failed'
                    
                    # Small delay between requests
                    if self.request_delay > 0:
                        await asyncio.sleep(self.request_delay)
                        
                except asyncio.TimeoutError:
                    if attempt < self.retry_attempts - 1:
                        logger.warning(f"⏰ [{post_index}] Timeout retry {attempt + 1}: {post_data.get('title', 'Unknown')}")
                        await asyncio.sleep(3 * (attempt + 1))
                        continue
                    else:
                        self.failed += 1
                        logger.error(f"⏰ [{post_index}] Timeout final failure: {post_data.get('title', 'Unknown')}")
                        self.failed_posts.append({
                            'index': post_index,
                            'title': post_data.get('title', 'Unknown'),
                            'error': 'Request timeout',
                            'attempt': attempt + 1
                        })
                        return 'failed'
                except Exception as e:
                    if attempt < self.retry_attempts - 1:
                        logger.warning(f"💥 [{post_index}] Exception retry {attempt + 1}: {post_data.get('title', 'Unknown')} - {e}")
                        await asyncio.sleep(2 * (attempt + 1))
                        continue
                    else:
                        self.failed += 1
                        logger.error(f"💥 [{post_index}] Exception final failure: {post_data.get('title', 'Unknown')} - {e}")
                        self.failed_posts.append({
                            'index': post_index,
                            'title': post_data.get('title', 'Unknown'),
                            'error': str(e),
                            'attempt': attempt + 1
                        })
                        return 'failed'
            
            # This should never be reached, but just in case
            return 'failed'
    
    async def process_batch(self, session: aiohttp.ClientSession, batch_posts: List[Dict[str, Any]], start_index: int) -> None:
        """Process a batch of posts concurrently"""
        tasks = []
        for i, post_data in enumerate(batch_posts):
            post_index = start_index + i + 1
            task = self.import_single_post(session, post_data, post_index)
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
        
        logger.info(f"\n📈 Progress: {processed}/{total} ({percentage:.1f}%)")
        logger.info(f"   ✅ Imported: {self.imported}")
        logger.info(f"   ⏭️  Skipped: {self.skipped}")
        logger.info(f"   ❌ Failed: {self.failed}")
        logger.info(f"   ⚡ Rate: {rate:.1f} posts/sec")
        logger.info(f"   ⏱️  ETA: {eta_minutes:.1f} minutes")
        logger.info("")
    
    async def import_from_json(self, json_file_path: str) -> bool:
        """Import all posts from JSON file asynchronously"""
        try:
            logger.info(f"📖 Reading JSON file: {json_file_path}")
            with open(json_file_path, 'r', encoding='utf-8-sig') as f:
                posts_data = json.load(f)
            
            if not isinstance(posts_data, list):
                logger.error("❌ JSON file must contain an array of posts")
                return False
            
            total_posts = len(posts_data)
            logger.info(f"📊 Found {total_posts} posts to import")
            
            # Test connection first
            if not await self.test_connection():
                return False
            
            logger.info(f"\n🚀 Starting async import with {self.max_concurrent} concurrent connections")
            if self.force_replace:
                logger.info("⚠️  Force replace mode enabled - existing posts will be updated")
            logger.info(f"📦 Batch size: {self.batch_size} posts")
            logger.info(f"🔄 Retry attempts: {self.retry_attempts}")
            logger.info("")
            
            self.start_time = time.time()
            
            # Configure session with optimized settings
            timeout = aiohttp.ClientTimeout(total=self.request_timeout)
            connector = aiohttp.TCPConnector(
                limit=self.max_concurrent * 2,  # Connection pool
                limit_per_host=self.max_concurrent,
                ttl_dns_cache=300,  # DNS cache
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
                    'User-Agent': 'AsyncWordPressImporter/1.0'
                }
            ) as session:
                
                # Process posts in batches
                for i in range(0, total_posts, self.batch_size):
                    batch_posts = posts_data[i:i + self.batch_size]
                    
                    logger.info(f"🔄 Processing batch {i//self.batch_size + 1}/{(total_posts + self.batch_size - 1)//self.batch_size}")
                    
                    await self.process_batch(session, batch_posts, i)
                    
                    # Log progress every batch
                    processed = min(i + self.batch_size, total_posts)
                    self.log_progress(processed, total_posts)
                    
                    # Brief pause between batches to prevent overwhelming the server
                    if i + self.batch_size < total_posts:
                        await asyncio.sleep(0.5)
            
            # Final summary
            total_time = time.time() - self.start_time
            final_rate = total_posts / total_time if total_time > 0 else 0
            
            logger.info(f"\n🏁 Import completed in {total_time/60:.1f} minutes ({total_time:.1f} seconds)")
            logger.info(f"📊 Final Statistics:")
            logger.info(f"   ✅ Imported: {self.imported}")
            logger.info(f"   ⏭️  Skipped: {self.skipped}")
            logger.info(f"   ❌ Failed: {self.failed}")
            logger.info(f"   ⚡ Average rate: {final_rate:.1f} posts/second")
            
            # Save failed posts log
            if self.failed_posts:
                failed_log = f"failed_imports_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
                with open(failed_log, 'w', encoding='utf-8') as f:
                    json.dump(self.failed_posts, f, indent=2, ensure_ascii=False)
                logger.info(f"💾 Failed posts saved to: {failed_log}")
                
                # Show sample of failed posts
                logger.info("\n❌ Sample of failed posts:")
                for failed in self.failed_posts[:5]:
                    logger.info(f"   - [{failed['index']}] {failed['title']}: {failed['error']}")
                if len(self.failed_posts) > 5:
                    logger.info(f"   ... and {len(self.failed_posts) - 5} more")
            
            success_rate = ((self.imported + self.skipped) / total_posts) * 100
            logger.info(f"✨ Success rate: {success_rate:.1f}%")
            
            return success_rate >= 95  # Consider 95%+ success rate as successful
            
        except Exception as e:
            logger.error(f"❌ Import failed: {e}")
            return False

def main():
    if len(sys.argv) < 4:
        print("Usage: python async_api_importer.py <wordpress_url> <api_key> <json_file> [force_replace] [max_concurrent]")
        print("Example: python async_api_importer.py https://climaterural.com/ your-api-key posts.json false 20")
        print("")
        print("Parameters:")
        print("  wordpress_url   : Your WordPress site URL")
        print("  api_key        : API key for authentication")
        print("  json_file      : Path to JSON file with posts")
        print("  force_replace  : true/false - Update existing posts (default: false)")
        print("  max_concurrent : Maximum concurrent requests (default: 20)")
        sys.exit(1)
    
    wordpress_url = sys.argv[1]
    api_key = sys.argv[2]
    json_file = sys.argv[3]
    force_replace = sys.argv[4].lower() == 'true' if len(sys.argv) > 4 else False
    max_concurrent = int(sys.argv[5]) if len(sys.argv) > 5 else 20
    
    # Create importer instance
    importer = AsyncWordPressImporter(wordpress_url, api_key, force_replace)
    importer.max_concurrent = max_concurrent
    importer.semaphore = asyncio.Semaphore(max_concurrent)
    
    # Adjust settings based on concurrent connections
    if max_concurrent > 30:
        importer.request_delay = 0.05  # Faster for high concurrency
        importer.batch_size = 200
    elif max_concurrent > 15:
        importer.request_delay = 0.1
        importer.batch_size = 100
    else:
        importer.request_delay = 0.2
        importer.batch_size = 50
    
    logger.info(f"🚀 Starting import with {max_concurrent} concurrent connections")
    logger.info(f"📦 Batch size: {importer.batch_size}")
    logger.info(f"⏱️  Request delay: {importer.request_delay}s")
    
    # Run the async import
    try:
        success = asyncio.run(importer.import_from_json(json_file))
        sys.exit(0 if success else 1)
    except KeyboardInterrupt:
        logger.info("\n🛑 Import cancelled by user")
        sys.exit(2)
    except Exception as e:
        logger.error(f"💥 Fatal error: {e}")
        sys.exit(3)

if __name__ == "__main__":
    main()
