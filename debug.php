<?php
/**
 * Debug helper for Post Importer Plugin
 * Add this to test featured image downloads manually
 */

// Only run if WordPress is loaded
if (!defined('ABSPATH')) {
    die('Direct access not permitted');
}

// Add debug logging function
function post_importer_debug_log($message, $data = null) {
    $log_message = '[Post Importer Debug] ' . $message;
    if ($data !== null) {
        $log_message .= ' | Data: ' . print_r($data, true);
    }
    error_log($log_message);
}

// Test function to check featured image download
function test_featured_image_download($image_url, $post_title = 'Test Post') {
    post_importer_debug_log('Testing image download', ['url' => $image_url, 'title' => $post_title]);
    
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    // Validate URL
    if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
        post_importer_debug_log('Invalid URL format', $image_url);
        return false;
    }
    
    // Check if URL is accessible
    $response = wp_remote_head($image_url);
    if (is_wp_error($response)) {
        post_importer_debug_log('URL check failed - WP Error', $response->get_error_message());
        return false;
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code != 200) {
        post_importer_debug_log('URL not accessible', ['response_code' => $response_code]);
        return false;
    }
    
    post_importer_debug_log('URL is accessible', ['response_code' => $response_code]);
    
    // Try to download
    $image_id = media_sideload_image($image_url, 0, $post_title, 'id');
    
    if (is_wp_error($image_id)) {
        post_importer_debug_log('media_sideload_image failed', $image_id->get_error_message());
        return false;
    }
    
    post_importer_debug_log('Image downloaded successfully', ['attachment_id' => $image_id]);
    return $image_id;
}

// Hook to add admin notice with debug button (only for administrators)
add_action('admin_notices', function() {
    if (current_user_can('manage_options') && isset($_GET['test_image_download'])) {
        $test_url = 'https://img-cdn.publive.online/fit-in/640x480/filters:format(webp)/ground-report/media/post_banners/wp-content/uploads/2022/06/Source-unsplash-2022-06-07T135237.759.jpg';
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>Post Importer Debug:</strong> Testing image download...<br>';
        
        $result = test_featured_image_download($test_url, 'Debug Test Image');
        
        if ($result) {
            echo '<span style="color: green;">✓ Image download test PASSED! Attachment ID: ' . $result . '</span>';
        } else {
            echo '<span style="color: red;">✗ Image download test FAILED! Check error logs for details.</span>';
        }
        
        echo '</p></div>';
    }
    
    // Handle cleanup orphaned images
    if (current_user_can('manage_options') && isset($_GET['cleanup_images'])) {
        $post_importer = new PostImporter();
        $deleted_count = $post_importer->cleanup_orphaned_images();
        
        echo '<div class="notice notice-success"><p>';
        echo '<strong>Post Importer Cleanup:</strong> ';
        echo "Cleaned up {$deleted_count} orphaned images that were no longer being used.";
        echo '</p></div>';
    }
    
    // Handle featured image verification
    if (current_user_can('manage_options') && isset($_GET['verify_featured_images'])) {
        $results = verify_featured_images();
        
        echo '<div class="notice notice-info"><p>';
        echo '<strong>Featured Image Verification:</strong><br>';
        echo "Total imported posts: {$results['total_posts']}<br>";
        echo "Posts with featured images: {$results['with_thumbnails']}<br>";
        echo "Posts missing featured images: {$results['missing_thumbnails']}<br>";
        echo "Images in media library: {$results['total_images']}<br>";
        if (!empty($results['missing_posts'])) {
            echo "Post IDs missing thumbnails: " . implode(', ', $results['missing_posts']) . "<br>";
        }
        echo '</p></div>';
    }
    
    // Handle fixing missing featured images
    if (current_user_can('manage_options') && isset($_GET['fix_featured_images'])) {
        $results = fix_missing_featured_images();
        
        echo '<div class="notice notice-success"><p>';
        echo '<strong>Featured Image Fix:</strong> ';
        echo "Fixed {$results['fixed']} posts, failed to fix {$results['failed']} posts.";
        echo '</p></div>';
    }
});

// Function to verify featured image assignments
function verify_featured_images() {
    global $wpdb;
    
    // Get all posts imported by our plugin
    $imported_posts = $wpdb->get_results("
        SELECT p.ID, p.post_title, pm.meta_value as banner_url
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
        WHERE pm.meta_key = '_original_post_id'
        AND p.post_type = 'post'
    ");
    
    $total_posts = count($imported_posts);
    $with_thumbnails = 0;
    $missing_thumbnails = 0;
    $missing_posts = array();
    
    foreach ($imported_posts as $post) {
        if (has_post_thumbnail($post->ID)) {
            $with_thumbnails++;
        } else {
            $missing_thumbnails++;
            $missing_posts[] = $post->ID;
            
            // Log details for debugging
            $banner_url = get_post_meta($post->ID, '_banner_image_url', true);
            $featured_imported = get_post_meta($post->ID, '_featured_image_imported', true);
            $thumbnail_id = get_post_meta($post->ID, '_thumbnail_id', true);
            error_log("Post Importer Debug: Post {$post->ID} missing thumbnail. Banner URL: {$banner_url}, Import status: {$featured_imported}, Thumbnail ID meta: {$thumbnail_id}");
        }
    }
    
    // Count total images imported by plugin
    $total_images = $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'attachment'
        AND pm.meta_key = '_imported_by_post_importer'
        AND pm.meta_value = '1'
    ");
    
    return array(
        'total_posts' => $total_posts,
        'with_thumbnails' => $with_thumbnails,
        'missing_thumbnails' => $missing_thumbnails,
        'missing_posts' => $missing_posts,
        'total_images' => $total_images
    );
}

// Function to fix missing featured images for already imported posts
function fix_missing_featured_images() {
    global $wpdb;
    
    $fixed_count = 0;
    $failed_count = 0;
    
    // Get posts that have banner_image_id but no thumbnail
    $posts_to_fix = $wpdb->get_results("
        SELECT p.ID, pm1.meta_value as banner_image_id, pm2.meta_value as banner_url
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_banner_image_id'
        LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_banner_image_url'
        LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_thumbnail_id'
        WHERE p.post_type = 'post'
        AND pm3.meta_value IS NULL
        AND pm1.meta_value IS NOT NULL
        AND pm1.meta_value != ''
    ");
    
    foreach ($posts_to_fix as $post) {
        $image_id = intval($post->banner_image_id);
        
        // Verify the attachment exists
        $attachment = get_post($image_id);
        if ($attachment && $attachment->post_type === 'attachment') {
            // Set as featured image
            $result = set_post_thumbnail($post->ID, $image_id);
            if ($result) {
                $fixed_count++;
                error_log("Post Importer Fix: Set featured image {$image_id} for post {$post->ID}");
            } else {
                // Try direct meta update
                update_post_meta($post->ID, '_thumbnail_id', $image_id);
                if (has_post_thumbnail($post->ID)) {
                    $fixed_count++;
                    error_log("Post Importer Fix: Set featured image {$image_id} for post {$post->ID} via direct meta");
                } else {
                    $failed_count++;
                    error_log("Post Importer Fix: Failed to set featured image {$image_id} for post {$post->ID}");
                }
            }
        } else {
            $failed_count++;
            error_log("Post Importer Fix: Image {$image_id} does not exist for post {$post->ID}");
        }
    }
    
    return array('fixed' => $fixed_count, 'failed' => $failed_count);
}

// Add debug menu for testing
add_action('admin_menu', function() {
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'tools.php',
            'Post Importer Debug',
            'PI Debug',
            'manage_options',
            'post-importer-debug',
            function() {
                echo '<div class="wrap">';
                echo '<h1>Post Importer Debug Tools</h1>';
                echo '<p>Use these tools to test the featured image download functionality and manage imported images.</p>';
                
                echo '<h3>Image Testing:</h3>';
                echo '<p><a href="' . admin_url('tools.php?page=post-importer-debug&test_image_download=1') . '" class="button button-primary">Test Image Download</a></p>';
                
                echo '<h3>Image Management:</h3>';
                echo '<p><a href="' . admin_url('tools.php?page=post-importer-debug&cleanup_images=1') . '" class="button button-secondary" onclick="return confirm(\'Are you sure? This will delete all imported images that are not currently being used as featured images.\')">Cleanup Orphaned Images</a></p>';
                
                echo '<h3>Verification:</h3>';
                echo '<p><a href="' . admin_url('tools.php?page=post-importer-debug&verify_featured_images=1') . '" class="button button-secondary">Verify Featured Images</a></p>';
                echo '<p><a href="' . admin_url('tools.php?page=post-importer-debug&fix_featured_images=1') . '" class="button button-secondary" onclick="return confirm(\'Are you sure? This will attempt to fix posts that have downloaded images but missing featured image assignments.\')">Fix Missing Featured Images</a></p>';
                
                echo '<h3>Debug Information:</h3>';
                echo '<ul>';
                echo '<li><strong>WordPress Version:</strong> ' . get_bloginfo('version') . '</li>';
                echo '<li><strong>PHP Version:</strong> ' . PHP_VERSION . '</li>';
                echo '<li><strong>Upload Directory:</strong> ' . wp_upload_dir()['basedir'] . '</li>';
                echo '<li><strong>Upload Directory Writable:</strong> ' . (is_writable(wp_upload_dir()['basedir']) ? 'Yes' : 'No') . '</li>';
                echo '<li><strong>allow_url_fopen:</strong> ' . (ini_get('allow_url_fopen') ? 'Enabled' : 'Disabled') . '</li>';
                echo '<li><strong>curl extension:</strong> ' . (extension_loaded('curl') ? 'Loaded' : 'Not loaded') . '</li>';
                echo '</ul>';
                echo '</div>';
            }
        );
    }
});
