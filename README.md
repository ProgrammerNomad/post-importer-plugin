# Post Importer WordPress Plugin

A comprehensive WordPress plugin to import posts from JSON files with resumable functionality, AJAX processing, and automatic image handling to avoid server timeouts.

## Features

- **Multiple File Input Methods**: Upload JSON files directly, select from Media Library, or specify server file path
- **Media Library Integration**: Select large JSON files (150MB+) directly from WordPress Media Library
- **Content Image Processing**: Automatically downloads and processes images within post content
- **Featured Image Import**: Downloads and sets featured images from banner URLs
- **Resumable Import**: Can pause and resume imports without losing progress
- **Batch Processing**: Processes posts in batches via AJAX to avoid timeouts
- **Duplicate Prevention**: Automatically skips existing posts and reuses downloaded images
- **Progress Tracking**: Real-time progress bar and statistics
- **Error Handling**: Logs failed imports for review
- **Author Management**: Creates authors if they don't exist
- **Category & Tag Management**: Creates categories and tags automatically
- **Meta Data Import**: Imports all post meta data
- **Reimport & Replace**: Update existing posts with new content and images
- **Large File Support**: Handles JSON files up to 150MB+ with memory optimization

## Installation

1. Upload the `post-importer-plugin` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin 'Plugins' menu
3. Go to **Tools > Post Importer** to start importing

## Usage

### Method 1: Direct File Upload
1. Go to **Tools > Post Importer**
2. Click **"Choose File"** and select your JSON file
3. Click **"Analyze Selected File"**
4. Click **"Start Import"**

### Method 2: Media Library Selection (Recommended for Large Files)
1. First upload your JSON file to **Media > Add New**
2. Go to **Tools > Post Importer**
3. Click **"Choose from Media Library"**
4. Select your uploaded JSON file
5. Click **"Use This File"** then **"Analyze Selected File"**
6. Click **"Start Import"**

### Method 3: Server File Path
1. Upload your JSON file to your server via FTP/cPanel
2. Go to **Tools > Post Importer**
3. Enter the full server path to your JSON file
4. Click **"Analyze Selected File"**
5. Click **"Start Import"**

### Import Controls
- **Start Import**: Begin importing new posts
- **Pause Import**: Temporarily stop the import process
- **Resume Import**: Continue from where you paused
- **Reimport & Replace**: Update existing posts with new content and replace images
- **Reset Import**: Start over from the beginning

## JSON Format

The plugin expects a JSON array with post objects containing these fields:

```json
[
    {
        "id": 4368345,
        "title": "Post Title",
        "short_description": "Post excerpt",
        "slug": "post-slug",
        "content": "<p>Post content with <img src='https://example.com/image.jpg' alt='Image' /> images</p>",
        "categories": [
            {
                "id": 47430,
                "name": "Category Name",
                "slug": "category-slug"
            }
        ],
        "tags": [
            {
                "name": "Tag Name",
                "slug": "tag-slug"
            }
        ],
        "banner_url": "https://example.com/featured-image.jpg",
        "media_file_banner": {
            "path": "https://example.com/alternative-featured-image.jpg",
            "alt_text": "Featured image description"
        },
        "member": {
            "name": "Author Name",
            "slug": "author-slug",
            "email": "author@example.com",
            "description": "Author bio"
        },
        "contributors": [
            {
                "name": "Contributor Name",
                "email": "contributor@example.com",
                "slug": "contributor-slug"
            }
        ],
        "formatted_first_published_at_datetime": "2022-07-31T11:01:04+05:30",
        "formatted_last_published_at_datetime": "2022-07-31T11:01:04+05:30",
        "meta_data": {
            "custom_field": "value",
            "_yoast_wpseo_title": "SEO Title"
        }
    }
]
```

### Image Processing Features

The plugin automatically handles:

#### Featured Images
- Downloads images from `banner_url` or `media_file_banner.path`
- Sets as WordPress featured image
- Avoids duplicate downloads by checking existing images
- Updates image metadata with proper titles and alt text

#### Content Images
- Scans post content for `<img>` tags and `<figure>` blocks
- Downloads external images to WordPress media library
- Replaces external URLs with local WordPress URLs
- Preserves alt text, CSS classes, width, and height attributes
- Adds WordPress-style `wp-image-{ID}` classes
- Tracks processed images to avoid duplicates

## Database Tables

The plugin creates two tables:

### wp_post_import_progress
Tracks import sessions and progress:
- `session_id`: Unique import session identifier
- `total_posts`: Total number of posts to import
- `processed_posts`: Number of posts processed so far
- `failed_posts`: Number of failed imports
- `file_path`: Path to the JSON file
- `status`: Current import status (pending, processing, completed)

### wp_post_import_failed
Stores failed import attempts:
- `session_id`: Related import session
- `post_data`: JSON data of the failed post
- `error_message`: Error description

## Configuration

### Batch Size
Default batch size is 10 posts per AJAX request. You can modify this in the PHP file:

```php
wp_localize_script('post-importer-js', 'postImporter', array(
    'batch_size' => 20 // Change to desired batch size
));
```

### Memory and Timeout Settings
For large imports, the plugin automatically increases PHP settings:

```php
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 300);
```

### Large File Handling
The plugin automatically detects large files (50MB+) and:
- Increases memory limit to 1024M
- Extends execution time to 5 minutes
- Uses efficient file processing methods

## Image Management

### Duplicate Prevention
- Checks existing images by URL before downloading
- Checks existing images by filename
- Reuses existing WordPress attachments when possible
- Prevents duplicate storage of identical images

### Image Metadata
All imported images include:
- `_imported_by_post_importer`: Flag marking plugin imports
- `_original_image_url`: Original external URL
- `_import_timestamp`: When the image was imported
- `_is_content_image`: True for content images
- `_content_image_post_id`: Parent post for content images

### Image Cleanup
Use the debug tools to:
- Verify imported images
- Clean up orphaned images
- Check featured image assignments

## Troubleshooting

### Common Issues

1. **Large File Upload Fails**
   - Use **Media Library method** instead of direct upload
   - Upload file to Media Library first, then select it
   - Check PHP `upload_max_filesize` and `post_max_size` settings

2. **Import Stops or Fails**
   - Check error logs in the import log section
   - Reduce batch size from 10 to 5 or fewer
   - Use **Reset Import** and try again

3. **Images Not Downloading**
   - Check if image URLs are accessible externally
   - Verify WordPress can write to uploads directory
   - Check for SSL certificate issues with external URLs
   - Look for CORS restrictions on image sources

4. **Content Images Not Processing**
   - Check WordPress error logs for image processing messages
   - Verify images have proper `<img>` tags in content
   - Ensure external URLs are accessible

5. **Memory or Timeout Issues**
   - Use **Media Library method** for large files
   - Reduce batch size in configuration
   - The plugin automatically increases limits for large files

### Error Recovery

If an import fails:
1. Check the import log for specific errors
2. Fix any data issues in the JSON file
3. Use **"Resume Import"** to continue from where it stopped
4. Use **"Reimport & Replace"** to update existing posts
5. Use **"Reset Import"** to start completely over

### Debug Tools

Access debug tools at **Tools > PI Debug**:
- Test image download functionality
- Verify featured image assignments
- Clean up orphaned images
- View import statistics

## Security

- Only administrators can access the import functionality
- File uploads are restricted to JSON files
- All input is sanitized and validated
- AJAX requests use WordPress nonces for security
- Uploaded files are stored in protected directories

## Performance Tips

1. **Large Files (150MB+)**: Use Media Library method
2. **Slow Imports**: Reduce batch size from 10 to 5 or fewer
3. **Memory Issues**: Plugin automatically increases PHP memory limit
4. **Timeout Issues**: Import runs via AJAX to avoid server timeouts
5. **Image Processing**: Plugin reuses existing images to save bandwidth

## Content Image Processing

The plugin processes images in post content by:

1. **Scanning Content**: Finds all `<img>` tags and WordPress `<figure>` blocks
2. **Downloading Images**: Downloads external images to WordPress media library
3. **URL Replacement**: Replaces external URLs with local WordPress URLs
4. **Attribute Preservation**: Maintains alt text, CSS classes, dimensions
5. **WordPress Integration**: Adds proper `wp-image-{ID}` classes
6. **Duplicate Prevention**: Reuses existing images when found

### Supported Image Formats
- Standard HTML `<img>` tags
- WordPress Gutenberg `<figure class="wp-block-image">` blocks
- Images with alt text, CSS classes, width/height attributes

### Image URL Processing
- Downloads from `https://` and `http://` URLs
- Skips local WordPress URLs (already processed)
- Skips data URLs and relative paths
- Validates URLs before processing

## Support

For issues or questions:
1. Check the import log for specific error messages
2. Use the debug tools to verify functionality
3. Check WordPress error logs for detailed information
4. Verify your JSON file format matches the expected structure
5. Ensure proper file permissions on uploads directory

## Changelog

### Version 1.0.0
- Initial release
- Basic import functionality
- Resumable imports
- AJAX processing
- Progress tracking
- Error handling
- Featured image import
- Content image processing
- Media library integration
- Large file support (150MB+)
- Duplicate prevention
- Reimport and replace functionality

## License

GPL v2 or later

## Author

Nomod Programmer - https://groundreport.in
