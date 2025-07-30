<?php
/**
 * Post Importer Configuration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PostImporterConfig {
    
    // Import settings
    const DEFAULT_BATCH_SIZE = 10;
    const MAX_BATCH_SIZE = 50;
    const MIN_BATCH_SIZE = 1;
    
    // File settings
    const MAX_FILE_SIZE = 100 * 1024 * 1024; // 100MB
    const ALLOWED_FILE_TYPES = array('json');
    
    // Memory settings
    const MEMORY_LIMIT = '512M';
    const TIME_LIMIT = 300; // 5 minutes
    
    // Database settings
    const PROGRESS_TABLE = 'post_import_progress';
    const FAILED_TABLE = 'post_import_failed';
    
    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_READY = 'ready';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_ERROR = 'error';
    
    // Default post settings
    const DEFAULT_POST_STATUS = 'publish';
    const DEFAULT_POST_TYPE = 'post';
    
    /**
     * Get batch size from user settings or default
     */
    public static function get_batch_size() {
        $batch_size = get_option('post_importer_batch_size', self::DEFAULT_BATCH_SIZE);
        
        // Ensure batch size is within limits
        $batch_size = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, intval($batch_size)));
        
        return $batch_size;
    }
    
    /**
     * Set memory and time limits for import
     */
    public static function set_import_limits() {
        if (function_exists('ini_set')) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
            ini_set('max_execution_time', self::TIME_LIMIT);
        }
        
        if (function_exists('set_time_limit')) {
            set_time_limit(self::TIME_LIMIT);
        }
    }
    
    /**
     * Get upload directory for plugin files
     */
    public static function get_upload_dir() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/post-importer/';
    }
    
    /**
     * Get upload URL for plugin files
     */
    public static function get_upload_url() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'] . '/post-importer/';
    }
    
    /**
     * Check if file type is allowed
     */
    public static function is_allowed_file_type($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_FILE_TYPES);
    }
    
    /**
     * Check if file size is within limits
     */
    public static function is_allowed_file_size($filesize) {
        return $filesize <= self::MAX_FILE_SIZE;
    }
    
    /**
     * Get all configuration as array
     */
    public static function get_config() {
        return array(
            'batch_size' => self::get_batch_size(),
            'max_file_size' => self::MAX_FILE_SIZE,
            'allowed_file_types' => self::ALLOWED_FILE_TYPES,
            'memory_limit' => self::MEMORY_LIMIT,
            'time_limit' => self::TIME_LIMIT,
            'upload_dir' => self::get_upload_dir(),
            'upload_url' => self::get_upload_url()
        );
    }
}
