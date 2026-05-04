<?php
/**
 * QR Code Tracker Permissions Integration with Members Plugin
 * 
 * This class handles all permission-related functionality for the QR Code Tracker plugin,
 * integrating with the Members plugin by MemberPress for role and capability management.
 */

class QRCodeTracker_Permissions {
    const ACCESS_REQUEST_MANAGER_ROLE = 'qr_code_access_request_manager';
    const ACCESS_REQUEST_MANAGER_CAPABILITIES = [
        'qr_tracker_view_teams',
        'qr_tracker_view_team_members',
        'qr_tracker_assign_users_to_teams',
        'qr_tracker_manage_all_teams',
    ];
    
    /**
     * Custom capabilities for QR Code Tracker
     */
    const CAPABILITIES = [
        // Core QR Code Management
        'qr_tracker_manage_qr_codes' => 'Manage QR Codes',
        'qr_tracker_view_qr_codes' => 'View QR Codes',
        'qr_tracker_create_qr_codes' => 'Create QR Codes',
        'qr_tracker_edit_qr_codes' => 'Edit QR Codes',
        'qr_tracker_delete_qr_codes' => 'Delete QR Codes',
        'qr_tracker_download_qr_codes' => 'Download QR Codes',
        
        // Team Management
        'qr_tracker_manage_teams' => 'Manage Teams',
        'qr_tracker_view_teams' => 'View Teams',
        'qr_tracker_view_team_members' => 'View Team Members',
        'qr_tracker_create_teams' => 'Create Teams',
        'qr_tracker_edit_teams' => 'Edit Teams',
        'qr_tracker_delete_teams' => 'Delete Teams',
        'qr_tracker_assign_users_to_teams' => 'Assign Users to Teams',
        'qr_tracker_remove_users_from_teams' => 'Remove Users from Teams',
        'qr_tracker_assign_qr_codes_to_teams' => 'Assign QR Codes to Teams',
        
        // Reporting and Analytics
        'qr_tracker_view_reports' => 'View Reports',
        'qr_tracker_export_data' => 'Export Data',
        'qr_tracker_view_analytics' => 'View Analytics',
        'qr_tracker_view_scan_logs' => 'View Scan Logs',
        
        // Settings and Configuration
        'qr_tracker_manage_settings' => 'Manage Settings',
        'qr_tracker_view_settings' => 'View Settings',
        
        // Advanced Features
        'qr_tracker_manage_all_teams' => 'Manage All Teams (Super Admin)',
        'qr_tracker_view_all_data' => 'View All Data (Super Admin)',
        'qr_tracker_manage_permissions' => 'Manage Permissions',
    ];
    
    /**
     * Default role capabilities mapping
     */
    const DEFAULT_ROLE_CAPABILITIES = [
        'administrator' => [
            'qr_tracker_manage_qr_codes',
            'qr_tracker_view_qr_codes',
            'qr_tracker_create_qr_codes',
            'qr_tracker_edit_qr_codes',
            'qr_tracker_delete_qr_codes',
            'qr_tracker_download_qr_codes',
            'qr_tracker_manage_teams',
            'qr_tracker_view_teams',
            'qr_tracker_view_team_members',
            'qr_tracker_create_teams',
            'qr_tracker_edit_teams',
            'qr_tracker_delete_teams',
            'qr_tracker_assign_users_to_teams',
            'qr_tracker_remove_users_from_teams',
            'qr_tracker_assign_qr_codes_to_teams',
            'qr_tracker_view_reports',
            'qr_tracker_export_data',
            'qr_tracker_view_analytics',
            'qr_tracker_view_scan_logs',
            'qr_tracker_manage_settings',
            'qr_tracker_view_settings',
            'qr_tracker_manage_all_teams',
            'qr_tracker_view_all_data',
            'qr_tracker_manage_permissions',
        ],
        'editor' => [
            'qr_tracker_view_qr_codes',
            'qr_tracker_create_qr_codes',
            'qr_tracker_edit_qr_codes',
            'qr_tracker_download_qr_codes',
            'qr_tracker_view_teams',
            'qr_tracker_view_team_members',
            'qr_tracker_view_reports',
            'qr_tracker_view_analytics',
            'qr_tracker_view_scan_logs',
        ],
        'author' => [
            'qr_tracker_view_qr_codes',
            'qr_tracker_create_qr_codes',
            'qr_tracker_edit_qr_codes',
            'qr_tracker_download_qr_codes',
            'qr_tracker_view_reports',
        ],
        'contributor' => [
            'qr_tracker_view_qr_codes',
            'qr_tracker_create_qr_codes',
        ],
        'subscriber' => [
            'qr_tracker_view_qr_codes',
        ],
    ];
    
    /**
     * Initialize the permissions system
     */
    public function __construct() {
        add_action('init', [$this, 'register_capabilities']);
        add_action('members_register_cap_groups', [$this, 'register_capability_groups']);
        add_action('members_register_caps', [$this, 'register_capabilities_with_members']);
        add_action('admin_init', [$this, 'maybe_assign_default_capabilities']);
    }
    
    /**
     * Register custom capabilities with WordPress
     */
    public function register_capabilities() {
        // Get administrator role
        $admin_role = get_role('administrator');
        
        if ($admin_role) {
            // Add all capabilities to administrator role
            foreach (array_keys(self::CAPABILITIES) as $capability) {
                $admin_role->add_cap($capability);
            }
        }

        $this->ensure_access_request_manager_role();
    }

    /**
     * Ensure the access-request manager role exists with required capabilities.
     */
    private function ensure_access_request_manager_role() {
        $role = get_role(self::ACCESS_REQUEST_MANAGER_ROLE);

        if (!$role) {
            add_role(self::ACCESS_REQUEST_MANAGER_ROLE, 'QR Code Access Request Manager', ['read' => true]);
            $role = get_role(self::ACCESS_REQUEST_MANAGER_ROLE);
        }

        if (!$role) {
            return;
        }

        $role->add_cap('read');
        foreach (self::ACCESS_REQUEST_MANAGER_CAPABILITIES as $capability) {
            $role->add_cap($capability);
        }
    }
    
    /**
     * Register capability groups with Members plugin
     */
    public function register_capability_groups() {
        if (!function_exists('members_register_cap_group')) {
            return;
        }
        
        // Register QR Code Tracker capability group
        members_register_cap_group('qr_tracker', [
            'label' => 'QR Code Tracker',
            'icon' => 'dashicons-qrcode',
            'priority' => 20,
            'caps' => array_keys(self::CAPABILITIES),
        ]);
    }
    
    /**
     * Register capabilities with Members plugin
     */
    public function register_capabilities_with_members() {
        if (!function_exists('members_register_cap')) {
            return;
        }
        
        foreach (self::CAPABILITIES as $capability => $label) {
            members_register_cap($capability, [
                'label' => $label,
                'group' => 'qr_tracker',
            ]);
        }
    }
    
    /**
     * Assign default capabilities to roles if not already assigned
     */
    public function maybe_assign_default_capabilities() {
        // Only run this once
        if (get_option('qr_tracker_capabilities_assigned')) {
            return;
        }
        
        foreach (self::DEFAULT_ROLE_CAPABILITIES as $role_name => $capabilities) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $capability) {
                    if (!isset($role->capabilities[$capability])) {
                        $role->add_cap($capability);
                    }
                }
            }
        }
        
        // Mark as completed
        update_option('qr_tracker_capabilities_assigned', true);
    }
    
    /**
     * Check if current user has a specific capability
     */
    public static function current_user_can($capability) {
        return current_user_can($capability);
    }
    
    /**
     * Check if current user can manage QR codes
     */
    public static function can_manage_qr_codes() {
        return self::current_user_can('qr_tracker_manage_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view QR codes
     */
    public static function can_view_qr_codes() {
        return self::current_user_can('qr_tracker_view_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can create QR codes
     */
    public static function can_create_qr_codes() {
        return self::current_user_can('qr_tracker_create_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can edit QR codes
     */
    public static function can_edit_qr_codes() {
        return self::current_user_can('qr_tracker_edit_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can delete QR codes
     */
    public static function can_delete_qr_codes() {
        return self::current_user_can('qr_tracker_delete_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can download QR codes
     */
    public static function can_download_qr_codes() {
        return self::current_user_can('qr_tracker_download_qr_codes') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage teams
     */
    public static function can_manage_teams() {
        return self::current_user_can('qr_tracker_manage_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view teams
     */
    public static function can_view_teams() {
        return self::current_user_can('qr_tracker_view_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view team members
     */
    public static function can_view_team_members() {
        return self::current_user_can('qr_tracker_view_team_members') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can create teams
     */
    public static function can_create_teams() {
        return self::current_user_can('qr_tracker_create_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can edit teams
     */
    public static function can_edit_teams() {
        return self::current_user_can('qr_tracker_edit_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can delete teams
     */
    public static function can_delete_teams() {
        return self::current_user_can('qr_tracker_delete_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can assign users to teams
     */
    public static function can_assign_users_to_teams() {
        return self::current_user_can('qr_tracker_assign_users_to_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can remove users from teams
     */
    public static function can_remove_users_from_teams() {
        return self::current_user_can('qr_tracker_remove_users_from_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can assign QR codes to teams
     */
    public static function can_assign_qr_codes_to_teams() {
        return self::current_user_can('qr_tracker_assign_qr_codes_to_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view reports
     */
    public static function can_view_reports() {
        return self::current_user_can('qr_tracker_view_reports') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can export data
     */
    public static function can_export_data() {
        return self::current_user_can('qr_tracker_export_data') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view analytics
     */
    public static function can_view_analytics() {
        return self::current_user_can('qr_tracker_view_analytics') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view scan logs
     */
    public static function can_view_scan_logs() {
        return self::current_user_can('qr_tracker_view_scan_logs') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage settings
     */
    public static function can_manage_settings() {
        return self::current_user_can('qr_tracker_manage_settings') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view settings
     */
    public static function can_view_settings() {
        return self::current_user_can('qr_tracker_view_settings') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage all teams (super admin)
     */
    public static function can_manage_all_teams() {
        return self::current_user_can('qr_tracker_manage_all_teams') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can view all data (super admin)
     */
    public static function can_view_all_data() {
        return self::current_user_can('qr_tracker_view_all_data') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Check if current user can manage permissions
     */
    public static function can_manage_permissions() {
        return self::current_user_can('qr_tracker_manage_permissions') || 
               self::current_user_can('manage_options');
    }
    
    /**
     * Get all capabilities for display purposes
     */
    public static function get_all_capabilities() {
        return self::CAPABILITIES;
    }
    
    /**
     * Get capabilities for a specific role
     */
    public static function get_role_capabilities($role_name) {
        $role = get_role($role_name);
        if (!$role) {
            return [];
        }
        
        $qr_capabilities = [];
        foreach (array_keys(self::CAPABILITIES) as $capability) {
            if (isset($role->capabilities[$capability])) {
                $qr_capabilities[] = $capability;
            }
        }
        
        return $qr_capabilities;
    }
    
    /**
     * Add capability to a role
     */
    public static function add_capability_to_role($role_name, $capability) {
        $role = get_role($role_name);
        if ($role && array_key_exists($capability, self::CAPABILITIES)) {
            $role->add_cap($capability);
            return true;
        }
        return false;
    }
    
    /**
     * Remove capability from a role
     */
    public static function remove_capability_from_role($role_name, $capability) {
        $role = get_role($role_name);
        if ($role && array_key_exists($capability, self::CAPABILITIES)) {
            $role->remove_cap($capability);
            return true;
        }
        return false;
    }
    
    /**
     * Check if Members plugin is active
     */
    public static function is_members_plugin_active() {
        return function_exists('members_register_cap') && 
               function_exists('members_register_cap_group');
    }
    
    /**
     * Get permission error message
     */
    public static function get_permission_error_message($capability = '') {
        $message = 'You do not have sufficient permissions to access this feature.';
        
        if ($capability && array_key_exists($capability, self::CAPABILITIES)) {
            $message = sprintf(
                'You do not have the "%s" permission required to access this feature.',
                self::CAPABILITIES[$capability]
            );
        }
        
        return $message;
    }
    
    /**
     * Display permission denied notice
     */
    public static function display_permission_denied_notice($capability = '') {
        $message = self::get_permission_error_message($capability);
        
        echo '<div class="notice notice-error">';
        echo '<p><strong>QR Code Tracker:</strong> ' . esc_html($message) . '</p>';
        echo '</div>';
    }
    
    /**
     * Die with permission error
     */
    public static function die_with_permission_error($capability = '') {
        $message = self::get_permission_error_message($capability);
        wp_die($message, 'Permission Denied', ['response' => 403]);
    }
}
