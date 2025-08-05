# Featured Image Import Troubleshooting Guide

## Quick Test Steps

1. **Enable WordPress Debug Mode** - Add these lines to your `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. **Check WordPress Error Logs** - Look for error messages in:
   - `wp-content/debug.log`
   - Your hosting provider's error logs

3. **Test Manual Image Import** - Go to **Tools > PI Debug** (if debug mode is enabled) and click "Test Image Download"

## Common Issues & Solutions

### Issue 1: Images Not Downloading
**Symptoms**: Posts import but no featured images
**Causes**:
- Server can't access external URLs
- PHP `allow_url_fopen` disabled
- cURL not installed
- SSL certificate issues

**Solutions**:
```php
// Check in WordPress admin or add to functions.php temporarily:
echo 'allow_url_fopen: ' . (ini_get('allow_url_fopen') ? 'Enabled' : 'Disabled') . '<br>';
echo 'cURL: ' . (extension_loaded('curl') ? 'Loaded' : 'Not loaded') . '<br>';
echo 'SSL verify: ' . (ini_get('openssl.cafile') ? 'Configured' : 'Not configured') . '<br>';
```

### Issue 2: Permission Errors
**Symptoms**: Error logs show "Permission denied"
**Solutions**:
- Check uploads directory permissions (should be 755)
- Ensure WordPress can write to `/wp-content/uploads/`
- Check disk space

### Issue 3: URL Format Issues
**Symptoms**: Some images import, others don't
**Causes**: Invalid URLs, redirects, or special characters
**Check**: Look for these in your JSON:
- URLs with spaces or special characters
- URLs that redirect (301/302)
- URLs that require authentication

### Issue 4: Memory/Timeout Issues
**Symptoms**: Import stops when processing images
**Solutions**:
- Increase PHP memory limit: `ini_set('memory_limit', '512M');`
- Reduce batch size in the plugin
- Check for very large images (>10MB)

## Verification Steps

### 1. Check if Featured Images are Being Set
Run this SQL query in phpMyAdmin or similar:
```sql
SELECT p.ID, p.post_title, pm.meta_value as featured_image_id
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
WHERE p.post_type = 'post'
AND pm.meta_value IS NOT NULL
ORDER BY p.ID DESC
LIMIT 10;
```

### 2. Check Import Status Meta
```sql
SELECT p.ID, p.post_title, 
       pm1.meta_value as original_id,
       pm2.meta_value as featured_imported,
       pm3.meta_value as banner_url
FROM wp_posts p
LEFT JOIN wp_postmeta pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_original_post_id'
LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_featured_image_imported'
LEFT JOIN wp_postmeta pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_banner_image_url'
WHERE p.post_type = 'post'
ORDER BY p.ID DESC
LIMIT 10;
```

### 3. Check Media Library for Downloaded Images
```sql
SELECT ID, post_title, post_date, guid
FROM wp_posts
WHERE post_type = 'attachment'
AND post_mime_type LIKE 'image%'
ORDER BY ID DESC
LIMIT 10;
```

## Manual Fix for Existing Posts

If you need to re-import just the featured images for existing posts:

```php
// Add this to a temporary PHP file and run it once
$posts = get_posts(array(
    'post_type' => 'post',
    'meta_key' => '_original_post_id',
    'numberposts' => -1
));

foreach ($posts as $post) {
    $banner_url = get_post_meta($post->ID, '_banner_image_url', true);
    if ($banner_url && !has_post_thumbnail($post->ID)) {
        // Re-attempt featured image download
        $image_id = media_sideload_image($banner_url, $post->ID, $post->post_title, 'id');
        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post->ID, $image_id);
            update_post_meta($post->ID, '_featured_image_imported', 'yes');
        }
    }
}
```

## Testing Individual URLs

Test if specific image URLs work:
```php
$test_url = 'https://img-cdn.publive.online/fit-in/640x480/filters:format(webp)/ground-report/media/post_banners/wp-content/uploads/2022/06/Source-unsplash-2022-06-07T135237.759.jpg';

$response = wp_remote_get($test_url);
echo 'Response Code: ' . wp_remote_retrieve_response_code($response) . '<br>';
echo 'Content Type: ' . wp_remote_retrieve_header($response, 'content-type') . '<br>';
echo 'Content Length: ' . wp_remote_retrieve_header($response, 'content-length') . '<br>';
```

## WordPress Requirements

Ensure your WordPress installation meets these requirements:
- WordPress 5.0+
- PHP 7.4+
- cURL or allow_url_fopen enabled
- GD or ImageMagick extension
- Writable uploads directory
- Sufficient memory (512MB recommended)

## Contact Support

If issues persist:
1. Enable debug mode and gather error logs
2. Note your server environment (PHP version, hosting provider)
3. Test with a single post/image first
4. Check if the same URLs work in a browser
