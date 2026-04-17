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
            short_code VARCHAR(16) DEFAULT NULL,
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
            team_id BIGINT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            KEY team_id (team_id),
            KEY short_code (short_code),
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
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->main_table} LIKE 'short_code'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$this->main_table} ADD COLUMN short_code VARCHAR(16) DEFAULT NULL AFTER url");
            $wpdb->query("ALTER TABLE {$this->main_table} ADD INDEX short_code (short_code)");
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
        
        // Add performance indexes for existing installations
        $this->add_performance_indexes();
        $this->populate_missing_short_codes();
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

    private function generate_unique_short_code($length = 6) {
        global $wpdb;
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $max_index = strlen($characters) - 1;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[random_int(0, $max_index)];
            }

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
                $code
            ));
            if (!$exists) {
                return $code;
            }
        }

        do {
            $fallback = strtolower(wp_generate_password($length + 2, false, false));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->main_table} WHERE short_code = %s LIMIT 1",
                $fallback
            ));
        } while ($exists);

        return $fallback;
    }

    private function populate_missing_short_codes() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id FROM {$this->main_table} WHERE short_code IS NULL OR short_code = ''");
        if (empty($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $wpdb->update(
                $this->main_table,
                ['short_code' => $this->generate_unique_short_code()],
                ['id' => $row->id]
            );
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
            delete_option('qr_tracker_tree_product_ids');
        }
    }
} 
