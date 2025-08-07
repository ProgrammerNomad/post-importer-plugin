<?php
/**
 * Plugin Name: Post Importer
 * Plugin URI: https://groundreport.in
 * Description: Import posts from JSON file with resumable functionality and AJAX processing
 * Version: 1.0.0
 * Author: Nomod Programmer
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('POST_IMPORTER_VERSION', '1.0.0');
define('POST_IMPORTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POST_IMPORTER_PLUGIN_URL', plugin_dir_url(__FILE__));

class PostImporter {
    
    // Track last imported post ID
    private $last_imported_post_id = null;
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_upload_json_file', array($this, 'handle_file_upload'));
        add_action('wp_ajax_import_posts_batch', array($this, 'import_posts_batch'));
        add_action('wp_ajax_reimport_posts_batch', array($this, 'reimport_posts_batch'));
        add_action('wp_ajax_get_import_status', array($this, 'get_import_status'));
        add_action('wp_ajax_reset_import', array($this, 'reset_import'));
        add_action('rest_api_init', array($this, 'register_api_endpoints'));
        
        // Create database table on activation
        register_activation_hook(__FILE__, array($this, 'create_import_table'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Post Importer',
            'Post Importer',
            'manage_options',
            'post-importer',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_post-importer') {
            return;
        }
        
        // Enqueue WordPress media scripts for media library selection
        wp_enqueue_media();
        
        wp_enqueue_script('post-importer-js', POST_IMPORTER_PLUGIN_URL . 'assets/post-importer.js', array('jquery', 'media-upload', 'media-views'), POST_IMPORTER_VERSION, true);
        wp_enqueue_style('post-importer-css', POST_IMPORTER_PLUGIN_URL . 'assets/post-importer.css', array(), POST_IMPORTER_VERSION);
        
        wp_localize_script('post-importer-js', 'postImporter', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('post_importer_nonce'),
            'batch_size' => 10 // Process 10 posts per batch
        ));
    }
    
    public function create_import_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'post_import_progress';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            total_posts int(11) NOT NULL DEFAULT 0,
            processed_posts int(11) NOT NULL DEFAULT 0,
            failed_posts int(11) NOT NULL DEFAULT 0,
            current_batch int(11) NOT NULL DEFAULT 0,
            file_path varchar(500) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create failed posts table
        $failed_table = $wpdb->prefix . 'post_import_failed';
        $sql_failed = "CREATE TABLE $failed_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            post_data longtext NOT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        dbDelta($sql_failed);
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Post Importer</h1>
            
            <div id="import-container">
                <!-- File Upload Section -->
                <div id="upload-section" class="postbox">
                    <h2 class="hndle">Select JSON File</h2>
                    <div class="inside">
                        <form id="upload-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Method 1: Upload New File</th>
                                    <td>
                                        <input type="file" id="json-file" name="json_file" accept=".json">
                                        <p class="description">Select your posts.json file to upload and import</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Method 2: Select from Media Library</th>
                                    <td>
                                        <input type="button" id="select-media-json" class="button" value="Choose from Media Library">
                                        <input type="hidden" id="media-file-id" name="media_file_id" value="">
                                        <div id="selected-media-info" style="margin-top: 10px; display: none;">
                                            <p><strong>Selected File:</strong> <span id="selected-filename"></span></p>
                                            <p><strong>File Size:</strong> <span id="selected-filesize"></span></p>
                                            <p><strong>Upload Date:</strong> <span id="selected-date"></span></p>
                                            <button type="button" id="clear-media-selection" class="button">Clear Selection</button>
                                        </div>
                                        <p class="description">Select a JSON file that's already uploaded to your Media Library</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Method 3: Server File Path</th>
                                    <td>
                                        <input type="text" id="file-path" name="file_path" class="regular-text" placeholder="Enter full path to JSON file">
                                        <p class="description">Enter the full server path to your JSON file</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" class="button-primary" value="Analyze Selected File">
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- Import Status Section -->
                <div id="status-section" class="postbox" style="display: none;">
                    <h2 class="hndle">Import Status</h2>
                    <div class="inside">
                        <div id="import-info"></div>
                        <div id="progress-container">
                            <div id="progress-bar">
                                <div id="progress-fill"></div>
                            </div>
                            <div id="progress-text">0%</div>
                        </div>
                        <div id="import-stats"></div>
                        <div id="import-controls">
                            <button id="start-import" class="button-primary" style="display: none;">Start Import</button>
                            <button id="pause-import" class="button" style="display: none;">Pause Import</button>
                            <button id="resume-import" class="button" style="display: none;">Resume Import</button>
                            <button id="reimport-posts" class="button button-secondary reimport-button" style="display: none;">Reimport & Replace</button>
                            <button id="reset-import" class="button button-secondary" style="display: none;">Reset Import</button>
                        </div>
                    </div>
                </div>
                
                <!-- Import Log Section -->
                <div id="log-section" class="postbox" style="display: none;">
                    <h2 class="hndle">Import Log</h2>
                    <div class="inside">
                        <div id="import-log"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function handle_file_upload() {
        check_ajax_referer('post_importer_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $file_path = '';
        $session_id = uniqid('import_');
        
        // Handle file upload (Method 1)
        if (!empty($_FILES['json_file']['tmp_name'])) {
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/post-importer/';
            
            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }
            
            $file_name = sanitize_file_name($_FILES['json_file']['name']);
            $file_path = $target_dir . $session_id . '_' . $file_name;
            
            if (!move_uploaded_file($_FILES['json_file']['tmp_name'], $file_path)) {
                wp_send_json_error('Failed to upload file');
                return;
            }
        }
        // Handle media library selection (Method 2) - NEW
        elseif (!empty($_POST['media_file_id'])) {
            $media_file_id = intval($_POST['media_file_id']);
            
            // Get attachment details
            $attachment = get_post($media_file_id);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                wp_send_json_error('Invalid media file selected');
                return;
            }
            
            // Check if it's a JSON file
            $mime_type = get_post_mime_type($media_file_id);
            if ($mime_type !== 'application/json') {
                wp_send_json_error('Selected file is not a JSON file');
                return;
            }
            
            // Get the file path
            $file_path = get_attached_file($media_file_id);
            
            if (!$file_path || !file_exists($file_path)) {
                wp_send_json_error('Media file not found on server');
                return;
            }
            
            error_log("Post Importer: Using media library file: {$file_path}");
        }
        // Handle server file path (Method 3)
        elseif (!empty($_POST['file_path'])) {
            $file_path = sanitize_text_field($_POST['file_path']);
            
            if (!file_exists($file_path)) {
                wp_send_json_error('File does not exist at the specified path');
                return;
            }
        } else {
            wp_send_json_error('No file uploaded, selected, or path specified');
            return;
        }
        
        // Validate file size for large files
        $file_size = filesize($file_path);
        if ($file_size === false) {
            wp_send_json_error('Unable to determine file size');
            return;
        }
        
        // Check if file is very large (over 100MB)
        if ($file_size > (100 * 1024 * 1024)) {
            error_log("Post Importer: Processing large file ({$file_size} bytes): {$file_path}");
            
            // Increase memory and time limits for large files
            if (function_exists('ini_set')) {
                ini_set('memory_limit', '1024M');
                ini_set('max_execution_time', 300);
            }
        }
        
        // Analyze JSON file
        $posts_data = $this->analyze_json_file($file_path);
        
        if ($posts_data === false) {
            wp_send_json_error('Invalid JSON file or unable to read file');
            return;
        }
        
        // Save import session
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_import_progress';
        
        $wpdb->insert(
            $table_name,
            array(
                'session_id' => $session_id,
                'total_posts' => count($posts_data),
                'file_path' => $file_path,
                'status' => 'ready'
            )
        );
        
        wp_send_json_success(array(
            'session_id' => $session_id,
            'total_posts' => count($posts_data),
            'file_path' => basename($file_path),
            'file_size' => size_format($file_size)
        ));
    }
    
    private function analyze_json_file($file_path) {
        // Check file size first
        $file_size = filesize($file_path);
        
        if ($file_size === false) {
            error_log("Post Importer: Unable to get file size for: {$file_path}");
            return false;
        }
        
        error_log("Post Importer: Analyzing JSON file: {$file_path} ({$file_size} bytes)");
        
        // For very large files, increase memory limit
        if ($file_size > (50 * 1024 * 1024)) { // 50MB+
            if (function_exists('ini_set')) {
                ini_set('memory_limit', '1024M');
                ini_set('max_execution_time', 300);
            }
            error_log("Post Importer: Increased memory limit for large file processing");
        }
        
        // Read file contents
        $json_content = file_get_contents($file_path);
        
        if ($json_content === false) {
            error_log("Post Importer: Failed to read file: {$file_path}");
            return false;
        }
        
        // Decode JSON
        $posts_data = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Post Importer: JSON decode error: " . json_last_error_msg());
            return false;
        }
        
        // Validate structure
        if (!is_array($posts_data) || empty($posts_data)) {
            error_log("Post Importer: Invalid JSON structure - not an array or empty");
            return false;
        }
        
        error_log("Post Importer: Successfully analyzed JSON file with " . count($posts_data) . " posts");
        return $posts_data;
    }
    
    public function import_posts_batch() {
        check_ajax_referer('post_importer_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $session_id = sanitize_text_field($_POST['session_id']);
        $batch_size = intval($_POST['batch_size']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_import_progress';
        
        // Get import session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error('Import session not found');
            return;
        }
        
        // Load JSON data
        $posts_data = $this->analyze_json_file($session->file_path);
        
        if ($posts_data === false) {
            wp_send_json_error('Unable to read JSON file');
            return;
        }
        
        // Calculate batch
        $start_index = $session->processed_posts;
        $end_index = min($start_index + $batch_size, count($posts_data));
        
        $batch_posts = array_slice($posts_data, $start_index, $batch_size);
        
        $imported_count = 0;
        $failed_count = 0;
        $skipped_count = 0;
        
        foreach ($batch_posts as $post_data) {
            $result = $this->import_single_post($post_data, $session_id);
            
            if ($result === 'imported') {
                $imported_count++;
            } elseif ($result === 'failed') {
                $failed_count++;
            } else {
                $skipped_count++;
            }
        }
        
        // Update progress
        $new_processed = $session->processed_posts + count($batch_posts);
        $new_failed = $session->failed_posts + $failed_count;
        $status = ($new_processed >= $session->total_posts) ? 'completed' : 'processing';
        
        $wpdb->update(
            $table_name,
            array(
                'processed_posts' => $new_processed,
                'failed_posts' => $new_failed,
                'status' => $status
            ),
            array('session_id' => $session_id)
        );
        
        wp_send_json_success(array(
            'imported' => $imported_count,
            'failed' => $failed_count,
            'skipped' => $skipped_count,
            'total_processed' => $new_processed,
            'total_posts' => $session->total_posts,
            'status' => $status,
            'percentage' => round(($new_processed / $session->total_posts) * 100, 2)
        ));
    }
    
    public function reimport_posts_batch() {
        check_ajax_referer('post_importer_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $session_id = sanitize_text_field($_POST['session_id']);
        $batch_size = intval($_POST['batch_size']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_import_progress';
        
        // Get import session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error('Import session not found');
            return;
        }
        
        // Load JSON data
        $posts_data = $this->analyze_json_file($session->file_path);
        
        if ($posts_data === false) {
            wp_send_json_error('Unable to read JSON file');
            return;
        }
        
        // Calculate batch
        $start_index = $session->processed_posts;
        $end_index = min($start_index + $batch_size, count($posts_data));
        
        $batch_posts = array_slice($posts_data, $start_index, $batch_size);
        
        $imported_count = 0;
        $failed_count = 0;
        $skipped_count = 0;
        
        foreach ($batch_posts as $post_data) {
            $result = $this->reimport_single_post($post_data, $session_id);
            
            if ($result === 'imported') {
                $imported_count++;
            } elseif ($result === 'failed') {
                $failed_count++;
            } else {
                $skipped_count++;
            }
        }
        
        // Update progress
        $new_processed = $session->processed_posts + count($batch_posts);
        $new_failed = $session->failed_posts + $failed_count;
        
        $status = ($new_processed >= $session->total_posts) ? 'completed' : 'processing';
        
        $wpdb->update(
            $table_name,
            array(
                'processed_posts' => $new_processed,
                'failed_posts' => $new_failed,
                'status' => $status
            ),
            array('session_id' => $session_id)
        );
        
        wp_send_json_success(array(
            'imported' => $imported_count,
            'failed' => $failed_count,
            'skipped' => $skipped_count,
            'total_processed' => $new_processed,
            'total_posts' => $session->total_posts,
            'status' => $status,
            'percentage' => round(($new_processed / $session->total_posts) * 100, 2)
        ));
    }
    
    private function import_single_post($post_data, $session_id) {
        global $wpdb;
        
        try {
            // Check if post already exists by slug
            $existing_post = get_page_by_path($post_data['slug'], OBJECT, 'post');
            if ($existing_post) {
                return 'skipped'; // Post already exists
            }
            
            // Also check by original post ID to prevent duplicates from re-imports
            $existing_by_original_id = get_posts(array(
                'meta_key' => '_original_post_id',
                'meta_value' => $post_data['id'],
                'post_type' => 'post',
                'post_status' => 'any',
                'numberposts' => 1
            ));
            
            if (!empty($existing_by_original_id)) {
                return 'skipped'; // Post with this original ID already exists
            }
            
            // Prepare post data with proper date handling
            $original_publish_date = $this->parse_date($post_data['formatted_first_published_at_datetime']);
            $original_modified_date = $this->parse_date($post_data['formatted_last_published_at_datetime']);
            
            $wp_post_data = array(
                'post_title' => sanitize_text_field($post_data['title']),
                'post_content' => wp_kses_post($post_data['content']),
                'post_excerpt' => sanitize_text_field(!empty($post_data['short_description']) ? $post_data['short_description'] : $post_data['summary']),
                'post_name' => sanitize_title($post_data['slug']),
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date' => $original_publish_date,        // Keep original publish date
                'post_date_gmt' => get_gmt_from_date($original_publish_date),
                'post_modified' => $original_modified_date,   // Keep original modified date initially
                'post_modified_gmt' => get_gmt_from_date($original_modified_date)
            );
            
            // Insert post
            $post_id = wp_insert_post($wp_post_data);
            
            if (is_wp_error($post_id)) {
                error_log('Post Importer: Failed to insert post: ' . $post_id->get_error_message());
                return 'failed';
            }
            
            // Store original dates for reference
            update_post_meta($post_id, '_original_publish_date', $post_data['formatted_first_published_at_datetime']);
            update_post_meta($post_id, '_original_modified_date', $post_data['formatted_last_published_at_datetime']);
            update_post_meta($post_id, '_import_date', current_time('mysql'));
            
            error_log("Post Importer: Created post {$post_id} with original publish date: {$original_publish_date}");
            
            // Handle categories
            if (!empty($post_data['categories'])) {
                $this->handle_categories($post_id, $post_data['categories']);
            }
            
            // Handle tags
            if (!empty($post_data['tags'])) {
                $this->handle_tags($post_id, $post_data['tags']);
            }
            
            // Handle featured image from banner_url
            $image_imported = false;
            if (!empty($post_data['banner_url'])) {
                $image_result = $this->handle_featured_image($post_id, $post_data['banner_url'], $post_data['title']);
                if ($image_result) {
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['banner_url']);
                    $image_imported = true;
                } else {
                    // Log that featured image failed to import but don't fail the whole post
                    error_log("Post Importer: Failed to import featured image for post ID {$post_id}, banner_url: {$post_data['banner_url']}");
                }
            }
            // Also try media_file_banner if banner_url is empty or failed
            elseif (!empty($post_data['media_file_banner']['path'])) {
                $image_result = $this->handle_featured_image($post_id, $post_data['media_file_banner']['path'], $post_data['title']);
                if ($image_result) {
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['media_file_banner']['path']);
                    $image_imported = true;
                } else {
                    error_log("Post Importer: Failed to import featured image for post ID {$post_id}, media_file_banner path: {$post_data['media_file_banner']['path']}");
                }
            }
            
            // Log image import status
            update_post_meta($post_id, '_featured_image_imported', $image_imported ? 'yes' : 'no');
            
            // Handle author from member field
            if (!empty($post_data['member'])) {
                $this->handle_author($post_id, $post_data['member']);
            }
            
            // Handle contributors (additional authors)
            if (!empty($post_data['contributors'])) {
                $this->handle_contributors($post_id, $post_data['contributors']);
            }
            
            // Handle updated_by author info
            if (!empty($post_data['updated_by'])) {
                $this->handle_updated_by($post_id, $post_data['updated_by']);
            }
            
            // Handle meta data
            if (!empty($post_data['meta_data'])) {
                $this->handle_meta_data($post_id, $post_data['meta_data']);
            }
            
            // Store comprehensive meta data for reference
            update_post_meta($post_id, '_original_post_id', $post_data['id']);
            update_post_meta($post_id, '_import_session_id', $session_id);
            update_post_meta($post_id, '_original_url', !empty($post_data['absolute_url']) ? $post_data['absolute_url'] : '');
            update_post_meta($post_id, '_legacy_url', !empty($post_data['legacy_url']) ? $post_data['legacy_url'] : '');
            update_post_meta($post_id, '_word_count', !empty($post_data['word_count']) ? $post_data['word_count'] : 0);
            update_post_meta($post_id, '_article_type', !empty($post_data['type']) ? $post_data['type'] : 'Article');
            update_post_meta($post_id, '_language_code', !empty($post_data['language_code']) ? $post_data['language_code'] : 'en');
            update_post_meta($post_id, '_access_type', !empty($post_data['access_type']) ? $post_data['access_type'] : 'Public');
            update_post_meta($post_id, '_summary', !empty($post_data['summary']) ? $post_data['summary'] : '');
            update_post_meta($post_id, '_banner_description', !empty($post_data['banner_description']) ? $post_data['banner_description'] : '');
            update_post_meta($post_id, '_hide_banner_image', !empty($post_data['hide_banner_image']) ? $post_data['hide_banner_image'] : null);
            
            // Store media file banner info if available
            if (!empty($post_data['media_file_banner'])) {
                update_post_meta($post_id, '_media_file_banner', json_encode($post_data['media_file_banner']));
            }
            
            // Store cache tags if available
            if (!empty($post_data['Cache-Tags'])) {
                update_post_meta($post_id, '_cache_tags', json_encode($post_data['Cache-Tags']));
            }
            
            // Handle primary category if specified
            if (!empty($post_data['primary_category'])) {
                $primary_cat_id = $this->get_or_create_category($post_data['primary_category']['name'], $post_data['primary_category']['slug']);
                if ($primary_cat_id) {
                    update_post_meta($post_id, '_yoast_wpseo_primary_category', $primary_cat_id);
                }
            }
            
            // Handle content with images - add this after the post is created
            $content_fields = ['content', 'content_html', 'post_content', 'body'];
            $original_content = '';

            foreach ($content_fields as $field) {
                if (isset($post_data[$field]) && !empty($post_data[$field])) {
                    $original_content = $post_data[$field];
                    break;
                }
            }

            if (!empty($original_content)) {
                error_log("Post Importer: Found content to process for post {$post_id}");
                
                // Process images in content BEFORE setting other meta data
                $processed_content = $this->process_content_images($original_content, $post_id, $post_data['title'] ?? '');
                
                // Update the post with processed content
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $processed_content
                ));
                
                error_log("Post Importer: Updated post {$post_id} content with processed images");
            }
            
            // After successful wp_insert_post
            $this->last_imported_post_id = $post_id;
            
            return 'imported';
            
        } catch (Exception $e) {
            // Log failed post
            $failed_table = $wpdb->prefix . 'post_import_failed';
            $wpdb->insert(
                $failed_table,
                array(
                    'session_id' => $session_id,
                    'post_data' => json_encode($post_data),
                    'error_message' => $e->getMessage()
                )
            );
            
            return 'failed';
        }
    }
    
    private function reimport_single_post($post_data, $session_id, $force_replace = true) {
        global $wpdb;
        
        try {
            // Find existing post by slug or original post ID
            $existing_post = get_page_by_path($post_data['slug'], OBJECT, 'post');
            
            if (!$existing_post) {
                // Also check by original post ID
                $existing_by_original_id = get_posts(array(
                    'meta_key' => '_original_post_id',
                    'meta_value' => $post_data['id'],
                    'post_type' => 'post',
                    'post_status' => 'any',
                    'numberposts' => 1
                ));
                
                if (!empty($existing_by_original_id)) {
                    $existing_post = $existing_by_original_id[0];
                }
            }
            
            if (!$existing_post) {
                // Post doesn't exist, so import it as new
                return $this->import_single_post($post_data, $session_id);
            }
            
            $post_id = $existing_post->ID;
            
            // Update the existing post with new data while preserving publish date
            $original_publish_date = get_post_field('post_date', $post_id); // Keep existing publish date
            $new_content_modified_date = $this->parse_date($post_data['formatted_last_published_at_datetime']);

            $wp_post_data = array(
                'ID' => $post_id,
                'post_title' => sanitize_text_field($post_data['title']),
                'post_content' => wp_kses_post($post_data['content']),
                'post_excerpt' => sanitize_text_field(!empty($post_data['short_description']) ? $post_data['short_description'] : $post_data['summary']),
                'post_name' => sanitize_title($post_data['slug']),
                'post_status' => 'publish',
                'post_date' => $original_publish_date,        // PRESERVE original publish date
                'post_date_gmt' => get_gmt_from_date($original_publish_date),
                // Don't set post_modified - let WordPress set it to current time for the update
            );
            
            // Update post (WordPress will automatically set post_modified to current time)
            $result = wp_update_post($wp_post_data);

            if (is_wp_error($result)) {
                error_log('Post Importer: Failed to update post: ' . $result->get_error_message());
                return 'failed';
            }

            // Store metadata about the update
            update_post_meta($post_id, '_last_content_modified_date', $new_content_modified_date);
            update_post_meta($post_id, '_last_reimport_date', current_time('mysql'));
            update_post_meta($post_id, '_reimport_count', intval(get_post_meta($post_id, '_reimport_count', true)) + 1);

            error_log("Post Importer: Updated post {$post_id} - kept original publish date: {$original_publish_date}, WordPress updated modified date automatically");
            
            // Remove existing featured image if replacing
            if ($force_replace && has_post_thumbnail($post_id)) {
                $old_thumbnail_id = get_post_thumbnail_id($post_id);
                delete_post_thumbnail($post_id);
                
                // Delete the old image file from media library if it was imported by our plugin
                // Check if the image was imported by our plugin using the metadata we store
                $was_imported_by_us = get_post_meta($old_thumbnail_id, '_imported_by_post_importer', true);
                if ($was_imported_by_us) {
                    // This image was imported by our plugin, safe to delete
                    wp_delete_attachment($old_thumbnail_id, true);
                }
            }
            
            // Handle categories (clear existing and add new)
            wp_set_post_categories($post_id, array()); // Clear existing categories
            if (!empty($post_data['categories'])) {
                $this->handle_categories($post_id, $post_data['categories']);
            }
            
            // Handle tags (clear existing and add new)
            wp_set_post_terms($post_id, array(), 'post_tag'); // Clear existing tags
            if (!empty($post_data['tags'])) {
                $this->handle_tags($post_id, $post_data['tags']);
            }
            
            // Handle featured image replacement
            $image_imported = false;
            if (!empty($post_data['banner_url'])) {
                $image_result = $this->handle_featured_image($post_id, $post_data['banner_url'], $post_data['title'], true);
                if ($image_result) {
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['banner_url']);
                    $image_imported = true;
                } else {
                    error_log("Post Importer: Failed to reimport featured image for post ID {$post_id}, banner_url: {$post_data['banner_url']}");
                }
            }
            // Also try media_file_banner if banner_url is empty or failed
            elseif (!empty($post_data['media_file_banner']['path'])) {
                $image_result = $this->handle_featured_image($post_id, $post_data['media_file_banner']['path'], $post_data['title'], true);
                if ($image_result) {
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['media_file_banner']['path']);
                    $image_imported = true;
                } else {
                    error_log("Post Importer: Failed to reimport featured image for post ID {$post_id}, media_file_banner path: {$post_data['media_file_banner']['path']}");
                }
            }
            
            // Update image import status
            update_post_meta($post_id, '_featured_image_imported', $image_imported ? 'yes' : 'no');
            update_post_meta($post_id, '_last_reimported', current_time('mysql'));
            
            // Handle author from member field
            if (!empty($post_data['member'])) {
                $this->handle_author($post_id, $post_data['member']);
            }
            
            // Handle contributors (clear existing and add new)
            if (!empty($post_data['contributors'])) {
                // Clear existing contributor meta
                delete_post_meta($post_id, '_contributors');
                delete_post_meta($post_id, '_contributors_data');
                $this->handle_contributors($post_id, $post_data['contributors']);
            }
            
            // Handle updated_by author info
            if (!empty($post_data['updated_by'])) {
                $this->handle_updated_by($post_id, $post_data['updated_by']);
            }
            
            // Handle meta data (replace existing)
            if (!empty($post_data['meta_data'])) {
                $this->handle_meta_data($post_id, $post_data['meta_data']);
            }
            
            // Update comprehensive meta data for reference
            update_post_meta($post_id, '_original_post_id', $post_data['id']);
            update_post_meta($post_id, '_import_session_id', $session_id);
            update_post_meta($post_id, '_original_url', !empty($post_data['absolute_url']) ? $post_data['absolute_url'] : '');
            update_post_meta($post_id, '_legacy_url', !empty($post_data['legacy_url']) ? $post_data['legacy_url'] : '');
            update_post_meta($post_id, '_word_count', !empty($post_data['word_count']) ? $post_data['word_count'] : 0);
            update_post_meta($post_id, '_article_type', !empty($post_data['type']) ? $post_data['type'] : 'Article');
            update_post_meta($post_id, '_language_code', !empty($post_data['language_code']) ? $post_data['language_code'] : 'en');
            update_post_meta($post_id, '_access_type', !empty($post_data['access_type']) ? $post_data['access_type'] : 'Public');
            update_post_meta($post_id, '_summary', !empty($post_data['summary']) ? $post_data['summary'] : '');
            update_post_meta($post_id, '_banner_description', !empty($post_data['banner_description']) ? $post_data['banner_description'] : '');
            update_post_meta($post_id, '_hide_banner_image', !empty($post_data['hide_banner_image']) ? $post_data['hide_banner_image'] : null);
            
            // Store media file banner info if available
            if (!empty($post_data['media_file_banner'])) {
                update_post_meta($post_id, '_media_file_banner', json_encode($post_data['media_file_banner']));
            }
            
            // Store cache tags if available
            if (!empty($post_data['Cache-Tags'])) {
                update_post_meta($post_id, '_cache_tags', json_encode($post_data['Cache-Tags']));
            }
            
            // Handle primary category if specified
            if (!empty($post_data['primary_category'])) {
                $primary_cat_id = $this->get_or_create_category($post_data['primary_category']['name'], $post_data['primary_category']['slug']);
                if ($primary_cat_id) {
                    update_post_meta($post_id, '_yoast_wpseo_primary_category', $primary_cat_id);
                }
            }
            
            // Handle content with images during reimport
            $content_fields = ['content', 'content_html', 'post_content', 'body'];
            $original_content = '';

            foreach ($content_fields as $field) {
                if (isset($post_data[$field]) && !empty($post_data[$field])) {
                    $original_content = $post_data[$field];
                    break;
                }
            }

            if (!empty($original_content)) {
                error_log("Post Importer: Processing content images during reimport for post {$post_id}");
                
                // Process images in content (force download new images during reimport)
                $processed_content = $this->process_content_images($original_content, $post_id, $post_data['title'] ?? '');
                
                // Update the post with processed content
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $processed_content
                ));
                
                error_log("Post Importer: Updated post {$post_id} content with processed images during reimport");
            }
            
            return 'imported';
            
        } catch (Exception $e) {
            // Log failed post
            $failed_table = $wpdb->prefix . 'post_import_failed';
            $wpdb->insert(
                $failed_table,
                array(
                    'session_id' => $session_id,
                    'post_data' => json_encode($post_data),
                    'error_message' => 'Reimport failed: ' . $e->getMessage()
                )
            );
            
            return 'failed';
        }
    }
    
    private function parse_date($date_string) {
        if (empty($date_string)) {
            return current_time('mysql');
        }
        
        // Try multiple date formats
        $formats = [
            'Y-m-d\TH:i:s.uP',     // 2025-07-24T15:23:35.152+05:30
            'Y-m-d\TH:i:sP',       // 2025-07-24T15:23:35+05:30  
            'Y-m-d\TH:i:s.u',      // 2025-07-24T15:23:35.152
            'Y-m-d\TH:i:s',        // 2025-07-24T15:23:35
            'Y-m-d H:i:s',         // 2025-07-24 15:23:35
            'Y-m-d'                // 2025-07-24
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $date_string);
            if ($date !== false) {
                // Convert to WordPress timezone
                $date->setTimezone(new DateTimeZone(wp_timezone_string()));
                return $date->format('Y-m-d H:i:s');
            }
        }
        
        error_log("Post Importer: Could not parse date: {$date_string}");
        return current_time('mysql');
    }
    
    private function handle_categories($post_id, $categories) {
        $category_ids = array();
        
        foreach ($categories as $category) {
            $cat_id = $this->get_or_create_category($category['name'], $category['slug']);
            if ($cat_id) {
                $category_ids[] = $cat_id;
            }
        }
        
        if (!empty($category_ids)) {
            wp_set_post_categories($post_id, $category_ids);
        }
    }
    
    private function get_or_create_category($name, $slug) {
        $category = get_category_by_slug($slug);
        
        if ($category) {
            return $category->term_id;
        }
        
        // Use wp_insert_term instead of deprecated wp_insert_category
        $result = wp_insert_term($name, 'category', array(
            'slug' => $slug
        ));
        
        if (is_wp_error($result)) {
            error_log("Post Importer: Failed to create category '{$name}': " . $result->get_error_message());
            return false;
        }
        
        return $result['term_id'];
    }
    
    private function handle_tags($post_id, $tags) {
        $tag_ids = array();
        
        foreach ($tags as $tag) {
            // Check if tag already exists by slug
            $tag_obj = get_term_by('slug', $tag['slug'], 'post_tag');
            
            if (!$tag_obj) {
                // Create new tag
                $new_tag = wp_insert_term($tag['name'], 'post_tag', array(
                    'slug' => $tag['slug']
                ));
                
                if (!is_wp_error($new_tag)) {
                    $tag_ids[] = $new_tag['term_id'];
                }
            } else {
                $tag_ids[] = $tag_obj->term_id;
            }
        }
        
        if (!empty($tag_ids)) {
            wp_set_post_terms($post_id, $tag_ids, 'post_tag');
        }
    }
    
    private function handle_featured_image($post_id, $image_url, $post_title, $force_replace = false) {
        // Validate inputs
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            error_log("Post Importer: Invalid image URL for post {$post_id}: {$image_url}");
            return false;
        }
        
        // Ensure post exists and is valid
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') {
            error_log("Post Importer: Post {$post_id} does not exist or is trashed");
            return false;
        }
        
        // Ensure post thumbnail support is enabled
        if (!current_theme_supports('post-thumbnails')) {
            add_theme_support('post-thumbnails');
            error_log("Post Importer: Added post-thumbnails theme support");
        }
        
        global $wpdb;
        
        // Handle existing featured image cleanup if force_replace is true
        if ($force_replace && has_post_thumbnail($post_id)) {
            $old_thumbnail_id = get_post_thumbnail_id($post_id);
            $this->cleanup_old_featured_image($post_id, $old_thumbnail_id);
        }
        
        // If not forcing replacement, check for existing images to reuse
        if (!$force_replace) {
            // Check if image already exists in media library by URL
            $existing_attachment = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s",
                $image_url
            ));
            
            if ($existing_attachment) {
                // Set existing image as featured image
                $result = set_post_thumbnail($post_id, $existing_attachment);
                if ($result) {
                    error_log("Post Importer: Reused existing image {$existing_attachment} for post {$post_id}");
                    return $existing_attachment;
                } else {
                    error_log("Post Importer: Failed to set existing image {$existing_attachment} as thumbnail for post {$post_id}");
                }
            }
            
            // Check by filename to avoid duplicate downloads
            $filename = basename(parse_url($image_url, PHP_URL_PATH));
            if (!empty($filename)) {
                $existing_by_filename = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND (post_title = %s OR post_name = %s)",
                    pathinfo($filename, PATHINFO_FILENAME),
                    sanitize_title(pathinfo($filename, PATHINFO_FILENAME))
                ));
                
                if ($existing_by_filename) {
                    $result = set_post_thumbnail($post_id, $existing_by_filename);
                    if ($result) {
                        error_log("Post Importer: Reused existing image by filename {$existing_by_filename} for post {$post_id}");
                        return $existing_by_filename;
                    }
                }
            }
        }
        
        // Download new image
        error_log("Post Importer: Downloading new image from {$image_url} for post {$post_id}");
        $image_id = $this->download_image($image_url, $post_title, $post_id);
        
        if (!$image_id || is_wp_error($image_id) || !is_numeric($image_id)) {
            error_log("Post Importer: Failed to download image from {$image_url}");
            return false;
        }
        
        // Verify the attachment was created successfully
        $attachment = get_post($image_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            error_log("Post Importer: Downloaded image ID {$image_id} is not a valid attachment");
            return false;
        }
        
        // Set as featured image with multiple attempts
        error_log("Post Importer: Setting image {$image_id} as featured image for post {$post_id}");
        
        // Method 1: Use WordPress function
        $thumbnail_result = set_post_thumbnail($post_id, $image_id);
        
        if (!$thumbnail_result) {
            error_log("Post Importer: set_post_thumbnail() failed, trying direct meta update");
            
            // Method 2: Direct meta update as fallback
            $meta_result = update_post_meta($post_id, '_thumbnail_id', $image_id);
            
            if (!$meta_result) {
                error_log("Post Importer: Direct meta update also failed for post {$post_id}");
                return false;
            }
        }
        
        // Verify the thumbnail was actually set
        $current_thumbnail = get_post_thumbnail_id($post_id);
        error_log("Post Importer: Before verification - Expected: {$image_id}, Current: {$current_thumbnail}");

        if ($current_thumbnail != $image_id) {
            error_log("Post Importer: Thumbnail verification failed. Expected: {$image_id}, Got: {$current_thumbnail}");
            
            // Check what's in the post meta directly
            $meta_thumbnail = get_post_meta($post_id, '_thumbnail_id', true);
            error_log("Post Importer: Direct meta check shows: {$meta_thumbnail}");
            
            // Final attempt with wp_update_post to trigger hooks
            wp_update_post(array(
                'ID' => $post_id,
                'meta_input' => array(
                    '_thumbnail_id' => $image_id
                )
            ));
            
            // Check one more time
            $current_thumbnail = get_post_thumbnail_id($post_id);
            error_log("Post Importer: After final attempt - Current: {$current_thumbnail}");
            
            if ($current_thumbnail != $image_id) {
                error_log("Post Importer: All methods failed to set featured image for post {$post_id}");
                return false;
            }
        }
        
        error_log("Post Importer: Successfully set featured image {$image_id} for post {$post_id}");
        
        // Update the attachment to have better metadata
        wp_update_post(array(
            'ID' => $image_id,
            'post_title' => $post_title,
            'post_excerpt' => $post_title, // Caption
        ));
        
        // Set alt text
        update_post_meta($image_id, '_wp_attachment_image_alt', $post_title);
        
        return $image_id;
    }
    
    private function download_image($image_url, $description = '', $post_id = 0) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            error_log("Post Importer: Invalid URL format: {$image_url}");
            return false;
        }
        
        // Check if image already exists in media library by URL (avoid duplicates)
        global $wpdb;
        $existing_attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
             AND (guid = %s OR post_content = %s)",
            $image_url, $image_url
        ));
        
        if ($existing_attachment) {
            error_log("Post Importer: Reusing existing image ID: {$existing_attachment} for URL: {$image_url}");
            return $existing_attachment;
        }
        
        // Check if URL is accessible
        $response = wp_remote_head($image_url, array('timeout' => 30));
        if (is_wp_error($response)) {
            error_log("Post Importer: URL check failed - WP Error: " . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            error_log("Post Importer: URL not accessible, response code: {$response_code}");
            return false;
        }
        
        // Verify the post exists if post_id is provided
        if ($post_id > 0) {
            $post = get_post($post_id);
            if (!$post) {
                error_log("Post Importer: Post ID {$post_id} does not exist, downloading without attachment");
                $post_id = 0; // Download without attachment
            }
        }
        
        error_log("Post Importer: Attempting to download image from {$image_url} for post {$post_id}");
        
        // Download the image and attach it to the post
        $image_id = media_sideload_image($image_url, $post_id, $description, 'id');
        
        if (is_wp_error($image_id)) {
            error_log('Post Importer: media_sideload_image failed for URL ' . $image_url . ': ' . $image_id->get_error_message());
            return false;
        }
        
        // Verify the image was actually created
        if (!$image_id || !is_numeric($image_id)) {
            error_log("Post Importer: media_sideload_image returned invalid ID: " . print_r($image_id, true));
            return false;
        }
        
        // Verify the attachment exists
        $attachment = get_post($image_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            error_log("Post Importer: Downloaded image ID {$image_id} is not a valid attachment");
            return false;
        }
        
        error_log("Post Importer: Successfully downloaded image from {$image_url}, attachment ID: {$image_id}");
        
        // Mark this image as imported by our plugin for future reference
        update_post_meta($image_id, '_imported_by_post_importer', true);
        update_post_meta($image_id, '_original_image_url', $image_url);
        update_post_meta($image_id, '_import_timestamp', current_time('mysql'));
        
        return $image_id;
    }
    
    private function handle_author($post_id, $author_data) {
        // First try to find user by email
        $user = get_user_by('email', $author_data['email']);
        
        if (!$user) {
            // Try to find by username/slug
            $user = get_user_by('login', $author_data['slug']);
        }
        
        if (!$user) {
            // Create new user if doesn't exist
            $user_id = wp_create_user(
                $author_data['slug'],
                wp_generate_password(),
                $author_data['email']
            );
            
            if (!is_wp_error($user_id)) {
                wp_update_user(array(
                    'ID' => $user_id,
                    'display_name' => $author_data['name'],
                    'description' => $author_data['description'],
                    'first_name' => $author_data['name'],
                    'nickname' => $author_data['name']
                ));
                
                // Add social media meta if available
                if (!empty($author_data['facebook'])) {
                    update_user_meta($user_id, 'facebook', $author_data['facebook']);
                }
                if (!empty($author_data['linkedin'])) {
                    update_user_meta($user_id, 'linkedin', $author_data['linkedin']);
                }
                if (!empty($author_data['instagram'])) {
                    update_user_meta($user_id, 'instagram', $author_data['instagram']);
                }
                if (!empty($author_data['twitter'])) {
                    update_user_meta($user_id, 'twitter', $author_data['twitter']);
                }
                
                // Store original author ID for reference
                update_user_meta($user_id, '_original_author_id', $author_data['id']);
                
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_author' => $user_id
                ));
            }
        } else {
            wp_update_post(array(
                'ID' => $post_id,
                'post_author' => $user->ID
            ));
        }
    }
    
    private function handle_meta_data($post_id, $meta_data) {
        foreach ($meta_data as $key => $value) {
            // Skip thumbnail-related meta to avoid overwriting our featured image
            if ($key === '_thumbnail_id' || $key === 'thumbnail_id') {
                continue;
            }
            
            // Skip other WordPress core meta that could interfere
            $skip_meta = array(
                '_thumbnail_id',
                'thumbnail_id',
                '_wp_attached_file',
                '_wp_attachment_metadata',
                '_edit_lock',
                '_edit_last'
            );
            
            if (in_array($key, $skip_meta)) {
                continue;
            }
            
            if (is_array($value) && count($value) == 1) {
                $value = $value[0];
            }
            
            if (is_string($value)) {
                $value = trim($value, "[]'\"");
            }
            
            update_post_meta($post_id, $key, $value);
        }
    }
    
    private function handle_contributors($post_id, $contributors) {
        if (!empty($contributors) && is_array($contributors)) {
            $contributor_names = array();
            $contributor_data = array();
            
            foreach ($contributors as $contributor) {
                if (!empty($contributor['name'])) {
                    $contributor_names[] = $contributor['name'];
                    $contributor_data[] = array(
                        'name' => $contributor['name'],
                        'email' => !empty($contributor['email']) ? $contributor['email'] : '',
                        'slug' => !empty($contributor['slug']) ? $contributor['slug'] : '',
                        'id' => !empty($contributor['id']) ? $contributor['id'] : 0
                    );
                }
            }
            
            if (!empty($contributor_names)) {
                update_post_meta($post_id, '_contributors', implode(', ', $contributor_names));
                update_post_meta($post_id, '_contributors_data', json_encode($contributor_data));
            }
        }
    }
    
    private function handle_updated_by($post_id, $updated_by) {
        if (!empty($updated_by) && is_array($updated_by)) {
            update_post_meta($post_id, '_updated_by_name', !empty($updated_by['name']) ? $updated_by['name'] : '');
            update_post_meta($post_id, '_updated_by_email', !empty($updated_by['email']) ? $updated_by['email'] : '');
            update_post_meta($post_id, '_updated_by_slug', !empty($updated_by['slug']) ? $updated_by['slug'] : '');
            update_post_meta($post_id, '_updated_by_id', !empty($updated_by['id']) ? $updated_by['id'] : 0);
            update_post_meta($post_id, '_updated_by_data', json_encode($updated_by));
        }
    }
    
    public function get_import_status() {
        check_ajax_referer('post_importer_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_import_progress';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$session) {
            wp_send_json_error('Session not found');
            return;
        }
        
        wp_send_json_success(array(
            'total_posts' => $session->total_posts,
            'processed_posts' => $session->processed_posts,
            'failed_posts' => $session->failed_posts,
            'status' => $session->status,
            'percentage' => $session->total_posts > 0 ? round(($session->processed_posts / $session->total_posts) * 100, 2) : 0
        ));
    }
    
    public function reset_import() {
        check_ajax_referer('post_importer_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_import_progress';
        $failed_table = $wpdb->prefix . 'post_import_failed';
        
        // Reset progress
        $wpdb->update(
            $table_name,
            array(
                'processed_posts' => 0,
                'failed_posts' => 0,
                'status' => 'ready'
            ),
            array('session_id' => $session_id)
        );
        
        // Clear failed posts
        $wpdb->delete($failed_table, array('session_id' => $session_id));
        
        wp_send_json_success('Import reset successfully');
    }
    
    private function cleanup_old_featured_image($post_id, $old_thumbnail_id) {
        /**
         * Clean up old featured image when replacing with new one
         * This saves space by removing old images that are no longer needed
         */
        if (!$old_thumbnail_id) {
            return;
        }
        
        // Remove the thumbnail association
        delete_post_thumbnail($post_id);
        
        // Check if this image was imported by our plugin
        $was_imported_by_us = get_post_meta($old_thumbnail_id, '_imported_by_post_importer', true);
        
        if ($was_imported_by_us) {
            // Check if any other posts are using this image as featured image
            global $wpdb;
            $other_usage = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_thumbnail_id' 
                 AND meta_value = %s 
                 AND post_id != %s",
                $old_thumbnail_id,
                $post_id
            ));
            
            // If no other posts are using this image, delete it to save space
            if ($other_usage == 0) {
                error_log("Post Importer: Deleting unused featured image {$old_thumbnail_id} to save space");
                wp_delete_attachment($old_thumbnail_id, true);
            } else {
                error_log("Post Importer: Keeping featured image {$old_thumbnail_id} as it's used by {$other_usage} other posts");
            }
        } else {
            error_log("Post Importer: Not deleting featured image {$old_thumbnail_id} as it wasn't imported by our plugin");
        }
    }
    
    public function register_api_endpoints() {
        register_rest_route('post-importer/v1', '/import-post', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_import_post'),
            'permission_callback' => array($this, 'api_permission_check'),
            'args' => array(
                'post_data' => array(
                    'required' => true,
                    'type' => 'object'
                ),
                'api_key' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'force_replace' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false
                )
            )
        ));
        
        register_rest_route('post-importer/v1', '/update-images', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_update_images'),
            'permission_callback' => array($this, 'api_permission_check'),
            'args' => array(
                'post_data' => array(
                    'required' => true,
                    'type' => 'object'
                ),
                'api_key' => array(
                    'required' => true,
                    'type' => 'string'
                ),
                'force_replace' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => true
                ),
                'update_images_only' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false
                )
            )
        ));
        
        register_rest_route('post-importer/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_get_status'),
            'permission_callback' => array($this, 'api_permission_check')
        ));
    }
    
    private function process_content_images($content, $post_id, $post_title = '') {
        // Skip if content is empty
        if (empty($content)) {
            return $content;
        }
        
        error_log("Post Importer: Processing content images for post {$post_id}");
        
        // Find all img tags in the content
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER);
        
        if (empty($matches)) {
            error_log("Post Importer: No images found in content for post {$post_id}");
            return $content;
        }
        
        error_log("Post Importer: Found " . count($matches) . " images in content for post {$post_id}");
        
        $processed_content = $content;
        
        foreach ($matches as $match) {
            $full_img_tag = $match[0];
            $src_url = $match[1];
            
            // Skip if it's already a local WordPress image
            if (strpos($src_url, wp_upload_dir()['baseurl']) !== false) {
                error_log("Post Importer: Skipping local image: {$src_url}");
                continue;
            }
            
            // Skip if it's a data URL
            if (strpos($src_url, 'data:') === 0) {
                error_log("Post Importer: Skipping data URL image");
                continue;
            }
            
            // Download and process the image
            error_log("Post Importer: Processing content image: {$src_url}");
            $new_image_id = $this->download_image($src_url, $post_title . ' - Content Image', $post_id);
            
            if ($new_image_id && !is_wp_error($new_image_id)) {
                // Get the new local URL
                $new_image_url = wp_get_attachment_url($new_image_id);
                
                if ($new_image_url) {
                    // Replace the old URL with the new local URL
                    $processed_content = str_replace($src_url, $new_image_url, $processed_content);
                    error_log("Post Importer: Replaced content image URL for post {$post_id}: {$src_url} -> {$new_image_url}");
                    
                    // Mark this image as imported by our plugin
                    update_post_meta($new_image_id, '_imported_by_post_importer', '1');
                    update_post_meta($new_image_id, '_imported_for_post', $post_id);
                } else {
                    error_log("Post Importer: Failed to get URL for downloaded image {$new_image_id}");
                }
            } else {
                error_log("Post Importer: Failed to download content image: {$src_url}");
            }
        }
        
        return $processed_content;
    }

    public function api_permission_check($request) {
        // Simple API key authentication
        $api_key = $request->get_param('api_key') ?: $request->get_header('X-API-Key');
        $stored_key = get_option('post_importer_api_key', 'climaterural-secret-key-2025');
        
        return $api_key === $stored_key;
    }

    public function api_import_post($request) {
        $post_data = $request->get_param('post_data');
        $force_replace = $request->get_param('force_replace') ?: false;
        
        try {
            // Add error logging and validation
            error_log("Post Importer API: Starting import for post: " . ($post_data['title'] ?? 'Unknown'));
            
            // Validate required fields
            if (empty($post_data['title']) || empty($post_data['slug'])) {
                throw new Exception('Missing required fields: title or slug');
            }
            
            // Increase memory and execution limits for API requests
            if (function_exists('ini_set')) {
                ini_set('memory_limit', '512M');
                ini_set('max_execution_time', 120);
            }
            
            // Use reimport logic if force_replace is true
            if ($force_replace) {
                $result = $this->reimport_single_post($post_data, 'api_import_' . time(), true);
            } else {
                $result = $this->import_single_post($post_data, 'api_import_' . time());
            }
            
            // Track the imported post ID
            $response_data = array(
                'success' => true,
                'result' => $result,
                'message' => "Post '{$post_data['title']}' {$result}",
                'force_replace' => $force_replace
            );
            
            if ($result === 'imported' && $this->last_imported_post_id) {
                $response_data['post_id'] = $this->last_imported_post_id;
            }
            
            error_log("Post Importer API: Import result for '{$post_data['title']}': {$result}");
            
            return new WP_REST_Response($response_data, 200);
            
        } catch (Exception $e) {
            error_log("Post Importer API: Error importing post '{$post_data['title']}': " . $e->getMessage());
            error_log("Post Importer API: Stack trace: " . $e->getTraceAsString());
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'post_data' => $post_data['title'] ?? 'Unknown',
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ), 500);
        } catch (Error $e) {
            error_log("Post Importer API: Fatal error importing post '{$post_data['title']}': " . $e->getMessage());
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Fatal error: ' . $e->getMessage(),
                'post_data' => $post_data['title'] ?? 'Unknown'
            ), 500);
        }
    }

    public function api_update_images($request) {
        $post_data = $request->get_param('post_data');
        $force_replace = $request->get_param('force_replace') ?: true;
        $update_images_only = $request->get_param('update_images_only') ?: false;
        
        try {
            // Add error logging and validation
            error_log("Post Importer API: Starting image update for post: " . ($post_data['title'] ?? 'Unknown'));
            
            // Validate required fields
            if (empty($post_data['title']) || empty($post_data['slug'])) {
                throw new Exception('Missing required fields: title or slug');
            }
            
            // Increase memory and execution limits for API requests
            if (function_exists('ini_set')) {
                ini_set('memory_limit', '512M');
                ini_set('max_execution_time', 120);
            }
            
            // Find existing post by slug or original post ID
            $existing_post = get_page_by_path($post_data['slug'], OBJECT, 'post');
            
            if (!$existing_post) {
                // Also check by original post ID
                $existing_by_original_id = get_posts(array(
                    'meta_key' => '_original_post_id',
                    'meta_value' => $post_data['id'],
                    'post_type' => 'post',
                    'post_status' => 'any',
                    'numberposts' => 1
                ));
                
                if (!empty($existing_by_original_id)) {
                    $existing_post = $existing_by_original_id[0];
                }
            }
            
            if (!$existing_post) {
                throw new Exception("Post not found: {$post_data['title']}");
            }
            
            $post_id = $existing_post->ID;
            $images_updated = array();
            
            // Update featured image
            $featured_image_updated = false;
            if (!empty($post_data['banner_url'])) {
                $old_thumbnail_id = get_post_thumbnail_id($post_id);
                
                $image_result = $this->handle_featured_image($post_id, $post_data['banner_url'], $post_data['title'], $force_replace);
                if ($image_result) {
                    $featured_image_updated = true;
                    $images_updated[] = 'featured_image';
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['banner_url']);
                    
                    // Clean up old image if force_replace is true
                    if ($force_replace && $old_thumbnail_id && $old_thumbnail_id != $image_result) {
                        $this->cleanup_old_featured_image($post_id, $old_thumbnail_id);
                    }
                    
                    error_log("Post Importer API: Updated featured image for post ID {$post_id}");
                } else {
                    error_log("Post Importer API: Failed to update featured image for post ID {$post_id}");
                }
            }
            
            // Also try media_file_banner if banner_url is empty or failed
            if (!$featured_image_updated && !empty($post_data['media_file_banner']['path'])) {
                $old_thumbnail_id = get_post_thumbnail_id($post_id);
                
                $image_result = $this->handle_featured_image($post_id, $post_data['media_file_banner']['path'], $post_data['title'], $force_replace);
                if ($image_result) {
                    $featured_image_updated = true;
                    $images_updated[] = 'featured_image_from_media_banner';
                    update_post_meta($post_id, '_banner_image_id', $image_result);
                    update_post_meta($post_id, '_banner_image_url', $post_data['media_file_banner']['path']);
                    
                    // Clean up old image if force_replace is true
                    if ($force_replace && $old_thumbnail_id && $old_thumbnail_id != $image_result) {
                        $this->cleanup_old_featured_image($post_id, $old_thumbnail_id);
                    }
                    
                    error_log("Post Importer API: Updated featured image from media_file_banner for post ID {$post_id}");
                }
            }
            
            // Update content images
            $content_images_updated = false;
            $content_fields = ['content', 'content_html', 'post_content', 'body'];
            $original_content = '';

            foreach ($content_fields as $field) {
                if (isset($post_data[$field]) && !empty($post_data[$field])) {
                    $original_content = $post_data[$field];
                    break;
                }
            }

            if (!empty($original_content)) {
                error_log("Post Importer API: Processing content images for post {$post_id}");
                
                // Process images in content and replace with new URLs
                $processed_content = $this->process_content_images($original_content, $post_id, $post_data['title'] ?? '');
                
                // Check if content was actually changed
                if ($processed_content !== $original_content) {
                    // Update the post content with processed images
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $processed_content
                    ));
                    
                    $content_images_updated = true;
                    $images_updated[] = 'content_images';
                    
                    error_log("Post Importer API: Updated content images for post {$post_id}");
                } else {
                    error_log("Post Importer API: No content images needed updating for post {$post_id}");
                }
            }
            
            // Update metadata
            update_post_meta($post_id, '_last_image_update_date', current_time('mysql'));
            update_post_meta($post_id, '_image_update_count', intval(get_post_meta($post_id, '_image_update_count', true)) + 1);
            
            $result_message = empty($images_updated) ? 'no_images_updated' : 'images_updated';
            
            // Track the updated post ID
            $response_data = array(
                'success' => true,
                'result' => $result_message,
                'message' => "Images updated for post '{$post_data['title']}'",
                'post_id' => $post_id,
                'images_updated' => $images_updated,
                'featured_image_updated' => $featured_image_updated,
                'content_images_updated' => $content_images_updated,
                'force_replace' => $force_replace
            );
            
            error_log("Post Importer API: Image update result for '{$post_data['title']}': {$result_message}");
            
            return new WP_REST_Response($response_data, 200);
            
        } catch (Exception $e) {
            error_log("Post Importer API: Error updating images for post '{$post_data['title']}': " . $e->getMessage());
            error_log("Post Importer API: Stack trace: " . $e->getTraceAsString());
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => $e->getMessage(),
                'post_data' => $post_data['title'] ?? 'Unknown',
                'trace' => WP_DEBUG ? $e->getTraceAsString() : null
            ), 500);
        } catch (Error $e) {
            error_log("Post Importer API: Fatal error updating images for post '{$post_data['title']}': " . $e->getMessage());
            
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Fatal error: ' . $e->getMessage(),
                'post_data' => $post_data['title'] ?? 'Unknown'
            ), 500);
        }
    }

    public function api_get_status($request) {
        global $wpdb;
        
        $imported_count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'post' 
            AND pm.meta_key = '_import_session_id' 
            AND pm.meta_value LIKE 'api_import_%'
        ");
        
        return new WP_REST_Response(array(
            'success' => true,
            'imported_posts' => (int)$imported_count,
            'wordpress_version' => get_bloginfo('version'),
            'plugin_version' => POST_IMPORTER_VERSION
        ), 200);
    }
}

// Include configuration
require_once POST_IMPORTER_PLUGIN_DIR . 'config.php';

// Include installer
require_once POST_IMPORTER_PLUGIN_DIR . 'installer.php';

// Include debug tools (only in development)
if (defined('WP_DEBUG') && WP_DEBUG) {
    require_once POST_IMPORTER_PLUGIN_DIR . 'debug.php';
}

// Initialize the plugin
new PostImporter();