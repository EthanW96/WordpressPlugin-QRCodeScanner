<?php
// Database install/upgrade logic for QR Code Tracker
class QRCodeTracker_DB {
    private $main_table;
    private $log_table;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
    }

    public function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $sql_main = "CREATE TABLE {$this->main_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            postcode VARCHAR(32),
            city VARCHAR(64),
            tree VARCHAR(64),
            label VARCHAR(100),
            reporting_id VARCHAR(64),
            scan_count BIGINT UNSIGNED DEFAULT 0,
            last_scanned DATETIME DEFAULT NULL,
            message_1 LONGTEXT,
            message_2 LONGTEXT,
            PRIMARY KEY (id)
        ) $charset_collate;";
        $sql_log = "CREATE TABLE {$this->log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tracker_id BIGINT UNSIGNED NOT NULL,
            postcode VARCHAR(32),
            city VARCHAR(64),
            tree VARCHAR(64),
            scanned_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tracker_id (tracker_id)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_main);
        dbDelta($sql_log);
    }

    public function maybe_upgrade_schema() {
        global $wpdb;
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'message_1'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN message_1 LONGTEXT AFTER last_scanned");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'message_2'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN message_2 LONGTEXT AFTER message_1");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'tree'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN tree VARCHAR(64) AFTER postcode");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'reporting_id'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN reporting_id VARCHAR(64) AFTER label");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->log_table} LIKE 'postcode'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->log_table} ADD COLUMN postcode VARCHAR(32) AFTER tracker_id");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->log_table} LIKE 'tree'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->log_table} ADD COLUMN tree VARCHAR(64) AFTER postcode");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'city'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN city VARCHAR(64) AFTER postcode");
        }
    }

    public static function uninstall() {
        $delete_on_uninstall = get_option('qr_tracker_delete_on_uninstall', 0);

        if ($delete_on_uninstall) {
            global $wpdb;
            $main_table = $wpdb->prefix . 'qr_tracker';
            $log_table = $wpdb->prefix . 'qr_tracker_logs';

            $wpdb->query("DROP TABLE IF EXISTS {$main_table}");
            $wpdb->query("DROP TABLE IF EXISTS {$log_table}");

            delete_option('qr_tracker_delete_on_uninstall');
        }
    }
} 