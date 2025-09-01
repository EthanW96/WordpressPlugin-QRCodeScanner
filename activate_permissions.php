<?php
/**
 * Activation script for QR Code Tracker Permissions System
 * 
 * This script helps upgrade existing installations to include the new team permissions system.
 * Run this script once after updating the plugin to ensure all database tables are properly created.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../wp-load.php');
}

// Check if user has admin privileges
if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this page.');
}

echo '<h1>QR Code Tracker Permissions System Activation</h1>';

try {
    global $wpdb;
    
    // Check if teams table exists
    $teams_table = $wpdb->prefix . 'qr_tracker_teams';
    $user_teams_table = $wpdb->prefix . 'qr_tracker_user_teams';
    $main_table = $wpdb->prefix . 'qr_tracker';
    
    $teams_exists = $wpdb->get_var("SHOW TABLES LIKE '{$teams_table}'");
    $user_teams_exists = $wpdb->get_var("SHOW TABLES LIKE '{$user_teams_table}'");
    
    if (!$teams_exists) {
        echo '<p>Creating teams table...</p>';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_teams = "CREATE TABLE {$teams_table} (
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
        
        $result = $wpdb->query($sql_teams);
        
        if ($result === false) {
            throw new Exception('Failed to create teams table: ' . $wpdb->last_error);
        }
        
        echo '<p>✓ Teams table created successfully.</p>';
    } else {
        echo '<p>✓ Teams table already exists.</p>';
    }
    
    if (!$user_teams_exists) {
        echo '<p>Creating user-teams table...</p>';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql_user_teams = "CREATE TABLE {$user_teams_table} (
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
        
        $result = $wpdb->query($sql_user_teams);
        
        if ($result === false) {
            throw new Exception('Failed to create user-teams table: ' . $wpdb->last_error);
        }
        
        echo '<p>✓ User-teams table created successfully.</p>';
    } else {
        echo '<p>✓ User-teams table already exists.</p>';
    }
    
    // Check if team_id column exists in main table
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$main_table} LIKE 'team_id'");
    if (empty($columns)) {
        echo '<p>Adding team_id column to main table...</p>';
        
        $result = $wpdb->query("ALTER TABLE {$main_table} ADD COLUMN team_id BIGINT UNSIGNED DEFAULT NULL AFTER show_shop_link");
        if ($result === false) {
            throw new Exception('Failed to add team_id column: ' . $wpdb->last_error);
        }
        
        $result = $wpdb->query("ALTER TABLE {$main_table} ADD INDEX team_id (team_id)");
        if ($result === false) {
            throw new Exception('Failed to add team_id index: ' . $wpdb->last_error);
        }
        
        echo '<p>✓ Team ID column added successfully.</p>';
    } else {
        echo '<p>✓ Team ID column already exists.</p>';
    }
    
    // Create default team if none exists
    $team_count = $wpdb->get_var("SELECT COUNT(*) FROM {$teams_table}");
    
    if ($team_count == 0) {
        echo '<p>Creating default team...</p>';
        
        $result = $wpdb->insert($teams_table, [
            'name' => 'Default Team',
            'description' => 'Default team for existing QR codes',
            'city' => 'Default',
            'postcode' => 'DEFAULT'
        ]);
        
        if ($result === false) {
            throw new Exception('Failed to create default team: ' . $wpdb->last_error);
        }
        
        $default_team_id = $wpdb->insert_id;
        
        // Assign all existing QR codes to default team
        $result = $wpdb->query("UPDATE {$main_table} SET team_id = {$default_team_id} WHERE team_id IS NULL");
        if ($result === false) {
            throw new Exception('Failed to assign QR codes to default team: ' . $wpdb->last_error);
        }
        
        // Assign current user to default team as admin
        $current_user_id = get_current_user_id();
        if ($current_user_id) {
            $result = $wpdb->insert($user_teams_table, [
                'user_id' => $current_user_id,
                'team_id' => $default_team_id,
                'role' => 'admin'
            ]);
            
            if ($result === false) {
                echo '<p>⚠ Warning: Failed to assign current user to default team: ' . $wpdb->last_error . '</p>';
            } else {
                echo '<p>✓ Current user assigned to default team as admin.</p>';
            }
        }
        
        echo '<p>✓ Default team created and QR codes assigned.</p>';
    } else {
        echo '<p>✓ Teams already exist.</p>';
    }
    
    echo '<h2>✅ Activation Complete!</h2>';
    echo '<p>The permissions system has been successfully activated. You can now:</p>';
    echo '<ul>';
    echo '<li>Access the Teams management page from QR Tracker > Teams</li>';
    echo '<li>Create new teams and assign users</li>';
    echo '<li>Assign QR codes to specific teams</li>';
    echo '<li>Manage team permissions and access control</li>';
    echo '</ul>';
    echo '<p><strong>Note:</strong> External website visitors will continue to see all QR codes without restrictions.</p>';
    
} catch (Exception $e) {
    echo '<h2>❌ Activation Failed</h2>';
    echo '<p>Error: ' . esc_html($e->getMessage()) . '</p>';
    echo '<p>Please check your database permissions and try again.</p>';
}
