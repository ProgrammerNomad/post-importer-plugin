# Quick Setup Guide

## Installation Steps

1. **Upload Plugin Files**
   - Copy the `post-importer-plugin` folder to your WordPress `wp-content/plugins/` directory
   - Or zip the folder and upload via WordPress admin

2. **Activate Plugin**
   - Go to WordPress Admin > Plugins
   - Find "Post Importer" and click "Activate"

3. **Access Import Tool**
   - Go to WordPress Admin > Tools > Post Importer
   - You'll see the import interface

## Usage Steps

### For Small Files (< 50MB)
1. Click "Choose File" and select your `posts.json`
2. Click "Upload & Analyze File"
3. Review the import summary
4. Click "Start Import"

### For Large Files (> 50MB)
1. Upload your `posts.json` file to your server via FTP/cPanel
2. In the "File Path" field, enter the full server path (e.g., `/home/username/posts.json`)
3. Click "Upload & Analyze File"
4. Review the import summary
5. Click "Start Import"

## Import Features

- **Resumable**: You can pause and resume imports
- **Safe**: Automatically skips duplicate posts (based on slug)
- **Progress Tracking**: Real-time progress bar and statistics
- **Error Handling**: Failed imports are logged for review
- **Batch Processing**: Processes posts in small batches to avoid timeouts

## Troubleshooting

### Import Stops or Fails
- Check the import log for specific errors
- Try reducing batch size (modify line 76 in `post-importer.php`)
- Increase PHP memory limit in your hosting control panel

### File Upload Fails
- Use the "File Path" method for large files
- Check file permissions on uploads directory
- Ensure file is valid JSON format

### Images Not Downloading
- Check if image URLs are publicly accessible
- Verify WordPress can write to uploads directory

## Configuration

You can modify these settings in `config.php`:

```php
const DEFAULT_BATCH_SIZE = 10;  // Posts per batch
const MAX_FILE_SIZE = 100 * 1024 * 1024;  // 100MB
const MEMORY_LIMIT = '512M';
const TIME_LIMIT = 300;  // 5 minutes
```

## File Structure

```
post-importer-plugin/
├── post-importer.php     # Main plugin file
├── config.php           # Configuration settings
├── installer.php        # Database setup
├── assets/
│   ├── post-importer.js  # JavaScript for AJAX
│   └── post-importer.css # Styling
├── README.md            # Full documentation
├── SETUP.md             # This file
└── example-posts.json   # Sample JSON structure
```

## Support

If you encounter issues:

1. Check the import log in the plugin interface
2. Review WordPress error logs
3. Verify your JSON file structure matches the example
4. Ensure proper file permissions (755 for directories, 644 for files)

## Sample JSON Structure

Your `posts.json` should be an array of post objects like this:

```json
[
    {
        "id": 123,
        "title": "Post Title",
        "slug": "post-slug",
        "content": "<p>Post content</p>",
        "short_description": "Post excerpt",
        "categories": [{"name": "Category", "slug": "category"}],
        "tags": [{"name": "Tag", "slug": "tag"}],
        "banner_url": "https://example.com/image.jpg",
        "member": {
            "name": "Author Name",
            "email": "author@example.com"
        },
        "formatted_first_published_at_datetime": "2022-07-31T11:01:04+05:30"
    }
]
```

That's it! Your plugin is ready to import posts.
