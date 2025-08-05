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
});

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
                echo '<p>Use these tools to test the featured image download functionality.</p>';
                echo '<p><a href="' . admin_url('tools.php?page=post-importer-debug&test_image_download=1') . '" class="button button-primary">Test Image Download</a></p>';
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
