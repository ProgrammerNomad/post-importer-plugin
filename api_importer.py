import json
import requests
import time
import sys
from datetime import datetime

class WordPressAPIImporter:
    def __init__(self, wordpress_url, api_key, force_replace=False):
        self.wordpress_url = wordpress_url.rstrip('/')
        self.api_key = api_key
        self.force_replace = force_replace
        self.api_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/import-post"
        self.status_endpoint = f"{self.wordpress_url}/wp-json/post-importer/v1/status"
        self.session = requests.Session()
        self.session.headers.update({
            'Content-Type': 'application/json',
            'X-API-Key': self.api_key
        })
        
        # Stats
        self.imported = 0
        self.failed = 0
        self.skipped = 0
        self.failed_posts = []
    
    def test_connection(self):
        """Test API connection"""
        try:
            response = self.session.get(self.status_endpoint)
            if response.status_code == 200:
                data = response.json()
                print(f"✅ Connected to WordPress API")
                print(f"   WordPress Version: {data.get('wordpress_version', 'Unknown')}")
                print(f"   Plugin Version: {data.get('plugin_version', 'Unknown')}")
                return True
            else:
                print(f"❌ API connection failed: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Connection error: {e}")
            return False
    
    def import_single_post(self, post_data):
        """Import a single post via API"""
        try:
            payload = {
                'post_data': post_data,
                'api_key': self.api_key,
                'force_replace': self.force_replace
            }
            
            response = self.session.post(self.api_endpoint, json=payload, timeout=60)
            
            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    status = result.get('result', 'unknown')
                    if status == 'imported':
                        self.imported += 1
                        print(f"✅ Imported: {post_data.get('title', 'Unknown')}")
                    elif status == 'skipped':
                        self.skipped += 1
                        print(f"⏭️  Skipped: {post_data.get('title', 'Unknown')} (already exists)")
                    return True
                else:
                    self.failed += 1
                    error = result.get('error', 'Unknown error')
                    print(f"❌ Failed: {post_data.get('title', 'Unknown')} - {error}")
                    self.failed_posts.append({
                        'title': post_data.get('title', 'Unknown'),
                        'error': error,
                        'data': post_data
                    })
                    return False
            else:
                self.failed += 1
                error_text = f"HTTP {response.status_code}"
                try:
                    error_response = response.json()
                    if error_response.get('error'):
                        error_text += f": {error_response['error']}"
                except:
                    error_text += f": {response.text[:200]}"
                
                print(f"❌ {error_text}: {post_data.get('title', 'Unknown')}")
                self.failed_posts.append({
                    'title': post_data.get('title', 'Unknown'),
                    'error': error_text,
                    'data': post_data
                })
                return False
                
        except Exception as e:
            self.failed += 1
            print(f"❌ Exception: {post_data.get('title', 'Unknown')} - {e}")
            self.failed_posts.append({
                'title': post_data.get('title', 'Unknown'),
                'error': str(e),
                'data': post_data
            })
            return False
    
    def import_from_json(self, json_file_path, delay=1.0):
        """Import all posts from JSON file"""
        try:
            print(f"📖 Reading JSON file: {json_file_path}")
            with open(json_file_path, 'r', encoding='utf-8-sig') as f:
                posts_data = json.load(f)
            
            if not isinstance(posts_data, list):
                print("❌ JSON file must contain an array of posts")
                return False
            
            total_posts = len(posts_data)
            print(f"📊 Found {total_posts} posts to import")
            
            # Test connection first
            if not self.test_connection():
                return False
            
            print(f"\n🚀 Starting import with {delay}s delay between posts...")
            if self.force_replace:
                print("⚠️  Force replace mode enabled - existing posts will be updated")
            print()
            
            start_time = time.time()
            
            for i, post_data in enumerate(posts_data, 1):
                print(f"[{i}/{total_posts}] ", end="")
                self.import_single_post(post_data)
                
                # Progress update every 10 posts
                if i % 10 == 0:
                    elapsed = time.time() - start_time
                    avg_time = elapsed / i
                    remaining = (total_posts - i) * avg_time
                    print(f"\n📈 Progress: {i}/{total_posts} ({(i/total_posts)*100:.1f}%)")
                    print(f"   Imported: {self.imported}, Skipped: {self.skipped}, Failed: {self.failed}")
                    print(f"   Estimated time remaining: {remaining/60:.1f} minutes\n")
                
                # Delay between requests
                if delay > 0 and i < total_posts:
                    time.sleep(delay)
            
            # Final summary
            total_time = time.time() - start_time
            print(f"\n🏁 Import completed in {total_time/60:.1f} minutes")
            print(f"📊 Final Stats:")
            print(f"   ✅ Imported: {self.imported}")
            print(f"   ⏭️  Skipped: {self.skipped}")
            print(f"   ❌ Failed: {self.failed}")
            
            # Save failed posts log
            if self.failed_posts:
                failed_log = f"failed_imports_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
                with open(failed_log, 'w', encoding='utf-8') as f:
                    json.dump(self.failed_posts, f, indent=2, ensure_ascii=False)
                print(f"💾 Failed posts saved to: {failed_log}")
            
            return True
            
        except Exception as e:
            print(f"❌ Import failed: {e}")
            return False

def main():
    if len(sys.argv) < 4:
        print("Usage: python api_importer.py <wordpress_url> <api_key> <json_file> [delay_seconds] [force_replace]")
        print("Example: python api_importer.py http://localhost/wordpress your-api-key posts.json 1 false")
        sys.exit(1)
    
    wordpress_url = sys.argv[1]
    api_key = sys.argv[2]
    json_file = sys.argv[3]
    delay = float(sys.argv[4]) if len(sys.argv) > 4 else 1
    force_replace = sys.argv[5].lower() == 'true' if len(sys.argv) > 5 else False
    
    importer = WordPressAPIImporter(wordpress_url, api_key, force_replace)
    success = importer.import_from_json(json_file, delay)
    
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()