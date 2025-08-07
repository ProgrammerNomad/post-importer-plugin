#!/usr/bin/env python3
"""
Update Banner URLs in JSON file
Removes '640x480/filters:format(webp)/' from all banner_url and media_file_banner.path URLs
"""

import json
import re
import sys
from datetime import datetime

def update_banner_urls(json_file_path, output_file_path=None):
    """
    Update banner URLs in JSON file by removing the resize/filter parameters
    
    Changes:
    https://img-cdn.publive.online/fit-in/640x480/filters:format(webp)/ground-report/...
    to:
    https://img-cdn.publive.online/fit-in/ground-report/...
    """
    
    if not output_file_path:
        # Create backup filename
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        output_file_path = json_file_path.replace('.json', f'_updated_urls_{timestamp}.json')
    
    print(f"📖 Reading JSON file: {json_file_path}")
    
    try:
        with open(json_file_path, 'r', encoding='utf-8-sig') as f:
            posts_data = json.load(f)
    except Exception as e:
        print(f"❌ Error reading JSON file: {e}")
        return False
    
    if not isinstance(posts_data, list):
        print("❌ JSON file must contain an array of posts")
        return False
    
    print(f"📊 Found {len(posts_data)} posts to process")
    
    # Pattern to match and remove the resize/filter parameters
    # Matches: /640x480/filters:format(webp)/
    pattern = r'/\d+x\d+/filters:format\(webp\)/'
    
    updated_count = 0
    
    for i, post in enumerate(posts_data):
        post_updated = False
        
        # Update banner_url
        if 'banner_url' in post and post['banner_url']:
            original_url = post['banner_url']
            updated_url = re.sub(pattern, '/', original_url)
            
            if updated_url != original_url:
                post['banner_url'] = updated_url
                post_updated = True
                print(f"✅ Updated banner_url for post [{i+1}] {post.get('title', 'Unknown')}")
                print(f"   From: {original_url}")
                print(f"   To:   {updated_url}")
        
        # Update media_file_banner.path
        if 'media_file_banner' in post and post['media_file_banner']:
            if 'path' in post['media_file_banner'] and post['media_file_banner']['path']:
                original_path = post['media_file_banner']['path']
                updated_path = re.sub(pattern, '/', original_path)
                
                if updated_path != original_path:
                    post['media_file_banner']['path'] = updated_path
                    post_updated = True
                    print(f"✅ Updated media_file_banner.path for post [{i+1}] {post.get('title', 'Unknown')}")
                    print(f"   From: {original_path}")
                    print(f"   To:   {updated_path}")
        
        if post_updated:
            updated_count += 1
    
    print(f"\n📈 Summary:")
    print(f"   Total posts processed: {len(posts_data)}")
    print(f"   Posts with updated URLs: {updated_count}")
    
    # Save updated JSON
    print(f"\n💾 Saving updated JSON to: {output_file_path}")
    try:
        with open(output_file_path, 'w', encoding='utf-8') as f:
            json.dump(posts_data, f, indent=2, ensure_ascii=False)
        
        print(f"✅ Successfully saved updated JSON file")
        print(f"📁 Original file: {json_file_path}")
        print(f"📁 Updated file: {output_file_path}")
        
        return True
        
    except Exception as e:
        print(f"❌ Error saving updated JSON file: {e}")
        return False

def main():
    if len(sys.argv) < 2:
        print("Usage: python update_banner_urls.py <json_file> [output_file]")
        print("")
        print("Example:")
        print("  python update_banner_urls.py posts.json")
        print("  python update_banner_urls.py posts.json posts_updated.json")
        print("")
        print("This script will:")
        print("  - Remove '640x480/filters:format(webp)/' from banner_url")
        print("  - Remove '640x480/filters:format(webp)/' from media_file_banner.path")
        print("  - Create a new JSON file with updated URLs")
        sys.exit(1)
    
    json_file = sys.argv[1]
    output_file = sys.argv[2] if len(sys.argv) > 2 else None
    
    success = update_banner_urls(json_file, output_file)
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()
