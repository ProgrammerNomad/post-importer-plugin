#!/usr/bin/env python3
"""
Quick Start: Complete Banner Image URL Update Workflow
This script automates the entire process of updating banner image URLs.
"""

import subprocess
import sys
import os
import time
from datetime import datetime

def run_command(command, description):
    """Run a command and return success status"""
    print(f"\n🔄 {description}")
    print(f"Command: {command}")
    print("-" * 50)
    
    try:
        result = subprocess.run(command, shell=True, capture_output=True, text=True)
        
        if result.returncode == 0:
            print(f"✅ {description} completed successfully")
            if result.stdout.strip():
                print("Output:")
                print(result.stdout)
            return True
        else:
            print(f"❌ {description} failed")
            if result.stderr.strip():
                print("Error:")
                print(result.stderr)
            if result.stdout.strip():
                print("Output:")
                print(result.stdout)
            return False
            
    except Exception as e:
        print(f"❌ {description} failed with exception: {e}")
        return False

def check_prerequisites():
    """Check if required packages are installed"""
    print("🔍 Checking prerequisites...")
    
    # Check if aiohttp is installed
    try:
        import aiohttp
        print("✅ aiohttp is installed")
    except ImportError:
        print("❌ aiohttp is not installed")
        print("Installing aiohttp...")
        if not run_command("pip install aiohttp", "Installing aiohttp"):
            return False
    
    return True

def main():
    if len(sys.argv) < 4:
        print("🚀 Complete Banner Image URL Update Workflow")
        print("=" * 50)
        print()
        print("This script will:")
        print("1. Update all banner URLs in your JSON file")
        print("2. Bulk update all WordPress posts with new images")
        print("3. Clean up old images to save storage space")
        print()
        print("Usage:")
        print("  python quick_start_banner_update.py <wordpress_url> <api_key> <json_file> [method] [concurrent]")
        print()
        print("Methods:")
        print("  update  - Update existing posts (default, recommended)")
        print("  import  - Fresh import with force replace (faster)")
        print()
        print("Examples:")
        print("  python quick_start_banner_update.py https://climaterural.com/ your-api-key posts.json")
        print("  python quick_start_banner_update.py https://climaterural.com/ your-api-key posts.json update 10")
        print("  python quick_start_banner_update.py https://climaterural.com/ your-api-key posts.json import 20")
        print()
        sys.exit(1)
    
    wordpress_url = sys.argv[1]
    api_key = sys.argv[2]
    json_file = sys.argv[3]
    method = sys.argv[4] if len(sys.argv) > 4 else "update"
    concurrent = sys.argv[5] if len(sys.argv) > 5 else "10"
    
    # Validate inputs
    if method not in ["update", "import"]:
        print("❌ Method must be 'update' or 'import'")
        sys.exit(1)
    
    if not os.path.exists(json_file):
        print(f"❌ JSON file not found: {json_file}")
        sys.exit(1)
    
    print("🚀 Starting Complete Banner Image URL Update Workflow")
    print("=" * 60)
    print(f"WordPress URL: {wordpress_url}")
    print(f"JSON file: {json_file}")
    print(f"Method: {method}")
    print(f"Concurrent: {concurrent}")
    print(f"Started: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print()
    
    start_time = time.time()
    
    # Step 0: Check prerequisites
    if not check_prerequisites():
        print("❌ Prerequisites check failed")
        sys.exit(1)
    
    # Step 1: Update JSON URLs
    step1_cmd = f"python update_banner_urls.py {json_file}"
    if not run_command(step1_cmd, "STEP 1: Updating banner URLs in JSON file"):
        print("❌ Failed to update JSON URLs. Cannot continue.")
        sys.exit(1)
    
    # Step 2: Update WordPress posts
    if method == "update":
        step2_cmd = f"python bulk_update_banners.py {wordpress_url} {api_key} {json_file} {concurrent}"
        step2_desc = "STEP 2: Bulk updating WordPress posts with new banner images"
    else:  # import
        step2_cmd = f"python async_api_importer.py {wordpress_url} {api_key} {json_file} --force-replace --max-concurrent {concurrent}"
        step2_desc = "STEP 2: Fresh importing all posts with force replace"
    
    if not run_command(step2_cmd, step2_desc):
        print(f"❌ Failed to {method} posts. Check the logs for details.")
        sys.exit(1)
    
    # Final summary
    total_time = time.time() - start_time
    print()
    print("🎉 WORKFLOW COMPLETED SUCCESSFULLY!")
    print("=" * 60)
    print(f"Total time: {total_time/60:.1f} minutes")
    print(f"Method used: {method}")
    print()
    print("What was accomplished:")
    print("✅ Updated all banner URLs (removed resize/filter parameters)")
    print("✅ Updated/imported all WordPress posts with new images")
    print("✅ Cleaned up old featured images to save storage space")
    print("✅ Created backup and log files for audit trail")
    print()
    
    # List generated files
    print("Files created:")
    backup_pattern = f"posts_backup_{datetime.now().strftime('%Y%m%d')}_*.json"
    log_files = ["banner_update_log.txt", "import_log.txt"]
    
    for log_file in log_files:
        if os.path.exists(log_file):
            print(f"📄 {log_file}")
    
    # Look for backup files
    for file in os.listdir("."):
        if file.startswith("posts_backup_") and file.endswith(".json"):
            print(f"💾 {file}")
    
    print()
    print("🔍 Check the log files for detailed results and any failures.")
    print("💡 Your WordPress site should now have optimized banner images!")

if __name__ == "__main__":
    main()
