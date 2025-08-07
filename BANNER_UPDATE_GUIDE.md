# Banner Image URL Update - Complete Guide

This guide shows you how to update all banner/featured image URLs in your WordPress posts to remove resize parameters and save storage space.

## What This Does

1. **Updates JSON URLs**: Removes `640x480/filters:format(webp)/` from all banner URLs
2. **Replaces WordPress Images**: Forces WordPress to download new images and replace old ones
3. **Saves Storage Space**: Automatically deletes old featured images that are no longer used

## URL Transformation Example

**Before:**
```
https://img-cdn.publive.online/fit-in/640x480/filters:format(webp)/ground-report/2023/06/image.jpg
```

**After:**
```
https://img-cdn.publive.online/fit-in/ground-report/2023/06/image.jpg
```

## Prerequisites

1. **Install Required Python Package**:
   ```powershell
   pip install aiohttp
   ```

2. **Your API Key**: Get it from WordPress Admin → Post Importer → Settings

## Step-by-Step Process

### Step 1: Update URLs in JSON File

First, update all the banner URLs in your JSON file:

```powershell
python update_banner_urls.py posts.json
```

This will:
- Create a backup: `posts_backup_YYYYMMDD_HHMMSS.json`
- Update the original file with clean URLs
- Show you a summary of changes

### Step 2: Bulk Update WordPress Posts

Now update all posts in WordPress with the new URLs:

```powershell
python bulk_update_banners.py https://climaterural.com/ YOUR_API_KEY posts.json 10
```

Parameters:
- `https://climaterural.com/` - Your WordPress site URL
- `YOUR_API_KEY` - Your Post Importer API key
- `posts.json` - The JSON file with updated URLs
- `10` - Number of concurrent connections (optional, default: 10)

This will:
- Test the connection first
- Process posts in batches
- Show real-time progress
- Force-replace featured images
- Delete old images to save space
- Create a log file: `banner_update_log.txt`

## Alternative: Import Fresh with New URLs

If you prefer to re-import all posts fresh (this will be faster):

```powershell
python async_api_importer.py https://climaterural.com/ YOUR_API_KEY posts.json --force-replace --max-concurrent 20
```

This approach:
- Imports all posts as new (with force replace)
- Uses the async importer for maximum speed
- Automatically handles image replacement

## Performance Expectations

- **URL Update Script**: ~50,000 URLs per second
- **WordPress Updates**: ~10-20 posts per second (depends on image sizes)
- **Fresh Import**: ~20-50 posts per second with force replace

## Monitoring Progress

Both scripts provide real-time feedback:

```
📊 PROGRESS: 5000/10422 (48.0%)
   UPDATED: 4850
   SKIPPED: 100
   FAILED: 50
   RATE: 15.2 posts/sec
   ETA: 6.2 minutes
```

## What Happens to Images

1. **New Images**: Downloaded fresh from updated URLs
2. **Old Images**: Automatically deleted if not used by other posts
3. **Storage**: Significant space savings from removing unused resized images
4. **Quality**: Better image quality without forced resizing

## Troubleshooting

### If Updates Fail
- Check your API key in WordPress settings
- Verify your site URL is correct
- Make sure WordPress is accessible
- Check the log file for detailed errors

### If Images Don't Update
- Verify the JSON URLs were actually changed
- Check WordPress media library permissions
- Look for image download errors in logs

### Performance Issues
- Reduce `max_concurrent` parameter (try 5-10)
- Check your server's memory and CPU usage
- Monitor network bandwidth during updates

## Files Created

During the process, these files are created:

- `posts_backup_YYYYMMDD_HHMMSS.json` - Original JSON backup
- `banner_update_log.txt` - Update process log
- `banner_update_failed_YYYYMMDD_HHMMSS.json` - Failed updates (if any)

## Safety Features

- **Backup**: Original JSON is always backed up
- **Testing**: Connection is tested before starting
- **Retries**: Failed requests are retried automatically
- **Rollback**: Original images are only deleted after successful replacement
- **Logging**: Complete audit trail of all changes

## Estimated Time

For ~10,000 posts:
- URL updates: ~1-2 minutes
- WordPress updates: ~10-30 minutes (depends on image sizes and server)

## Need Help?

If you encounter issues:
1. Check the log files for detailed error messages
2. Verify your WordPress site is accessible
3. Ensure the Post Importer plugin is active and updated
4. Test with a smaller batch first (use head -n 100 posts.json > test.json)

## Success Metrics

A successful update will show:
- 95%+ success rate
- Significant reduction in media library size
- Improved image quality in posts
- Faster page loading times
