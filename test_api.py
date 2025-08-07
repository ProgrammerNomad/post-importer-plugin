#!/usr/bin/env python3
"""
Simple test script to check the WordPress API endpoints
"""
import json
import requests

def test_api():
    wordpress_url = "https://climaterural.com"
    api_key = "climaterural-secret-key-2025"
    
    # Test status endpoint
    status_url = f"{wordpress_url}/wp-json/post-importer/v1/status"
    print(f"🔍 Testing status endpoint: {status_url}")
    
    try:
        response = requests.get(status_url, params={'api_key': api_key}, timeout=10)
        print(f"Status Code: {response.status_code}")
        print(f"Response: {response.text}")
        
        if response.status_code == 200:
            result = response.json()
            print("✅ Status endpoint working!")
            print(f"WordPress Version: {result.get('wordpress_version', 'Unknown')}")
            print(f"Plugin Version: {result.get('plugin_version', 'Unknown')}")
            print(f"Imported Posts: {result.get('imported_posts', 0)}")
        else:
            print("❌ Status endpoint failed")
            return False
            
    except Exception as e:
        print(f"❌ Status endpoint error: {e}")
        return False
    
    # Test import endpoint with a minimal post
    import_url = f"{wordpress_url}/wp-json/post-importer/v1/import-post"
    print(f"\n🔍 Testing import endpoint: {import_url}")
    
    test_post = {
        'title': 'API Test Post',
        'slug': 'api-test-post-' + str(int(time.time())),
        'content': 'This is a test post from the API.',
        'categories': [],
        'tags': [],
        'id': 'test-' + str(int(time.time())),
        'formatted_first_published_at_datetime': '2025-08-07 12:00:00',
        'formatted_last_published_at_datetime': '2025-08-07 12:00:00'
    }
    
    payload = {
        'post_data': test_post,
        'api_key': api_key,
        'force_replace': False
    }
    
    try:
        response = requests.post(import_url, json=payload, timeout=30)
        print(f"Status Code: {response.status_code}")
        print(f"Response: {response.text}")
        
        if response.status_code == 200:
            result = response.json()
            if result.get('success'):
                print("✅ Import endpoint working!")
                print(f"Result: {result.get('result')}")
                print(f"Post ID: {result.get('post_id', 'N/A')}")
            else:
                print(f"❌ Import failed: {result.get('error')}")
                return False
        else:
            print("❌ Import endpoint failed")
            return False
            
    except Exception as e:
        print(f"❌ Import endpoint error: {e}")
        return False
    
    return True

if __name__ == "__main__":
    import time
    print("🧪 Testing WordPress Post Importer API\n")
    
    if test_api():
        print("\n🎉 All tests passed! API is working correctly.")
    else:
        print("\n💥 Tests failed! Check the error messages above.")
