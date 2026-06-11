<?php
// Team management and permissions for QR Code Tracker
class QRCodeTracker_Teams {
    private $teams_table;
    private $user_teams_table;
    private $main_table;

    public function __construct() {
        global $wpdb;
        $this->teams_table = $wpdb->prefix . 'qr_tracker_teams';
        $this->user_teams_table = $wpdb->prefix . 'qr_tracker_user_teams';
        $this->main_table = $wpdb->prefix . 'qr_tracker';
    }

    /**
     * Get all teams (including private ones — for admin use)
     */
    public function get_all_teams() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->teams_table} ORDER BY name");
    }

    /**
     * Get only public (non-private) teams — used for the purchaser-type dropdown
     */
    public function get_public_teams() {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->teams_table} WHERE is_private = 0 ORDER BY name");
    }

    /**
     * Get team by ID
     */
    public function get_team($team_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->teams_table} WHERE id = %d", $team_id));
    }

    /**
     * Get teams for a specific user
     */
    public function get_user_teams($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, ut.role FROM {$this->teams_table} t 
             JOIN {$this->user_teams_table} ut ON t.id = ut.team_id 
             WHERE ut.user_id = %d 
             ORDER BY t.name",
            $user_id
        ));
    }

    /**
     * Get team members
     */
    public function get_team_members($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.display_name, u.user_email, ut.role, ut.created_at 
             FROM {$this->user_teams_table} ut 
             JOIN {$wpdb->users} u ON ut.user_id = u.ID 
             WHERE ut.team_id = %d 
             ORDER BY ut.role DESC, u.display_name",
            $team_id
        ));
    }

    /**
     * Check if user can access a specific team
     */
    public function user_can_access_team($user_id, $team_id) {
        global $wpdb;
        
        // Super admins can access all teams
        if (current_user_can('manage_network') || QRCodeTracker_Permissions::can_manage_all_teams()) {
            return true;
        }
        
        // Check if user is a member of the team
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id, $team_id
        ));
        
        return !empty($member);
    }

    /**
     * Check if user can manage a specific team
     */
    public function user_can_manage_team($user_id, $team_id) {
        global $wpdb;
        
        // Super admins can manage all teams
        if (current_user_can('manage_network') || QRCodeTracker_Permissions::can_manage_all_teams()) {
            return true;
        }
        
        // Check if user is an admin of the team
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d AND role = 'admin'",
            $user_id, $team_id
        ));
        
        return !empty($member);
    }

    /**
     * Get accessible teams for current user
     */
    public function get_accessible_teams() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return [];
        }
        
        // Super admins can access all teams
        if (current_user_can('manage_network') || QRCodeTracker_Permissions::can_manage_all_teams()) {
            return $this->get_all_teams();
        }
        
        return $this->get_user_teams($user_id);
    }

    /**
     * Get QR codes for a specific team
     */
    public function get_team_qr_codes($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->main_table} WHERE team_id = %d ORDER BY postcode, label",
            $team_id
        ));
    }

    /**
     * Get QR codes accessible to current user
     */
    public function get_accessible_qr_codes() {
        global $wpdb;
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return [];
        }
        
        // Super admins can access all QR codes
        if (current_user_can('manage_network') || QRCodeTracker_Permissions::can_view_all_data()) {
            return $wpdb->get_results("SELECT * FROM {$this->main_table} ORDER BY postcode, label");
        }
        
        // Get QR codes from user's teams
        $teams = $this->get_user_teams($user_id);
        if (empty($teams)) {
            return [];
        }
        
        $team_ids = array_column($teams, 'id');
        $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->main_table} WHERE team_id IN ($placeholders) ORDER BY postcode, label",
            $team_ids
        ));
    }

    /**
     * Create a new team
     *
     * @param string $name        Team name.
     * @param string $description Optional description.
     * @param string $city        Optional city.
     * @param int    $is_private  1 = private (hidden from purchaser dropdown), 0 = public.
     * @return int|false New team ID or false on failure.
     */
    public function create_team($name, $description = '', $city = '', $is_private = 0) {
        global $wpdb;

        $result = $wpdb->insert($this->teams_table, [
            'name'       => sanitize_text_field($name),
            'description'=> sanitize_textarea_field($description),
            'city'       => sanitize_text_field($city),
            'is_private' => (int) (bool) $is_private,
        ]);
        
        if ($result) {
            $team_id = $wpdb->insert_id;
            
            // Add current user as admin of the new team
            $user_id = get_current_user_id();
            if ($user_id) {
                $wpdb->insert($this->user_teams_table, [
                    'user_id' => $user_id,
                    'team_id' => $team_id,
                    'role' => 'admin'
                ]);
            }
            
            return $team_id;
        }
        
        return false;
    }

    /**
     * Update team
     *
     * @param int    $team_id     Team ID.
     * @param string $name        Team name.
     * @param string $description Optional description.
     * @param string $city        Optional city.
     * @param int    $is_private  1 = private (hidden from purchaser dropdown), 0 = public.
     * @return int|false Number of rows updated or false on failure.
     */
    public function update_team($team_id, $name, $description = '', $city = '', $is_private = 0) {
        global $wpdb;

        return $wpdb->update($this->teams_table, [
            'name'       => sanitize_text_field($name),
            'description'=> sanitize_textarea_field($description),
            'city'       => sanitize_text_field($city),
            'is_private' => (int) (bool) $is_private,
        ], ['id' => $team_id]);
    }

    /**
     * Find a public team by exact name (case-insensitive).
     *
     * @param string $name Team name to look up.
     * @return object|null Team row or null if not found.
     */
    public function get_team_by_name($name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->teams_table} WHERE name = %s LIMIT 1",
            sanitize_text_field($name)
        ));
    }

    /**
     * Delete team
     */
    public function delete_team($team_id) {
        global $wpdb;
        
        // First, reassign all QR codes to default team
        $default_team = $wpdb->get_row("SELECT id FROM {$this->teams_table} WHERE name = 'Default Team' LIMIT 1");
        if ($default_team) {
            $wpdb->update($this->main_table, ['team_id' => $default_team->id], ['team_id' => $team_id]);
        }
        
        // Remove all user-team relationships
        $wpdb->delete($this->user_teams_table, ['team_id' => $team_id]);
        
        // Delete the team
        return $wpdb->delete($this->teams_table, ['id' => $team_id]);
    }

    /**
     * Add user to team
     */
    public function add_user_to_team($user_id, $team_id, $role = 'member') {
        global $wpdb;
        
        // Check if user is already in the team
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id, $team_id
        ));
        
        if ($existing) {
            // Update existing role
            return $wpdb->update($this->user_teams_table, 
                ['role' => $role], 
                ['user_id' => $user_id, 'team_id' => $team_id]
            );
        } else {
            // Add new user
            return $wpdb->insert($this->user_teams_table, [
                'user_id' => $user_id,
                'team_id' => $team_id,
                'role' => $role
            ]);
        }
    }

    /**
     * Remove user from team
     */
    public function remove_user_from_team($user_id, $team_id) {
        global $wpdb;
        
        return $wpdb->delete($this->user_teams_table, [
            'user_id' => $user_id,
            'team_id' => $team_id
        ]);
    }

    /**
     * Allow a user to leave a team (self-removal)
     * @param int $user_id User ID leaving the team
     * @param int $team_id Team ID to leave
     * @return array Result with success status and message
     */
    public function leave_team($user_id, $team_id) {
        global $wpdb;
        
        // Check if user is actually a member of the team
        $membership = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id, $team_id
        ));
        
        if (!$membership) {
            return ['success' => false, 'message' => 'You are not a member of this team.'];
        }
        
        // Check if user is the only admin
        $admin_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->user_teams_table} WHERE team_id = %d AND role = 'admin'",
            $team_id
        ));
        
        if ($membership->role === 'admin' && $admin_count <= 1) {
            return ['success' => false, 'message' => 'You are the only admin of this team. Please assign another member as admin before leaving.'];
        }
        
        // User can leave the team
        $result = $wpdb->delete($this->user_teams_table, [
            'user_id' => $user_id,
            'team_id' => $team_id
        ]);
        
        if ($result !== false) {
            return ['success' => true, 'message' => 'Successfully left the team.'];
        } else {
            return ['success' => false, 'message' => 'Failed to leave the team. Please try again.'];
        }
    }

    /**
     * Transfer admin role to another team member
     * @param int $current_admin_id Current admin user ID
     * @param int $new_admin_id New admin user ID
     * @param int $team_id Team ID
     * @return array Result with success status and message
     */
    public function transfer_admin_role($current_admin_id, $new_admin_id, $team_id) {
        global $wpdb;
        
        // Verify current user is actually an admin of the team
        $current_admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d AND role = 'admin'",
            $current_admin_id, $team_id
        ));
        
        if (!$current_admin) {
            return ['success' => false, 'message' => 'You are not an admin of this team.'];
        }
        
        // Verify new admin is a member of the team
        $new_admin = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_teams_table} WHERE user_id = %d AND team_id = %d",
            $new_admin_id, $team_id
        ));
        
        if (!$new_admin) {
            return ['success' => false, 'message' => 'The selected user is not a member of this team.'];
        }
        
        // Check if this would leave the team without any admins
        $admin_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->user_teams_table} WHERE team_id = %d AND role = 'admin'",
            $team_id
        ));
        
        if ($admin_count <= 1) {
            return ['success' => false, 'message' => 'Cannot transfer admin role. The team must have at least one admin.'];
        }
        
        // Transfer admin role
        $wpdb->update(
            $this->user_teams_table,
            ['role' => 'admin'],
            ['user_id' => $new_admin_id, 'team_id' => $team_id]
        );
        
        // Remove admin role from current user
        $wpdb->update(
            $this->user_teams_table,
            ['role' => 'member'],
            ['user_id' => $current_admin_id, 'team_id' => $team_id]
        );
        
        return ['success' => true, 'message' => 'Admin role transferred successfully.'];
    }

    /**
     * Get team statistics
     */
    public function get_team_stats($team_id) {
        global $wpdb;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_qr_codes,
                SUM(scan_count) as total_scans,
                COUNT(CASE WHEN scan_count > 0 THEN 1 END) as active_qr_codes
             FROM {$this->main_table} 
             WHERE team_id = %d",
            $team_id
        ));
        
        return $stats;
    }

    /**
     * Get all team statistics
     */
    public function get_all_teams_stats() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT 
                t.id,
                t.name,
                t.city,
                COALESCE(qr_stats.total_qr_codes, 0) as total_qr_codes,
                COALESCE(qr_stats.total_scans, 0) as total_scans,
                COALESCE(qr_stats.active_qr_codes, 0) as active_qr_codes,
                COALESCE(member_stats.member_count, 0) as member_count
             FROM {$this->teams_table} t
             LEFT JOIN (
                 SELECT 
                     team_id,
                     COUNT(*) as total_qr_codes,
                     SUM(scan_count) as total_scans,
                     COUNT(CASE WHEN scan_count > 0 THEN 1 END) as active_qr_codes
                 FROM {$this->main_table}
                 GROUP BY team_id
             ) qr_stats ON t.id = qr_stats.team_id
             LEFT JOIN (
                 SELECT 
                     team_id,
                     COUNT(*) as member_count
                 FROM {$this->user_teams_table}
                 GROUP BY team_id
             ) member_stats ON t.id = member_stats.team_id
             ORDER BY t.name"
        );
    }

    /**
     * Check if user has any teams
     */
    public function user_has_teams($user_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->user_teams_table} WHERE user_id = %d",
            $user_id
        ));
        
        return $count > 0;
    }

    /**
     * Get users not in a specific team
     */
    public function get_users_not_in_team($team_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.display_name, u.user_email 
             FROM {$wpdb->users} u 
             WHERE u.ID NOT IN (
                 SELECT user_id FROM {$this->user_teams_table} WHERE team_id = %d
             )
             ORDER BY u.display_name",
            $team_id
        ));
    }

    /**
     * Get orphaned QR codes (those without a team assigned)
     */
    public function get_orphaned_qr_codes() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT 
                id, postcode, city, tree, label, reporting_id, scan_count, last_scanned
             FROM {$this->main_table} 
             WHERE team_id IS NULL OR team_id = 0
             ORDER BY postcode, city, tree"
        );
    }

    /**
     * Get count of orphaned QR codes
     */
    public function get_orphaned_qr_codes_count() {
        global $wpdb;
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->main_table} WHERE team_id IS NULL OR team_id = 0"
        );
    }

    /**
     * Reassign orphaned QR codes to a team
     */
    public function reassign_orphaned_qr_codes($team_id, $qr_code_ids = []) {
        global $wpdb;
        
        if (empty($qr_code_ids)) {
            return false;
        }
        
        $placeholders = implode(',', array_fill(0, count($qr_code_ids), '%d'));
        $params = array_merge([$team_id], $qr_code_ids);
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->main_table} 
             SET team_id = %d 
             WHERE id IN ($placeholders)",
            $params
        ));
        
        return $result !== false;
    }
}
