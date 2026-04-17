<?php
// Database install/upgrade logic for QR Code Tracker
class QRCodeTracker_DB {
    private $main_table;
    private $log_table;
    private $teams_table;
    private $user_teams_table;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
        $this->teams_table = $wpdb->prefix . 'qr_tracker_teams';
        $this->user_teams_table = $wpdb->prefix . 'qr_tracker_user_teams';
    }

    public function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Main QR tracker table
        $sql_main = "CREATE TABLE {$this->main_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            legacy_url TEXT NULL,
            unique_code VARCHAR(32) NULL,
            postcode VARCHAR(32),
            city VARCHAR(64),
            tree VARCHAR(64),
            label VARCHAR(100),
            reporting_id VARCHAR(64),
            scan_count BIGINT UNSIGNED DEFAULT 0,
            last_scanned DATETIME DEFAULT NULL,
            message_1 LONGTEXT,
            message_2 LONGTEXT,
            show_popup TINYINT(1) DEFAULT 1,
            shop_link VARCHAR(255),
            shop_logo VARCHAR(255),
            show_shop_link TINYINT(1) DEFAULT 1,
            qr_source VARCHAR(32) DEFAULT 'manual',
            purchase_status VARCHAR(32) DEFAULT 'ready',
            contact_emails TEXT NULL,
            report_emails TEXT NULL,
            buyer_name VARCHAR(190) NULL,
            referral_code VARCHAR(64) NULL,
            referral_url TEXT NULL,
            pay_tree_forward_type VARCHAR(32) NULL,
            pay_tree_forward_recipient VARCHAR(190) NULL,
            woocommerce_order_id BIGINT UNSIGNED NULL,
            woocommerce_item_id BIGINT UNSIGNED NULL,
            team_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY team_id (team_id),
            KEY unique_code (unique_code),
            KEY woocommerce_order_id (woocommerce_order_id),
            KEY postcode (postcode),
            KEY city (city),
            KEY tree (tree)
        ) $charset_collate;";
        
        // Scan logs table
        $sql_log = "CREATE TABLE {$this->log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tracker_id BIGINT UNSIGNED NOT NULL,
            postcode VARCHAR(32),
            city VARCHAR(64),
            tree VARCHAR(64),
            scanned_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY tracker_id (tracker_id),
            KEY scanned_at (scanned_at),
            KEY postcode (postcode),
            KEY tree (tree),
            KEY city (city),
            KEY postcode_tree (postcode, tree),
            KEY scanned_at_postcode (scanned_at, postcode),
            KEY scanned_at_tree (scanned_at, tree)
        ) $charset_collate;";
        
        // Teams/Areas table
        $sql_teams = "CREATE TABLE {$this->teams_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            city VARCHAR(64),
            postcode VARCHAR(32),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY city (city),
            KEY postcode (postcode)
        ) $charset_collate;";
        
        // User-Team relationships table
        $sql_user_teams = "CREATE TABLE {$this->user_teams_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            team_id BIGINT UNSIGNED NOT NULL,
            role ENUM('admin', 'member') DEFAULT 'member',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_team (user_id, team_id),
            KEY user_id (user_id),
            KEY team_id (team_id)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_main);
        dbDelta($sql_log);
        dbDelta($sql_teams);
        dbDelta($sql_user_teams);
        
        // Insert default team if none exists
        $this->insert_default_team();
    }

    public function maybe_upgrade_schema() {
        global $wpdb;
        
        // Check if teams table exists
        $teams_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->teams_table}'");
        if (!$teams_table_exists) {
            $this->create_teams_tables();
        }
        
        // Check if team_id column exists in main table
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'team_id'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN team_id BIGINT UNSIGNED DEFAULT NULL AFTER show_shop_link");
            $wpdb->query("ALTER TABLE {$this->main_table} ADD INDEX team_id (team_id)");
        }
        
        // Existing upgrade logic
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'message_1'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN message_1 LONGTEXT AFTER last_scanned");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'message_2'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN message_2 LONGTEXT AFTER message_1");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'show_popup'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN show_popup TINYINT(1) DEFAULT 1 AFTER message_2");
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
        
        // Add shop link and logo fields
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'shop_link'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN shop_link VARCHAR(255) AFTER show_popup");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'shop_logo'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN shop_logo VARCHAR(255) AFTER shop_link");
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'show_shop_link'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN show_shop_link TINYINT(1) DEFAULT 1 AFTER shop_logo");
        }

        // Add unique URL support and WooCommerce purchase metadata fields
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'legacy_url'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN legacy_url TEXT NULL AFTER url");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'unique_code'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN unique_code VARCHAR(32) NULL AFTER legacy_url");
            $wpdb->query("ALTER TABLE {$this->main_table} ADD INDEX unique_code (unique_code)");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'qr_source'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN qr_source VARCHAR(32) DEFAULT 'manual' AFTER show_shop_link");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'purchase_status'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN purchase_status VARCHAR(32) DEFAULT 'ready' AFTER qr_source");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'contact_emails'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN contact_emails TEXT NULL AFTER purchase_status");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'report_emails'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN report_emails TEXT NULL AFTER contact_emails");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'buyer_name'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN buyer_name VARCHAR(190) NULL AFTER report_emails");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'referral_code'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN referral_code VARCHAR(64) NULL AFTER buyer_name");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'referral_url'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN referral_url TEXT NULL AFTER referral_code");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'pay_tree_forward_type'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN pay_tree_forward_type VARCHAR(32) NULL AFTER referral_url");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'pay_tree_forward_recipient'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN pay_tree_forward_recipient VARCHAR(190) NULL AFTER pay_tree_forward_type");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'woocommerce_order_id'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN woocommerce_order_id BIGINT UNSIGNED NULL AFTER pay_tree_forward_recipient");
            $wpdb->query("ALTER TABLE {$this->main_table} ADD INDEX woocommerce_order_id (woocommerce_order_id)");
        }

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'woocommerce_item_id'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN woocommerce_item_id BIGINT UNSIGNED NULL AFTER woocommerce_order_id");
        }

        $this->migrate_existing_qr_urls_to_unique_codes();
        
        // Add performance indexes for existing installations
        $this->add_performance_indexes();
    }

    private function migrate_existing_qr_urls_to_unique_codes() {
        global $wpdb;

        $rows = $wpdb->get_results("SELECT id, url, legacy_url, unique_code FROM {$this->main_table} WHERE unique_code IS NULL OR unique_code = ''");
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $unique_code = QRCodeTracker_Utils::generate_unique_code($this->main_table, 'unique_code', 6);
            $new_url = home_url('/' . $unique_code);

            // Preserve any existing legacy URL value and only fallback to current URL when missing.
            $legacy_url = !empty($row->legacy_url) ? $row->legacy_url : $row->url;

            $wpdb->update(
                $this->main_table,
                [
                    'legacy_url' => $legacy_url,
                    'unique_code' => $unique_code,
                    'url' => $new_url,
                    'qr_source' => 'manual'
                ],
                ['id' => $row->id]
            );
        }
    }
    
    private function create_teams_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Teams/Areas table
        $sql_teams = "CREATE TABLE {$this->teams_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            city VARCHAR(64),
            postcode VARCHAR(32),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY city (city),
            KEY postcode (postcode)
        ) $charset_collate;";
        
        // User-Team relationships table
        $sql_user_teams = "CREATE TABLE {$this->user_teams_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            team_id BIGINT UNSIGNED NOT NULL,
            role ENUM('admin', 'member') DEFAULT 'member',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_team (user_id, team_id),
            KEY user_id (user_id),
            KEY team_id (team_id)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_teams);
        dbDelta($sql_user_teams);
        
        // Insert default team if none exists
        $this->insert_default_team();
    }
    
    private function insert_default_team() {
        global $wpdb;
        
        // Check if any teams exist
        $team_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->teams_table}");
        
        if ($team_count == 0) {
            // Insert default team
            $wpdb->insert($this->teams_table, [
                'name' => 'Default Team',
                'description' => 'Default team for existing QR codes',
                'city' => 'Default',
                'postcode' => 'DEFAULT'
            ]);
            
            $default_team_id = $wpdb->insert_id;
            
            // Assign all existing QR codes to default team
            $wpdb->query("UPDATE {$this->main_table} SET team_id = {$default_team_id} WHERE team_id IS NULL");
            
            // Assign current user to default team as admin
            $current_user_id = get_current_user_id();
            if ($current_user_id) {
                $wpdb->insert($this->user_teams_table, [
                    'user_id' => $current_user_id,
                    'team_id' => $default_team_id,
                    'role' => 'admin'
                ]);
            }
        }
    }
    
    private function add_performance_indexes() {
        global $wpdb;
        
        // Check and add indexes for log table
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$this->log_table}");
        $existing_indexes = array_column($indexes, 'Key_name');
        
        $required_indexes = [
            'scanned_at' => 'scanned_at',
            'postcode' => 'postcode', 
            'tree' => 'tree',
            'city' => 'city',
            'postcode_tree' => 'postcode, tree',
            'scanned_at_postcode' => 'scanned_at, postcode',
            'scanned_at_tree' => 'scanned_at, tree'
        ];
        
        foreach ($required_indexes as $index_name => $columns) {
            if (!in_array($index_name, $existing_indexes)) {
                $wpdb->query("ALTER TABLE {$this->log_table} ADD INDEX {$index_name} ({$columns})");
            }
        }
    }

    public static function uninstall() {
        $delete_on_uninstall = get_option('qr_tracker_delete_on_uninstall', 0);

        if ($delete_on_uninstall) {
            global $wpdb;
            $main_table = $wpdb->prefix . 'qr_tracker';
            $log_table = $wpdb->prefix . 'qr_tracker_logs';
            $teams_table = $wpdb->prefix . 'qr_tracker_teams';
            $user_teams_table = $wpdb->prefix . 'qr_tracker_user_teams';

            $wpdb->query("DROP TABLE IF EXISTS {$main_table}");
            $wpdb->query("DROP TABLE IF EXISTS {$log_table}");
            $wpdb->query("DROP TABLE IF EXISTS {$teams_table}");
            $wpdb->query("DROP TABLE IF EXISTS {$user_teams_table}");

            delete_option('qr_tracker_delete_on_uninstall');
        }
    }
} 
