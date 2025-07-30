# Post Importer WordPress Plugin

A comprehensive WordPress plugin to import posts from JSON files with resumable functionality and AJAX processing to avoid server timeouts.

## Features

- **File Upload or Path Input**: Upload JSON files directly or specify server file path
- **Resumable Import**: Can pause and resume imports without losing progress
- **Batch Processing**: Processes posts in batches via AJAX to avoid timeouts
- **Duplicate Prevention**: Automatically skips existing posts based on slug
- **Progress Tracking**: Real-time progress bar and statistics
- **Error Handling**: Logs failed imports for review
- **Author Management**: Creates authors if they don't exist
- **Media Handling**: Downloads and sets featured images
- **Category & Tag Management**: Creates categories and tags automatically
- **Meta Data Import**: Imports all post meta data

## Installation

1. Upload the `post-importer-plugin` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin 'Plugins' menu
3. Go to **Tools > Post Importer** to start importing

## Usage

### Method 1: File Upload
1. Click "Choose File" and select your JSON file
2. Click "Upload & Analyze File"
3. Review the import summary
4. Click "Start Import"

### Method 2: Server File Path
1. Enter the full server path to your JSON file (e.g., `/home/user/posts.json`)
2. Click "Upload & Analyze File"
3. Review the import summary
4. Click "Start Import"

### Import Controls
- **Start Import**: Begin the import process
- **Pause Import**: Temporarily stop the import (can be resumed)
- **Resume Import**: Continue a paused import
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
        "content": "<p>Post content HTML</p>",
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
        "banner_url": "https://example.com/image.jpg",
        "member": {
            "name": "Author Name",
            "slug": "author-slug",
            "email": "author@example.com",
            "description": "Author bio"
        },
        "formatted_first_published_at_datetime": "2022-07-31T11:01:04+05:30",
        "formatted_last_published_at_datetime": "2022-07-31T11:01:04+05:30",
        "meta_data": {
            "custom_field": "value"
        }
    }
]
```

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
For large imports, you may need to increase PHP settings:

```php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
```

## Troubleshooting

### Common Issues

1. **File Upload Fails**
   - Check file permissions on uploads directory
   - Increase `upload_max_filesize` in PHP settings
   - Use server file path method for very large files

2. **Import Stops or Fails**
   - Check error logs in the import log section
   - Increase PHP memory limit
   - Reduce batch size

3. **Images Not Downloading**
   - Check if image URLs are accessible
   - Verify WordPress can write to uploads directory
   - Check for SSL certificate issues with external URLs

4. **Authors Not Created**
   - Check if user creation is allowed
   - Verify email addresses are valid and unique

### Error Recovery

If an import fails:
1. Check the import log for specific errors
2. Fix any data issues in the JSON file
3. Use "Resume Import" to continue from where it stopped
4. Use "Reset Import" to start completely over

## Security

- Only administrators can access the import functionality
- File uploads are restricted to JSON files
- All input is sanitized and validated
- AJAX requests use WordPress nonces for security

## Performance Tips

1. **Large Files**: Use server file path method instead of upload
2. **Slow Imports**: Reduce batch size from 10 to 5 or fewer
3. **Memory Issues**: Increase PHP memory limit
4. **Timeout Issues**: Import runs via AJAX to avoid timeouts

## Support

For issues or questions:
1. Check the import log for specific error messages
2. Verify your JSON file format matches the expected structure
3. Check WordPress error logs
4. Ensure proper file permissions

## Changelog

### Version 1.0.0
- Initial release
- Basic import functionality
- Resumable imports
- AJAX processing
- Progress tracking
- Error handling
