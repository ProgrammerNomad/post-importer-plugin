#!/usr/bin/env python3
"""
Test script to compare sync vs async performance
"""
import time
import asyncio
import json
import requests
import aiohttp

def test_sync_performance(posts, api_url, api_key, max_requests=50):
    """Test synchronous import performance"""
    print(f"🐌 Testing synchronous import ({max_requests} posts)...")
    
    start_time = time.time()
    imported = 0
    failed = 0
    
    session = requests.Session()
    session.headers.update({
        'Content-Type': 'application/json',
        'X-API-Key': api_key
    })
    
    for i, post_data in enumerate(posts[:max_requests]):
        try:
            payload = {
                'post_data': post_data,
                'api_key': api_key,
                'force_replace': False
            }
            
            response = session.post(api_url, json=payload, timeout=30)
            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    imported += 1
                    if i % 10 == 0:
                        print(f"   Progress: {i+1}/{max_requests}")
                else:
                    failed += 1
            else:
                failed += 1
                
        except Exception as e:
            failed += 1
    
    elapsed = time.time() - start_time
    rate = max_requests / elapsed
    
    print(f"   ✅ Completed {max_requests} posts in {elapsed:.1f}s")
    print(f"   📊 Imported: {imported}, Failed: {failed}")
    print(f"   ⚡ Rate: {rate:.1f} posts/second")
    
    return rate

async def test_async_performance(posts, api_url, api_key, max_requests=50, concurrency=10):
    """Test asynchronous import performance"""
    print(f"🚀 Testing asynchronous import ({max_requests} posts, {concurrency} concurrent)...")
    
    start_time = time.time()
    imported = 0
    failed = 0
    
    semaphore = asyncio.Semaphore(concurrency)
    
    async def import_post(session, post_data, index):
        nonlocal imported, failed
        async with semaphore:
            try:
                payload = {
                    'post_data': post_data,
                    'api_key': api_key,
                    'force_replace': False
                }
                
                async with session.post(api_url, json=payload) as response:
                    if response.status == 200:
                        result = await response.json()
                        if result.get('success'):
                            imported += 1
                            if index % 10 == 0:
                                print(f"   Progress: {index+1}/{max_requests}")
                        else:
                            failed += 1
                    else:
                        failed += 1
                        
            except Exception as e:
                failed += 1
    
    timeout = aiohttp.ClientTimeout(total=30)
    async with aiohttp.ClientSession(timeout=timeout) as session:
        tasks = []
        for i, post_data in enumerate(posts[:max_requests]):
            task = import_post(session, post_data, i)
            tasks.append(task)
        
        await asyncio.gather(*tasks)
    
    elapsed = time.time() - start_time
    rate = max_requests / elapsed
    
    print(f"   ✅ Completed {max_requests} posts in {elapsed:.1f}s")
    print(f"   📊 Imported: {imported}, Failed: {failed}")
    print(f"   ⚡ Rate: {rate:.1f} posts/second")
    
    return rate

def estimate_completion_time(total_posts, rate_per_second):
    """Estimate total completion time"""
    total_seconds = total_posts / rate_per_second
    hours = total_seconds / 3600
    minutes = (total_seconds % 3600) / 60
    
    return hours, minutes, total_seconds

async def main():
    wordpress_url = "https://climaterural.com"
    api_key = "climaterural-secret-key-2025"
    api_url = f"{wordpress_url}/wp-json/post-importer/v1/import-post"
    
    # Load some posts for testing
    print("📖 Loading posts for performance testing...")
    try:
        with open('posts.json', 'r', encoding='utf-8-sig') as f:
            posts_data = json.load(f)
        
        if not posts_data:
            print("❌ No posts found in JSON file")
            return
        
        total_posts = len(posts_data)
        print(f"📊 Total posts available: {total_posts}")
        
        # Test with 50 posts
        test_posts = 50
        print(f"\n🧪 Performance Testing with {test_posts} posts:")
        print("=" * 60)
        
        # Test synchronous
        sync_rate = test_sync_performance(posts_data, api_url, api_key, test_posts)
        
        print()
        
        # Test async with different concurrency levels
        for concurrency in [5, 10, 20]:
            async_rate = await test_async_performance(posts_data, api_url, api_key, test_posts, concurrency)
            
            # Calculate improvement
            improvement = (async_rate / sync_rate) * 100 if sync_rate > 0 else 0
            print(f"   🚀 {improvement:.1f}% of sync speed with {concurrency} concurrent\n")
        
        print("=" * 60)
        print("📈 Time Estimates for Full Import:")
        print(f"Total posts: {total_posts}")
        
        # Sync estimate
        sync_hours, sync_minutes, _ = estimate_completion_time(total_posts, sync_rate)
        print(f"Synchronous: {sync_hours:.1f} hours ({sync_minutes:.0f} minutes)")
        
        # Async estimates
        for concurrency in [10, 20, 30]:
            # Estimate async rate (assuming 3-5x improvement)
            estimated_async_rate = sync_rate * (concurrency / 3)  # Conservative estimate
            async_hours, async_minutes, _ = estimate_completion_time(total_posts, estimated_async_rate)
            print(f"Async ({concurrency} concurrent): {async_hours:.1f} hours ({async_minutes:.0f} minutes)")
        
        print("\n💡 Recommendation:")
        if sync_rate < 2:
            print("   Use async with 20-30 concurrent connections for optimal speed")
        elif sync_rate < 5:
            print("   Use async with 15-20 concurrent connections")
        else:
            print("   Your server is fast! Use async with 10-15 concurrent connections")
            
    except Exception as e:
        print(f"❌ Error: {e}")

if __name__ == "__main__":
    asyncio.run(main())
