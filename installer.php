<?php
/**
 * Plugin Installation and Setup Script
 * Run this file once to set up the plugin properly
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PostImporterInstaller {
    
    public function __construct() {
        add_action('init', array($this, 'check_installation'));
    }
    
    public function check_installation() {
        if (get_option('post_importer_installed') !== '1.0.0') {
            $this->install();
        }
    }
    
    public function install() {
        // Create database tables
        $this->create_tables();
        
        // Create upload directory
        $this->create_upload_directory();
        
        // Set installation flag
        update_option('post_importer_installed', '1.0.0');
        
        // Add admin notice
        add_action('admin_notices', array($this, 'installation_notice'));
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Progress tracking table
        $progress_table = $wpdb->prefix . 'post_import_progress';
        $sql1 = "CREATE TABLE IF NOT EXISTS $progress_table (
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
        
        // Failed posts table
        $failed_table = $wpdb->prefix . 'post_import_failed';
        $sql2 = "CREATE TABLE IF NOT EXISTS $failed_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            post_data longtext NOT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }
    
    private function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $importer_dir = $upload_dir['basedir'] . '/post-importer/';
        
        if (!file_exists($importer_dir)) {
            wp_mkdir_p($importer_dir);
            
            // Create .htaccess file for security
            $htaccess_content = "Options -Indexes\nDeny from all\n<Files ~ \"\\.(json)$\">\nOrder allow,deny\nAllow from all\n</Files>";
            file_put_contents($importer_dir . '.htaccess', $htaccess_content);
        }
    }
    
    public function installation_notice() {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>Post Importer:</strong> Plugin installed successfully! You can now import posts from <a href="' . admin_url('tools.php?page=post-importer') . '">Tools > Post Importer</a>.</p>';
        echo '</div>';
    }
    
    /**
     * Uninstall function - cleans up plugin data
     */
    public static function uninstall() {
        global $wpdb;
        
        // Drop tables
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}post_import_progress");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}post_import_failed");
        
        // Remove options
        delete_option('post_importer_installed');
        
        // Remove upload directory (optional - commented out for safety)
        // $upload_dir = wp_upload_dir();
        // $importer_dir = $upload_dir['basedir'] . '/post-importer/';
        // if (file_exists($importer_dir)) {
        //     $files = glob($importer_dir . '*');
        //     foreach($files as $file) {
        //         if(is_file($file)) unlink($file);
        //     }
        //     rmdir($importer_dir);
        // }
    }
}

// Hook uninstall function
register_uninstall_hook(__FILE__, array('PostImporterInstaller', 'uninstall'));

// Initialize installer
new PostImporterInstaller();
