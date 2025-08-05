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
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_upload_json_file', array($this, 'handle_file_upload'));
        add_action('wp_ajax_import_posts_batch', array($this, 'import_posts_batch'));
        add_action('wp_ajax_get_import_status', array($this, 'get_import_status'));
        add_action('wp_ajax_reset_import', array($this, 'reset_import'));
        
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
        
        wp_enqueue_script('post-importer-js', POST_IMPORTER_PLUGIN_URL . 'assets/post-importer.js', array('jquery'), POST_IMPORTER_VERSION, true);
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
                    <h2 class="hndle">Upload JSON File</h2>
                    <div class="inside">
                        <form id="upload-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Select JSON File</th>
                                    <td>
                                        <input type="file" id="json-file" name="json_file" accept=".json" required>
                                        <p class="description">Select your posts.json file to import</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">OR File Path</th>
                                    <td>
                                        <input type="text" id="file-path" name="file_path" class="regular-text" placeholder="Enter full path to JSON file">
                                        <p class="description">Enter the full server path to your JSON file (alternative to upload)</p>
                                    </td>
                                </tr>
                            </table>
                            <p class="submit">
                                <input type="submit" class="button-primary" value="Upload & Analyze File">
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
                            <button id="start-import" class="button-primary">Start Import</button>
                            <button id="pause-import" class="button" style="display: none;">Pause Import</button>
                            <button id="resume-import" class="button" style="display: none;">Resume Import</button>
                            <button id="reset-import" class="button button-secondary">Reset Import</button>
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
        
        // Handle file upload
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
        // Handle file path input
        elseif (!empty($_POST['file_path'])) {
            $file_path = sanitize_text_field($_POST['file_path']);
            
            if (!file_exists($file_path)) {
                wp_send_json_error('File does not exist at the specified path');
                return;
            }
        } else {
            wp_send_json_error('No file uploaded or path specified');
            return;
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
            'file_path' => $file_path
        ));
    }
    
    private function analyze_json_file($file_path) {
        $json_content = file_get_contents($file_path);
        
        if ($json_content === false) {
            return false;
        }
        
        $posts_data = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }
        
        // Validate structure
        if (!is_array($posts_data) || empty($posts_data)) {
            return false;
        }
        
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
            
            // Prepare post data
            $wp_post_data = array(
                'post_title' => sanitize_text_field($post_data['title']),
                'post_content' => wp_kses_post($post_data['content']), // Allow HTML but sanitize
                'post_excerpt' => sanitize_text_field(!empty($post_data['short_description']) ? $post_data['short_description'] : $post_data['summary']),
                'post_name' => sanitize_title($post_data['slug']),
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date' => $this->parse_date($post_data['formatted_first_published_at_datetime']),
                'post_modified' => $this->parse_date($post_data['formatted_last_published_at_datetime'])
            );
            
            // Insert post
            $post_id = wp_insert_post($wp_post_data);
            
            if (is_wp_error($post_id)) {
                throw new Exception($post_id->get_error_message());
            }
            
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
    
    private function parse_date($date_string) {
        $date = DateTime::createFromFormat('Y-m-d\TH:i:sP', $date_string);
        if ($date === false) {
            return current_time('mysql');
        }
        return $date->format('Y-m-d H:i:s');
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
        
        $result = wp_insert_category(array(
            'cat_name' => $name,
            'category_nicename' => $slug
        ));
        
        return is_wp_error($result) ? false : $result;
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
    
    private function handle_featured_image($post_id, $image_url, $post_title) {
        // Validate URL
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check if image already exists in media library by URL
        global $wpdb;
        $existing_attachment = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s",
            $image_url
        ));
        
        if ($existing_attachment) {
            // Use existing image
            set_post_thumbnail($post_id, $existing_attachment);
            return $existing_attachment;
        }
        
        // Check by filename to avoid duplicate downloads
        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (!empty($filename)) {
            $existing_by_filename = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = %s OR post_name = %s",
                pathinfo($filename, PATHINFO_FILENAME),
                sanitize_title(pathinfo($filename, PATHINFO_FILENAME))
            ));
            
            if ($existing_by_filename) {
                // Use existing image with same filename
                set_post_thumbnail($post_id, $existing_by_filename);
                return $existing_by_filename;
            }
        }
        
        // Download and set new featured image
        $image_id = $this->download_image($image_url, $post_title);
        
        if ($image_id && !is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
            
            // Update the attachment post to have better title and alt text
            wp_update_post(array(
                'ID' => $image_id,
                'post_title' => $post_title,
                'post_excerpt' => $post_title, // Caption
            ));
            
            // Set alt text
            update_post_meta($image_id, '_wp_attachment_image_alt', $post_title);
            
            return $image_id;
        }
        
        return false;
    }
    
    private function download_image($image_url, $description = '') {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check if URL is accessible
        $response = wp_remote_head($image_url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return false;
        }
        
        // Get the image
        $image_id = media_sideload_image($image_url, 0, $description, 'id');
        
        if (is_wp_error($image_id)) {
            // Log the error for debugging
            error_log('Post Importer: Failed to download image ' . $image_url . ': ' . $image_id->get_error_message());
            return false;
        }
        
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
