<?php
// Admin UI logic for QR Code Tracker
class QRCodeTracker_Admin {
    private $main_table;
    private $log_table;
    private $tracker;
    private $teams;

    public function __construct($tracker, $teams) {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
        $this->tracker = $tracker;
        $this->teams = $teams;
        
        // Initialize search functionality
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-search.php';
        $this->search = new QRCodeTracker_Search();
    }

    public function admin_menu() {
        // Main menu - requires view QR codes permission
        if (QRCodeTracker_Permissions::can_view_qr_codes()) {
            add_menu_page('QR Tracker', 'QR Tracker', 'qr_tracker_view_qr_codes', 'qr-tracker', [$this, 'admin_page'], 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h6v6H3V3zm2 2v2h2V5H5zm8-2h6v6h-6V3zm2 2v2h2V5h-2zM3 11h6v6H3v-6zm2 2v2h2v-2H5zm8 0h6v6h-6v-6zm2 2v2h2v-2h-2z"/></svg>'));
        }
        
        // Scan Logs submenu
        if (QRCodeTracker_Permissions::can_view_scan_logs()) {
            add_submenu_page('qr-tracker', 'Scan Logs', 'Scan Logs', 'qr_tracker_view_scan_logs', 'qr-scan-logs', [$this, 'scan_logs_page']);
        }
        
        // Reports submenu
        if (QRCodeTracker_Permissions::can_view_reports()) {
            add_submenu_page('qr-tracker', 'Reports', 'Reports', 'qr_tracker_view_reports', 'qr-reports', [$this, 'reports_page']);
        }
        
        // Teams submenu
        if (QRCodeTracker_Permissions::can_view_teams()) {
            add_submenu_page('qr-tracker', 'Teams', 'Teams', 'qr_tracker_view_teams', 'qr-teams', [$this, 'teams_page']);
        }
        
        // Hidden pages - accessible but not shown in menu
        if (QRCodeTracker_Permissions::can_view_reports()) {
            add_submenu_page('qr-tracker', 'Single QR Report', 'Single QR Report', 'qr_tracker_view_reports', 'qr-single-report', [$this, 'single_report_page']);
            add_submenu_page('qr-tracker', 'City Report', 'City Report', 'qr_tracker_view_reports', 'qr-city-report', [$this, 'city_report_page']);
            add_submenu_page('qr-tracker', 'Reporting ID Report', 'Reporting ID Report', 'qr_tracker_view_reports', 'qr-reporting-id-report', [$this, 'reporting_id_report_page']);
        }
        
        // Settings submenu
        if (QRCodeTracker_Permissions::can_view_settings()) {
            $orphaned_count = $this->teams->get_orphaned_qr_codes_count();
            $settings_title = $orphaned_count > 0 ? "Settings <span class='update-plugins count-{$orphaned_count}'><span class='plugin-count'>{$orphaned_count}</span></span>" : 'Settings';
            add_submenu_page('qr-tracker', 'Settings', $settings_title, 'qr_tracker_view_settings', 'qr-settings', [$this, 'settings_page']);
        }
        
        // Hide the hidden pages from the menu
        add_action('admin_head', [$this, 'hide_hidden_menu_items']);
        add_action('admin_head', [$this, 'add_admin_styles']);
    }

    public function admin_page() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_view_qr_codes()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_view_qr_codes');
            return;
        }
        
        global $wpdb;
        echo '<div class="wrap"><h1>QR Code Tracker</h1>';

        // Handle form submissions first
        if (isset($_POST['qr_submit'])) {
            // Check create permission
            if (!QRCodeTracker_Permissions::can_create_qr_codes()) {
                echo '<div class="error"><p>You do not have permission to create QR codes.</p></div>';
                return;
            }
            $postcode = strtoupper(sanitize_text_field($_POST['qr_postcode']));
            $city = sanitize_text_field($_POST['qr_city']);
            $tree = sanitize_text_field($_POST['qr_tree']);
            $label = sanitize_text_field($_POST['qr_label']);
            $reporting_id = sanitize_text_field($_POST['qr_reporting_id']);
            $message_1 = wp_unslash($_POST['qr_message_1']);
            $message_2 = wp_unslash($_POST['qr_message_2']);
            $show_popup = isset($_POST['qr_show_popup']) ? 1 : 0;
            $shop_link = esc_url_raw($_POST['qr_shop_link']);
            $shop_logo = esc_url_raw($_POST['qr_shop_logo']);
            $show_shop_link = isset($_POST['qr_show_shop_link']) ? 1 : 0;
            $team_id = !empty($_POST['qr_team']) && QRCodeTracker_Permissions::can_assign_qr_codes_to_teams() ? intval($_POST['qr_team']) : null;
            
            // Validate fields for spaces and special characters
            $validation_errors = [];
            
            if (preg_match('/[\s\W]/', $postcode)) {
                $validation_errors[] = 'Postcode cannot contain spaces or special characters (including hyphens).';
            }
            
            if (preg_match('/[\s\W]/', $city) && !preg_match('/^[A-Za-z0-9-]+$/', $city)) {
                $validation_errors[] = 'City cannot contain spaces or special characters (except hyphens).';
            }
            
            if (preg_match('/[\s\W]/', $tree)) {
                $validation_errors[] = 'Tree cannot contain spaces or special characters.';
            }
            
            // Validate team access and assignment permission
            if ($team_id) {
                if (!QRCodeTracker_Permissions::can_assign_qr_codes_to_teams()) {
                    $validation_errors[] = 'You do not have permission to assign QR codes to teams.';
                } elseif (!$this->teams->user_can_access_team(get_current_user_id(), $team_id)) {
                    $validation_errors[] = 'You do not have access to the selected team.';
                }
            }
            
            if (!empty($validation_errors)) {
                echo '<div class="error"><p><strong>Validation Errors:</strong></p><ul>';
                foreach ($validation_errors as $error) {
                    echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
            } else {
                $short_code = $this->tracker->generate_unique_short_code();
                $edit_token = $this->tracker->generate_unique_edit_token();
                $url = $this->tracker->generate_tracker_url($postcode, $city, $tree, $short_code);
                
                // Check for duplicate URL/short code
                $existing = $wpdb->get_row($wpdb->prepare("SELECT id, postcode, tree FROM {$this->main_table} WHERE url = %s OR short_code = %s", $url, $short_code));
                if ($existing) {
                    echo '<div class="error"><p>Error: A QR code with this URL or short code already exists (ID: ' . $existing->id . ', Postcode: ' . $existing->postcode . ', Tree: ' . $existing->tree . '). Please try again.</p></div>';
                } else {
                    $wpdb->insert($this->main_table, compact('url', 'short_code', 'edit_token', 'postcode', 'city', 'tree', 'label', 'reporting_id', 'message_1', 'message_2', 'show_popup', 'shop_link', 'shop_logo', 'show_shop_link', 'team_id'));
                    echo '<div class="updated"><p>QR Code entry saved.</p></div>';
                }
            }
        }

        if (isset($_POST['qr_edit_submit'])) {
            // Check edit permission
            if (!QRCodeTracker_Permissions::can_edit_qr_codes()) {
                echo '<div class="error"><p>You do not have permission to edit QR codes.</p></div>';
                return;
            }
            $postcode = strtoupper(sanitize_text_field($_POST['qr_postcode']));
            $edit_id = intval($_POST['qr_edit_id']);
            $row = $wpdb->get_row($wpdb->prepare("SELECT scan_count, url, short_code, edit_token FROM {$this->main_table} WHERE id = %d", $edit_id));
            
            if ($row) {
                $city = sanitize_text_field($_POST['qr_city']);
                $tree = sanitize_text_field($_POST['qr_tree']);
                $label = sanitize_text_field($_POST['qr_label']);
                $reporting_id = sanitize_text_field($_POST['qr_reporting_id']);
                $message_1 = wp_unslash($_POST['qr_message_1']);
                $message_2 = wp_unslash($_POST['qr_message_2']);
                $show_popup = isset($_POST['qr_show_popup']) ? 1 : 0;
                $shop_link = esc_url_raw($_POST['qr_shop_link']);
                $shop_logo = esc_url_raw($_POST['qr_shop_logo']);
                $show_shop_link = isset($_POST['qr_show_shop_link']) ? 1 : 0;
                $team_id = !empty($_POST['qr_team']) && QRCodeTracker_Permissions::can_assign_qr_codes_to_teams() ? intval($_POST['qr_team']) : null;
                
                // Validate fields for spaces and special characters
                $validation_errors = [];
                
                if (preg_match('/[\s\W]/', $postcode)) {
                    $validation_errors[] = 'Postcode cannot contain spaces or special characters (including hyphens).';
                }
                
                if (preg_match('/[\s\W]/', $city) && !preg_match('/^[A-Za-z0-9-]+$/', $city)) {
                    $validation_errors[] = 'City cannot contain spaces or special characters (except hyphens).';
                }
                
                if (preg_match('/[\s\W]/', $tree)) {
                    $validation_errors[] = 'Tree cannot contain spaces or special characters.';
                }
                
                // Validate team access and assignment permission
                if ($team_id) {
                    if (!QRCodeTracker_Permissions::can_assign_qr_codes_to_teams()) {
                        $validation_errors[] = 'You do not have permission to assign QR codes to teams.';
                    } elseif (!$this->teams->user_can_access_team(get_current_user_id(), $team_id)) {
                        $validation_errors[] = 'You do not have access to the selected team.';
                    }
                }
                
                if (!empty($validation_errors)) {
                    echo '<div class="error"><p><strong>Validation Errors:</strong></p><ul>';
                    foreach ($validation_errors as $error) {
                        echo '<li>' . esc_html($error) . '</li>';
                    }
                    echo '</ul></div>';
                } else {
                    if (empty($row->edit_token)) {
                        $row->edit_token = $this->tracker->generate_unique_edit_token();
                        $wpdb->update($this->main_table, ['edit_token' => $row->edit_token], ['id' => $edit_id]);
                    }
                    $short_code = !empty($row->short_code) ? $row->short_code : $this->tracker->generate_unique_short_code();
                    $url = $this->tracker->generate_tracker_url($postcode, $city, $tree, $short_code);
                    
                    // If no scans exist, allow URL editing
                    if ($row->scan_count == 0) {
                        $update_data = compact('url', 'short_code', 'postcode', 'city', 'tree', 'label', 'reporting_id', 'message_1', 'message_2', 'show_popup', 'shop_link', 'shop_logo', 'show_shop_link', 'team_id');
                    } else {
                        // If scans exist, only update non-URL fields
                        $update_data = compact('short_code', 'postcode', 'city', 'tree', 'label', 'reporting_id', 'message_1', 'message_2', 'show_popup', 'shop_link', 'shop_logo', 'show_shop_link', 'team_id');
                    }
                    
                    $wpdb->update($this->main_table, $update_data, ['id' => $edit_id]);
                    echo '<div class="updated"><p>QR Code entry updated.</p></div>';
                }
            } else {
                echo '<div class="error"><p>QR Code not found.</p></div>';
            }
        }

        if (isset($_GET['delete_id'])) {
            // Check delete permission
            if (!QRCodeTracker_Permissions::can_delete_qr_codes()) {
                echo '<div class="error"><p>You do not have permission to delete QR codes.</p></div>';
                return;
            }
            
            $delete_id = intval($_GET['delete_id']);
            $row = $wpdb->get_row($wpdb->prepare("SELECT scan_count, team_id FROM {$this->main_table} WHERE id = %d", $delete_id));
            
            if ($row) {
                // Check if user can access this QR code
                if ($row->team_id && !$this->teams->user_can_access_team(get_current_user_id(), $row->team_id)) {
                    echo '<div class="error"><p>You do not have permission to delete this QR code.</p></div>';
                } elseif ($row->scan_count == 0) {
                    // Only allow deletion if no scans exist
                    $wpdb->delete($this->main_table, ['id' => $delete_id]);
                    echo '<div class="updated"><p>QR Code entry deleted.</p></div>';
                } else {
                    echo '<div class="error"><p>Cannot delete QR code with existing scan data. Use the merge function instead.</p></div>';
                }
            } else {
                echo '<div class="error"><p>QR Code not found.</p></div>';
            }
        }

        if (isset($_POST['qr_merge_submit'])) {
            $source_id = intval($_POST['qr_merge_source_id']);
            $target_allocations = $_POST['qr_merge_allocations'];
            $total_allocated = 0;
            
            // Validate allocations
            foreach ($target_allocations as $target_id => $allocation) {
                if (!empty($allocation) && is_numeric($allocation)) {
                    $total_allocated += intval($allocation);
                }
            }
            
            // Verify source exists and has enough scans
            $source = $wpdb->get_row($wpdb->prepare("SELECT scan_count FROM {$this->main_table} WHERE id = %d", $source_id));
            
            if ($source && $source->scan_count > 0 && $total_allocated == $source->scan_count) {
                // Process each allocation
                foreach ($target_allocations as $target_id => $allocation) {
                    if (!empty($allocation) && is_numeric($allocation) && intval($allocation) > 0) {
                        $target_id = intval($target_id);
                        $allocation = intval($allocation);
                        
                        // Update scan count on target
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$this->main_table} SET scan_count = scan_count + %d WHERE id = %d",
                            $allocation, $target_id
                        ));
                        
                        // Transfer proportional scan logs to target
                        // Get the allocation percentage and apply to scan logs
                        $percentage = $allocation / $source->scan_count;
                        $logs_to_transfer = $wpdb->get_results($wpdb->prepare(
                            "SELECT id FROM {$this->log_table} WHERE tracker_id = %d ORDER BY scanned_at LIMIT %d",
                            $source_id, $allocation
                        ));
                        
                        if (!empty($logs_to_transfer)) {
                            $log_ids = array_column($logs_to_transfer, 'id');
                            $placeholders = implode(',', array_fill(0, count($log_ids), '%d'));
                            $wpdb->query($wpdb->prepare(
                                "UPDATE {$this->log_table} SET tracker_id = %d WHERE id IN ($placeholders)",
                                array_merge([$target_id], $log_ids)
                            ));
                        }
                    }
                }
                
                // Delete the source entry
                $wpdb->delete($this->main_table, ['id' => $source_id]);
                
                echo '<div class="updated"><p>QR Code merged successfully. ' . $source->scan_count . ' scans distributed across ' . count(array_filter($target_allocations)) . ' target QR codes.</p></div>';
            } else {
                echo '<div class="error"><p>Merge failed. Please ensure total allocation equals source scan count (' . ($source ? $source->scan_count : 0) . ').</p></div>';
            }
        }

        // Handle edit form display
        $editing = false;
        $edit_data = null;
        if (isset($_GET['edit_id'])) {
            $edit_id = intval($_GET['edit_id']);
            $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $edit_id));
            if ($edit_data) {
                // Check if user can access this QR code
                if ($edit_data->team_id && !$this->teams->user_can_access_team(get_current_user_id(), $edit_data->team_id)) {
                    echo '<div class="error"><p>You do not have permission to edit this QR code.</p></div>';
                } else {
                    $editing = true;
                }
            } else {
                echo '<div class="error"><p>QR Code not found.</p></div>';
            }
        }

        // Handle merge form display
        $merging = false;
        $merge_data = null;
        if (isset($_GET['merge_id'])) {
            $merge_id = intval($_GET['merge_id']);
            $merge_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->main_table} WHERE id = %d", $merge_id));
            if ($merge_data && $merge_data->scan_count > 0) {
                // Check if user can access this QR code
                if ($merge_data->team_id && !$this->teams->user_can_access_team(get_current_user_id(), $merge_data->team_id)) {
                    echo '<div class="error"><p>You do not have permission to merge this QR code.</p></div>';
                } else {
                    $merging = true;
                }
            } else {
                echo '<div class="error"><p>Cannot merge QR code with no scan data. Use delete instead.</p></div>';
            }
        }

        // Display the add QR code form at the top
        if ($merging && $merge_data) {
            // Get accessible QR codes for target selection (excluding the source)
            $target_options = $this->teams->get_accessible_qr_codes();
            $target_options = array_filter($target_options, function($option) use ($merge_data) {
                return $option->id != $merge_data->id;
            });
            
            echo '<h2>Merge QR Code</h2>
            <p><strong>Source QR Code:</strong> Postcode: ' . esc_html($merge_data->postcode) . ' - Tree: ' . esc_html($merge_data->tree) . ' (' . $merge_data->scan_count . ' scans)</p>
            <form method="post">
                <input type="hidden" name="qr_merge_source_id" value="' . $merge_data->id . '">
                <table class="form-table">
                    <tr><th><label>Distribute scans to:</label></th>
                        <td><div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px;">';
            foreach ($target_options as $option) {
                echo '<div style="margin-bottom: 10px;">
                    <label style="display: inline-block; width: 200px;">Postcode: ' . esc_html($option->postcode) . ' - Tree: ' . esc_html($option->tree) . '</label>
                    <input type="number" name="qr_merge_allocations[' . $option->id . ']" min="0" max="' . $merge_data->scan_count . '" placeholder="0" style="width: 80px;">
                    <span style="color: #666; font-size: 12px;">(' . esc_html($option->label) . ')</span>
                </div>';
            }
            echo '</div></td></tr>
                </table>
                <p><strong>Total to allocate:</strong> <span id="total-allocated">0</span> / ' . $merge_data->scan_count . '</p>
                <p><strong>Warning:</strong> This will distribute scans from the source to the target QR codes and delete the source entry.</p>
                <p><input type="submit" name="qr_merge_submit" class="button button-primary" value="Merge QR Code" onclick="return confirm(\'Are you sure you want to merge these QR codes? This action cannot be undone.\')"></p>
            </form>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const inputs = document.querySelectorAll("input[name^=\'qr_merge_allocations\']");
                const totalSpan = document.getElementById("total-allocated");
                
                function updateTotal() {
                    let total = 0;
                    inputs.forEach(input => {
                        total += parseInt(input.value) || 0;
                    });
                    totalSpan.textContent = total;
                    totalSpan.style.color = total == ' . $merge_data->scan_count . ' ? "green" : "red";
                }
                
                inputs.forEach(input => {
                    input.addEventListener("input", updateTotal);
                });
                updateTotal();
            });
            </script>';
        } elseif ($editing && $edit_data) {
            echo '<h2>Edit QR Code</h2>';
            if ($edit_data->scan_count > 0) {
                echo '<div class="notice notice-warning"><p><strong>Note:</strong> This QR code has ' . number_format($edit_data->scan_count) . ' scan(s). The URL is auto-generated and locked to preserve tracking data. <br>Because the URL is based on postcode, city, and tree, <strong>these fields cannot be edited</strong> once scans exist. You can still edit other fields or use the merge function to redistribute scans.</p></div>';
            } else {
                echo '<div class="notice notice-info"><p><strong>Important:</strong> Postcode, City, and Tree fields cannot contain spaces or special characters. Only letters, numbers, and hyphens are allowed.</p></div>';
            }
            if (!empty($edit_data->short_code)) {
                $anonymous_url = $this->tracker->generate_anonymous_tracker_url($edit_data->short_code);
                if (!empty($anonymous_url)) {
                    echo '<div class="notice notice-info"><p><strong>Anonymous Link:</strong> <a href="' . esc_url($anonymous_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($anonymous_url) . '</a><br><span class="description">Share this short link externally. Existing legacy links continue to work.</span></p></div>';
                }
            }
            if (empty($edit_data->edit_token)) {
                $edit_data->edit_token = $this->tracker->generate_unique_edit_token();
                $wpdb->update($this->main_table, ['edit_token' => $edit_data->edit_token], ['id' => $edit_data->id]);
            }
            $management_url = !empty($edit_data->edit_token) ? $this->tracker->generate_qr_management_url($edit_data->edit_token) : '';
            if (!empty($management_url)) {
                echo '<div class="notice notice-info"><p><strong>Management Link:</strong> <a href="' . esc_url($management_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($management_url) . '</a><br><span class="description">Share this link with someone who can create/log in to a WordPress account and edit this QR code\'s message/details.</span></p></div>';
            }
            echo '<form method="post" id="qr-edit-form">
                <input type="hidden" name="qr_edit_id" value="' . $edit_data->id . '">
                <table class="form-table">';
            // Postcode
            echo '<tr><th><label for="qr_postcode">Postcode:</label></th><td><input type="text" name="qr_postcode" id="qr_postcode" required pattern="[A-Za-z0-9]+" title="Only letters and numbers allowed" value="' . esc_attr($edit_data->postcode) . '"' . ($edit_data->scan_count > 0 ? ' readonly style="background-color:#f0f0f0;color:#666;"' : '') . '>';
            if ($edit_data->scan_count == 0) {
                echo '<p class="description">Only letters and numbers allowed. No spaces, hyphens, or special characters.</p>';
            }
            echo '</td></tr>';
            // City
            echo '<tr><th><label for="qr_city">City:</label></th><td><input type="text" name="qr_city" id="qr_city" pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed" value="' . esc_attr($edit_data->city) . '"' . ($edit_data->scan_count > 0 ? ' readonly style="background-color:#f0f0f0;color:#666;"' : '') . '>';
            if ($edit_data->scan_count == 0) {
                echo '<p class="description">Only letters, numbers, and hyphens allowed. No spaces or special characters.</p>';
            }
            echo '</td></tr>';
            // Tree
            echo '<tr><th><label for="qr_tree">Tree (free text):</label></th><td><input type="text" name="qr_tree" id="qr_tree" required pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed" value="' . esc_attr($edit_data->tree) . '"' . ($edit_data->scan_count > 0 ? ' readonly style="background-color:#f0f0f0;color:#666;"' : '') . '>';
            if ($edit_data->scan_count == 0) {
                echo '<p class="description">Only letters, numbers, and hyphens allowed. No spaces or special characters.</p>';
            }
            echo '</td></tr>';
            echo '<tr><th><label for="qr_label">Label (optional):</label></th>
                        <td><input type="text" name="qr_label" value="' . esc_attr($edit_data->label) . '"></td></tr>
                    <tr><th><label for="qr_reporting_id">Reporting ID (optional):</label></th>
                        <td><input type="text" name="qr_reporting_id" placeholder="Link to related entries" value="' . esc_attr($edit_data->reporting_id) . '"></td></tr>
                    <tr><th><label for="qr_message_1">Message 1 (HTML):</label></th>
                        <td>'; wp_editor($edit_data->message_1, 'qr_message_1', ['textarea_rows' => 5]); echo '<p class="description"><strong>Shortcode:</strong> <code>[qr_tracker_message_1]</code> - Use this shortcode in your WordPress pages/posts to display this message when someone scans the QR code.</p></td></tr>
                    <tr><th><label for="qr_message_2">Message 2 (HTML):</label></th>
                        <td>'; wp_editor($edit_data->message_2, 'qr_message_2', ['textarea_rows' => 5]); echo '<p class="description"><strong>Button Shortcode:</strong> <code>[qr_message_2_button url="https://example.com" label="Read More"]</code> - Use this shortcode within your message content to add a button with custom URL and label.</p><p class="description"><strong>Shortcode:</strong> <code>[qr_tracker_message_2]</code> - Use this shortcode in your WordPress pages/posts to display this message when someone scans the QR code.</p></td></tr>
                    <tr><th><label for="qr_show_popup">Show Popup:</label></th>
                        <td><input type="checkbox" name="qr_show_popup" id="qr_show_popup" value="1"' . ($edit_data->show_popup ? ' checked' : '') . '> <label for="qr_show_popup">Display popup with messages when QR code is scanned</label><p class="description">If checked, a popup will appear showing both messages when someone scans this QR code.</p></td></tr>
                    <tr><th><label for="qr_shop_link">Shop Link:</label></th>
                        <td><input type="url" name="qr_shop_link" id="qr_shop_link" value="' . esc_attr($edit_data->shop_link) . '" placeholder="https://example.com/shop"><p class="description">URL to your shop. Leave empty to use default from settings.</p></td></tr>
                    <tr><th><label for="qr_shop_logo">Shop Logo URL:</label></th>
                        <td><input type="url" name="qr_shop_logo" id="qr_shop_logo" value="' . esc_attr($edit_data->shop_logo) . '" placeholder="https://example.com/logo.png"><p class="description">URL to your shop logo image. Leave empty to use default from settings.</p></td></tr>
                    <tr><th><label for="qr_show_shop_link">Show Shop Link:</label></th>
                        <td><input type="checkbox" name="qr_show_shop_link" id="qr_show_shop_link" value="1"' . ($edit_data->show_shop_link ? ' checked' : '') . '> <label for="qr_show_shop_link">Display shop logo as clickable link when QR code is scanned</label><p class="description">If checked, the shop logo will be displayed as a clickable link using the <code>[qr_tracker_shop_link]</code> shortcode. Both shop link and logo URL must be set.</p></td></tr>';
            
            // Team selection (only show if user has permission)
            if (QRCodeTracker_Permissions::can_assign_qr_codes_to_teams()) {
                $accessible_teams = $this->teams->get_accessible_teams();
                echo '<tr><th><label for="qr_team">Team:</label></th><td>';
                echo '<select name="qr_team" id="qr_team">';
                echo '<option value="">No Team</option>';
                foreach ($accessible_teams as $team) {
                    $selected = ($edit_data->team_id == $team->id) ? ' selected' : '';
                    echo '<option value="' . $team->id . '"' . $selected . '>' . esc_html($team->name) . '</option>';
                }
                echo '</select>';
                echo '<p class="description">Select the team that manages this QR code. You can only assign QR codes to teams you are a member of.</p>';
                echo '</td></tr>';
            } else {
                // Show current team assignment as read-only
                $current_team = $edit_data->team_id ? $this->teams->get_team($edit_data->team_id) : null;
                echo '<tr><th>Team:</th><td>';
                if ($current_team) {
                    echo '<strong>' . esc_html($current_team->name) . '</strong>';
                    echo '<p class="description">You do not have permission to change team assignments.</p>';
                } else {
                    echo '<em>No Team Assigned</em>';
                    echo '<p class="description">You do not have permission to assign QR codes to teams.</p>';
                }
                echo '</td></tr>';
            }
            
            echo '</table>
                <p><input type="submit" name="qr_edit_submit" class="button button-primary" value="Update QR Code"></p>
            </form>';
            
            // Add JavaScript validation for edit form (only if fields are not readonly)
            if ($edit_data->scan_count == 0) {
                echo '<script>
                document.addEventListener("DOMContentLoaded", function() {
                    const restrictedFields = ["qr_postcode", "qr_city", "qr_tree"];
                    
                    restrictedFields.forEach(function(fieldId) {
                        const field = document.getElementById(fieldId);
                        if (field && !field.readOnly) {
                            // Prevent typing invalid characters
                            field.addEventListener("input", function(e) {
                                const value = this.value;
                                let cleaned;
                                if (fieldId === "qr_postcode") {
                                    cleaned = value.replace(/[^A-Za-z0-9]/g, "");
                                } else {
                                    cleaned = value.replace(/[^A-Za-z0-9-]/g, "");
                                }
                                if (value !== cleaned) {
                                    this.value = cleaned;
                                    // Show warning
                                    if (fieldId === "qr_postcode") {
                                        showWarning(this, "Invalid characters removed. Only letters and numbers are allowed.");
                                    } else if (fieldId === "qr_city") {
                                        showWarning(this, "Invalid characters removed. Only letters, numbers, and hyphens are allowed.");
                                    } else {
                                        showWarning(this, "Invalid characters removed. Only letters, numbers, and hyphens are allowed.");
                                    }
                                }
                            });
                            
                            // Prevent pasting invalid characters
                            field.addEventListener("paste", function(e) {
                                e.preventDefault();
                                const pastedText = (e.clipboardData || window.clipboardData).getData("text");
                                let cleaned;
                                if (fieldId === "qr_postcode") {
                                    cleaned = pastedText.replace(/[^A-Za-z0-9]/g, "");
                                } else {
                                    cleaned = pastedText.replace(/[^A-Za-z0-9-]/g, "");
                                }
                                if (pastedText !== cleaned) {
                                    if (fieldId === "qr_postcode") {
                                        showWarning(this, "Invalid characters removed from pasted text. Only letters and numbers are allowed.");
                                    } else if (fieldId === "qr_city") {
                                        showWarning(this, "Invalid characters removed from pasted text. Only letters, numbers, and hyphens are allowed.");
                                    } else {
                                        showWarning(this, "Invalid characters removed from pasted text. Only letters, numbers, and hyphens are allowed.");
                                    }
                                }
                                this.value += cleaned;
                            });
                            
                            // Show warning on focus
                            field.addEventListener("focus", function() {
                                if (fieldId === "qr_postcode") {
                                    showWarning(this, "Only letters and numbers are allowed. No spaces, hyphens, or special characters.");
                                } else if (fieldId === "qr_city") {
                                    showWarning(this, "Only letters, numbers, and hyphens are allowed. No spaces or special characters.");
                                } else {
                                    showWarning(this, "Only letters, numbers, and hyphens are allowed. No spaces or special characters.");
                                }
                            });
                        }
                    });
                    
                    function showWarning(field, message) {
                        // Remove existing warning
                        const existingWarning = field.parentNode.querySelector(".field-warning");
                        if (existingWarning) {
                            existingWarning.remove();
                        }
                        
                        // Create warning element
                        const warning = document.createElement("div");
                        warning.className = "field-warning";
                        warning.style.cssText = "color: #d63638; font-size: 12px; margin-top: 5px; font-style: italic;";
                        warning.textContent = message;
                        
                        // Insert after the field
                        field.parentNode.insertBefore(warning, field.nextSibling);
                        
                        // Remove warning after 3 seconds
                        setTimeout(function() {
                            if (warning.parentNode) {
                                warning.remove();
                            }
                        }, 3000);
                    }
                });
                </script>';
            }
        } else {
            echo '<h2>Add QR Code</h2>
            <div class="notice notice-info"><p><strong>Important:</strong> Postcode, City, and Tree fields cannot contain spaces or special characters. Only letters, numbers, and hyphens are allowed.</p></div>
            <form method="post" id="qr-form">
                <table class="form-table">
                    <tr><th><label for="qr_postcode">Postcode:</label></th>
                        <td><input type="text" name="qr_postcode" id="qr_postcode" required pattern="[A-Za-z0-9]+" title="Only letters and numbers allowed">
                        <p class="description">Only letters and numbers allowed. No spaces, hyphens, or special characters.</p></td></tr>
                    <tr><th><label for="qr_city">City:</label></th>
                        <td><input type="text" name="qr_city" id="qr_city" pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed">
                        <p class="description">Only letters, numbers, and hyphens allowed. No spaces or special characters.</p></td></tr>
                    <tr><th><label for="qr_tree">Tree (free text):</label></th>
                        <td><input type="text" name="qr_tree" id="qr_tree" required pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed">
                        <p class="description">Only letters, numbers, and hyphens allowed. No spaces or special characters.</p></td></tr>
                    <tr><th><label for="qr_label">Label (optional):</label></th>
                        <td><input type="text" name="qr_label"></td></tr>
                    <tr><th><label for="qr_reporting_id">Reporting ID (optional):</label></th>
                        <td><input type="text" name="qr_reporting_id" placeholder="Link to related entries"></td></tr>
                    <tr><th><label for="qr_message_1">Message 1 (HTML):</label></th>
                        <td>'; wp_editor('', 'qr_message_1', ['textarea_rows' => 5]); echo '<p class="description"><strong>Shortcode:</strong> <code>[qr_tracker_message_1]</code> - Use this shortcode in your WordPress pages/posts to display this message when someone scans the QR code.</p></td></tr>
                    <tr><th><label for="qr_message_2">Message 2 (HTML):</label></th>
                        <td>'; wp_editor('', 'qr_message_2', ['textarea_rows' => 5]); echo '<p class="description"><strong>Button Shortcode:</strong> <code>[qr_message_2_button url="https://example.com" label="Read More"]</code> - Use this shortcode within your message content to add a button with custom URL and label.</p><p class="description"><strong>Shortcode:</strong> <code>[qr_tracker_message_2]</code> - Use this shortcode in your WordPress pages/posts to display this message when someone scans the QR code.</p></td></tr>
                    <tr><th><label for="qr_show_popup">Show Popup:</label></th>
                        <td><input type="checkbox" name="qr_show_popup" id="qr_show_popup" value="1" checked> <label for="qr_show_popup">Display popup with messages when QR code is scanned</label><p class="description">If checked, a popup will appear showing both messages when someone scans this QR code.</p></td></tr>
                    <tr><th><label for="qr_shop_link">Shop Link:</label></th>
                        <td><input type="url" name="qr_shop_link" id="qr_shop_link" placeholder="https://example.com/shop"><p class="description">URL to your shop. Leave empty to use default from settings.</p></td></tr>
                    <tr><th><label for="qr_shop_logo">Shop Logo URL:</label></th>
                        <td><input type="url" name="qr_shop_logo" id="qr_shop_logo" placeholder="https://example.com/logo.png"><p class="description">URL to your shop logo image. Leave empty to use default from settings.</p></td></tr>
                    <tr><th><label for="qr_show_shop_link">Show Shop Link:</label></th>
                        <td><input type="checkbox" name="qr_show_shop_link" id="qr_show_shop_link" value="1" checked> <label for="qr_show_shop_link">Display shop logo as clickable link when QR code is scanned</label><p class="description">If checked, the shop logo will be displayed as a clickable link using the <code>[qr_tracker_shop_link]</code> shortcode. Both shop link and logo URL must be set.</p></td></tr>';
            
            // Team selection (only show if user has permission)
            if (QRCodeTracker_Permissions::can_assign_qr_codes_to_teams()) {
                $accessible_teams = $this->teams->get_accessible_teams();
                echo '<tr><th><label for="qr_team">Team:</label></th><td>';
                echo '<select name="qr_team" id="qr_team">';
                echo '<option value="">No Team</option>';
                foreach ($accessible_teams as $team) {
                    echo '<option value="' . $team->id . '">' . esc_html($team->name) . '</option>';
                }
                echo '</select>';
                echo '<p class="description">Select the team that manages this QR code. You can only assign QR codes to teams you are a member of.</p>';
                echo '</td></tr>';
            } else {
                echo '<tr><th>Team:</th><td>';
                echo '<em>No Team Assignment</em>';
                echo '<p class="description">You do not have permission to assign QR codes to teams.</p>';
                echo '</td></tr>';
            }
            
            echo '</table>
                <p><input type="submit" name="qr_submit" class="button button-primary" value="Save QR Code"></p>
            </form>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                const restrictedFields = ["qr_postcode", "qr_city", "qr_tree"];
                
                restrictedFields.forEach(function(fieldId) {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        // Prevent typing invalid characters
                        field.addEventListener("input", function(e) {
                            const value = this.value;
                            let cleaned;
                            if (fieldId === "qr_postcode") {
                                cleaned = value.replace(/[^A-Za-z0-9]/g, "");
                            } else {
                                cleaned = value.replace(/[^A-Za-z0-9-]/g, "");
                            }
                            if (value !== cleaned) {
                                this.value = cleaned;
                                // Show warning
                                if (fieldId === "qr_postcode") {
                                    showWarning(this, "Invalid characters removed. Only letters and numbers are allowed.");
                                } else if (fieldId === "qr_city") {
                                    showWarning(this, "Invalid characters removed. Only letters, numbers, and hyphens are allowed.");
                                } else {
                                    showWarning(this, "Invalid characters removed. Only letters, numbers, and hyphens are allowed.");
                                }
                            }
                        });
                        
                        // Prevent pasting invalid characters
                        field.addEventListener("paste", function(e) {
                            e.preventDefault();
                            const pastedText = (e.clipboardData || window.clipboardData).getData("text");
                            let cleaned;
                            if (fieldId === "qr_postcode") {
                                cleaned = pastedText.replace(/[^A-Za-z0-9]/g, "");
                            } else {
                                cleaned = pastedText.replace(/[^A-Za-z0-9-]/g, "");
                            }
                            if (pastedText !== cleaned) {
                                if (fieldId === "qr_postcode") {
                                    showWarning(this, "Invalid characters removed from pasted text. Only letters and numbers are allowed.");
                                } else if (fieldId === "qr_city") {
                                    showWarning(this, "Invalid characters removed from pasted text. Only letters, numbers, and hyphens are allowed.");
                                } else {
                                    showWarning(this, "Invalid characters removed from pasted text. Only letters, numbers, and hyphens are allowed.");
                                }
                            }
                            this.value += cleaned;
                        });
                        
                        // Show warning on focus
                        field.addEventListener("focus", function() {
                            if (fieldId === "qr_postcode") {
                                showWarning(this, "Only letters and numbers are allowed. No spaces, hyphens, or special characters.");
                            } else if (fieldId === "qr_city") {
                                showWarning(this, "Only letters, numbers, and hyphens are allowed. No spaces or special characters.");
                            } else {
                                showWarning(this, "Only letters, numbers, and hyphens are allowed. No spaces or special characters.");
                            }
                        });
                    }
                });
                
                function showWarning(field, message) {
                    // Remove existing warning
                    const existingWarning = field.parentNode.querySelector(".field-warning");
                    if (existingWarning) {
                        existingWarning.remove();
                    }
                    
                    // Create warning element
                    const warning = document.createElement("div");
                    warning.className = "field-warning";
                    warning.style.cssText = "color: #d63638; font-size: 12px; margin-top: 5px; font-style: italic;";
                    warning.textContent = message;
                    
                    // Insert after the field
                    field.parentNode.insertBefore(warning, field.nextSibling);
                    
                    // Remove warning after 3 seconds
                    setTimeout(function() {
                        if (warning.parentNode) {
                            warning.remove();
                        }
                    }, 3000);
                }
            });
            </script>';
        }

        // Now display the Tracked QR Codes table
        $entries = $this->teams->get_accessible_qr_codes();
        echo '<h2>Tracked QR Codes</h2><table class="widefat"><thead><tr><th>Postcode</th><th>City</th><th>Tree</th><th>Label</th><th>Reporting ID</th><th>Popup</th><th>Shop Link</th><th>Team</th><th>URL</th><th>QR Code</th><th>Scans</th><th>Last Scanned</th><th>Actions</th></tr></thead><tbody>';
        foreach ($entries as $row) {
            $delete_url = esc_url(add_query_arg(['delete_id' => $row->id]));
            $edit_url = esc_url(add_query_arg(['edit_id' => $row->id]));
            $merge_url = esc_url(add_query_arg(['merge_id' => $row->id]));
            $download_url = esc_url(admin_url('admin.php?action=qr_tracker_download_qr&id=' . $row->id));
            $popup_status = $row->show_popup ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: #666;">✗ Disabled</span>';
            $shop_link_status = $row->show_shop_link ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: #666;">✗ Disabled</span>';
            
            // Get team name
            $team_name = 'No Team';
            if ($row->team_id) {
                $team = $this->teams->get_team($row->team_id);
                if ($team) {
                    $team_name = esc_html($team->name);
                }
            }

            if (empty($row->edit_token)) {
                $row->edit_token = $this->tracker->generate_unique_edit_token();
                $wpdb->update($this->main_table, ['edit_token' => $row->edit_token], ['id' => $row->id]);
            }

            $url_display = '<code>' . esc_html($row->url) . '</code>';
            $anonymous_url = !empty($row->short_code) ? $this->tracker->generate_anonymous_tracker_url($row->short_code) : '';
            if (!empty($anonymous_url) && untrailingslashit($anonymous_url) !== untrailingslashit($row->url)) {
                $url_display .= '<br><code>' . esc_html($anonymous_url) . '</code><br><span class="description">Anonymous link</span>';
            }
            $management_url = !empty($row->edit_token) ? $this->tracker->generate_qr_management_url($row->edit_token) : '';
            if (!empty($management_url)) {
                $url_display .= '<br><code>' . esc_html($management_url) . '</code><br><span class="description">Management link</span>';
            }
            
            echo "<tr><td>{$row->postcode}</td><td>{$row->city}</td><td>{$row->tree}</td><td>{$row->label}</td><td>{$row->reporting_id}</td><td>{$popup_status}</td><td>{$shop_link_status}</td><td>{$team_name}</td><td>{$url_display}</td><td><img src='" . esc_attr($this->tracker->generate_qr_code_image($row->url)) . "' alt='QR Code' style='width:80px;height:80px;'></td><td>{$row->scan_count}</td><td>{$row->last_scanned}</td>";
            echo "<td>";
            if ($row->scan_count == 0) {
                echo "<a href=\"$edit_url\" class=\"button button-secondary\">Edit</a> ";
                echo "<a href=\"$delete_url\" onclick=\"return confirm('Are you sure you want to delete this QR code?')\" class=\"button button-secondary\">Delete</a> ";
            } else {
                echo "<a href=\"$edit_url\" class=\"button button-secondary\">Edit</a> ";
                echo "<a href=\"$merge_url\" class=\"button button-secondary\">Merge</a> ";
            }
            echo "<a href='$download_url' class='button' target='_blank'>Download QR Image</a> ";
            if ($row->scan_count > 0) {
                $report_url = esc_url(admin_url('admin.php?page=qr-single-report&qr_id=' . $row->id));
                echo "<a href='$report_url' class='button button-primary'>Report</a> ";
            }
            echo "</td></tr>";
        }
        echo '</tbody></table>';

        // Display quick summary
        $accessible_teams = $this->teams->get_accessible_teams();
        $total_qr_codes = count($entries);
        $total_scans = array_sum(array_column($entries, 'scan_count'));
        $active_qr_codes = count(array_filter($entries, function($entry) { return $entry->scan_count > 0; }));
        $recent_scans = $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table} WHERE scanned_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        
        echo '<h2>Quick Summary</h2>';
        echo '<div style="display: flex; gap: 20px; margin: 20px 0;">';
        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . number_format($total_qr_codes ?? 0) . '</div>';
        echo '<div style="color: #666; margin-top: 5px;">Total QR Codes</div>';
        echo '</div>';
        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . number_format($total_scans ?? 0) . '</div>';
        echo '<div style="color: #666; margin-top: 5px;">Total Scans</div>';
        echo '</div>';
        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . number_format($active_qr_codes ?? 0) . '</div>';
        echo '<div style="color: #666; margin-top: 5px;">Active QR Codes</div>';
        echo '</div>';
        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; flex: 1; text-align: center;">';
        echo '<div style="font-size: 24px; font-weight: bold; color: #0073aa;">' . number_format($recent_scans ?? 0) . '</div>';
        echo '<div style="color: #666; margin-top: 5px;">Scans (7 days)</div>';
        echo '</div>';
        echo '</div>';
        echo '<p><a href="' . admin_url('admin.php?page=qr-reports') . '" class="button button-primary">View Detailed Reports</a></p>';
    }

    // 2. Add a helper to generate the URL from postcode, city, and tree
    private function generate_tracker_url($postcode, $city, $tree, $short_code = '') {
        return $this->tracker->generate_tracker_url($postcode, $city, $tree, $short_code);
    }





    public function scan_logs_page() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_view_scan_logs()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_view_scan_logs');
            return;
        }
        
        // Include the scan logs class
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-scan-logs.php';
        
        // Create instance and display the page
        $scan_logs = new QRCodeTracker_ScanLogs($this->tracker);
        $scan_logs->display_scan_logs_page();
    }

        public function reports_page() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_view_reports()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_view_reports');
            return;
        }
        
        // Use the new reports display class
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-reports-display.php';
        $reports_display = new QRCodeTracker_ReportsDisplay($this->search, $this->teams);
        $reports_display->display_reports_page();
    }

    public function settings_page() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_view_settings()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_view_settings');
            return;
        }
        
        $plugin_version = '0.9994';
        if (isset($_POST['qr_tracker_settings_submit'])) {
            // Check manage settings permission
            if (!QRCodeTracker_Permissions::can_manage_settings()) {
                echo '<div class="error"><p>You do not have permission to manage settings.</p></div>';
                return;
            }
            check_admin_referer('qr_tracker_settings');
            $delete_on_uninstall = isset($_POST['qr_tracker_delete_on_uninstall']) ? 1 : 0;
            update_option('qr_tracker_delete_on_uninstall', $delete_on_uninstall);
            
            $default_shop_link = esc_url_raw($_POST['qr_tracker_default_shop_link']);
            $default_shop_logo = esc_url_raw($_POST['qr_tracker_default_shop_logo']);
            update_option('qr_tracker_default_shop_link', $default_shop_link);
            update_option('qr_tracker_default_shop_logo', $default_shop_logo);

            $tree_product_ids = isset($_POST['qr_tracker_tree_product_ids']) && is_array($_POST['qr_tracker_tree_product_ids'])
                ? array_values(array_unique(array_filter(array_map('intval', wp_unslash($_POST['qr_tracker_tree_product_ids'])))))
                : [];
            update_option('qr_tracker_tree_product_ids', $tree_product_ids);
            
            echo '<div class="updated"><p>Settings saved.</p></div>';
        }
        $delete_on_uninstall = get_option('qr_tracker_delete_on_uninstall', 0);
        $default_shop_link = get_option('qr_tracker_default_shop_link', '');
        $default_shop_logo = get_option('qr_tracker_default_shop_logo', '');
        $tree_product_ids = get_option('qr_tracker_tree_product_ids', []);
        if (!is_array($tree_product_ids)) {
            $tree_product_ids = [];
        }

        $available_tree_products = [];
        if (post_type_exists('product')) {
            $available_tree_products = get_posts([
                'post_type' => 'product',
                'post_status' => ['publish', 'private'],
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
                'suppress_filters' => false,
            ]);
        }

        echo '<div class="wrap"><h1>QR Code Tracker Settings</h1>';
        echo '<div style="margin-bottom:20px;padding:10px 15px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;max-width:600px;">';
        echo '<strong>Plugin Version:</strong> ' . esc_html($plugin_version) . '<br>';
        echo '<strong>Available Shortcodes:</strong>';
        echo '<ul style="margin-top:8px;">';
        echo '<li><code>[qr_tracker_message_1]</code> — Displays the first custom message for the current QR code scan.</li>';
        echo '<li><code>[qr_tracker_message_2]</code> — Displays the second custom message for the current QR code scan.</li>';
        echo '<li><code>[qr_tracker_shop_link]</code> — Displays shop logo as a clickable link for the current QR code scan.</li>';
        echo '<li><code>[qr_tracker_shop_link max_width="300px"]</code> — Customize the logo size.</li>';
        echo '<li><code>[qr_tracker_popup]</code> — Displays a button that triggers the popup with both messages.</li>';
        echo '<li><code>[qr_tracker_popup text="Custom Text"]</code> — Customize the button text.</li>';
        echo '</ul>';
        echo '<div style="margin-top:10px;font-size:12px;color:#666;">QR code generation powered by <a href="https://github.com/chillerlan/php-qrcode" target="_blank">chillerlan/php-qrcode</a>.</div>';
        echo '</div>';
        echo '<form method="post">';
        wp_nonce_field('qr_tracker_settings');
        echo '<table class="form-table">';
        echo '<tr><th scope="row">Delete Data on Uninstall</th><td>';
        echo '<label><input type="checkbox" name="qr_tracker_delete_on_uninstall" value="1"' . checked(1, $delete_on_uninstall, false) . '> Delete all plugin data when the plugin is uninstalled</label>';
        echo '<p class="description">If checked, all QR code data and logs will be permanently deleted when you uninstall the plugin.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Default Shop Link</th><td>';
        echo '<input type="url" name="qr_tracker_default_shop_link" value="' . esc_attr($default_shop_link) . '" placeholder="https://example.com/shop" style="width: 100%;">';
        echo '<p class="description">Default shop URL to use when individual QR codes don\'t have their own shop link set.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Default Shop Logo</th><td>';
        echo '<input type="url" name="qr_tracker_default_shop_logo" value="' . esc_attr($default_shop_logo) . '" placeholder="https://example.com/logo.png" style="width: 100%;">';
        echo '<p class="description">Default shop logo URL to use when individual QR codes don\'t have their own logo set.</p>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Tree Product Selection</th><td>';
        if (!post_type_exists('product')) {
            echo '<p class="description">WooCommerce products are unavailable. Activate WooCommerce to configure this setting.</p>';
        } elseif (empty($available_tree_products)) {
            echo '<p class="description">No WooCommerce products found.</p>';
        } else {
            echo '<select name="qr_tracker_tree_product_ids[]" multiple size="12" style="width: 100%; max-width: 600px;">';
            foreach ($available_tree_products as $product_id) {
                $title = get_the_title($product_id);
                if ($title === '') {
                    $title = '(no title)';
                }
                echo '<option value="' . esc_attr($product_id) . '"' . selected(in_array((int) $product_id, $tree_product_ids, true), true, false) . '>';
                echo esc_html($title . ' (ID: ' . $product_id . ')');
                echo '</option>';
            }
            echo '</select>';
            echo '<p class="description">Select zero, one, or many WooCommerce products that should show the Advent Tree purchase fields. Hold Ctrl (Windows) or Command (Mac) to select multiple products.</p>';
        }
        echo '</td></tr>';
        echo '</table>';
        echo '<p><input type="submit" name="qr_tracker_settings_submit" class="button button-primary" value="Save Settings"></p>';
        echo '</form>';

        // Handle orphaned QR codes reassignment
        if (isset($_POST['reassign_orphaned_qr_codes']) && isset($_POST['team_id']) && isset($_POST['qr_code_ids'])) {
            check_admin_referer('qr_tracker_orphaned_qr_codes');
            $team_id = intval($_POST['team_id']);
            $qr_code_ids = array_map('intval', $_POST['qr_code_ids']);
            
            if ($this->teams->reassign_orphaned_qr_codes($team_id, $qr_code_ids)) {
                echo '<div class="updated"><p>Selected QR codes have been reassigned to the team successfully.</p></div>';
            } else {
                echo '<div class="error"><p>Failed to reassign QR codes. Please try again.</p></div>';
            }
        }

        // Display orphaned QR codes section
        $this->display_orphaned_qr_codes_section();
        
        echo '</div>';
    }

    /**
     * Add admin styles for notification badges and orphaned QR codes section
     */
    public function add_admin_styles() {
        echo '<style>
        .update-plugins {
            display: inline-block;
            vertical-align: top;
            box-sizing: border-box;
            height: 17px;
            min-width: 18px;
            padding: 0 5px;
            font-size: 9px;
            line-height: 17px;
            font-weight: 600;
            border-radius: 10px;
            background: #d63638;
            color: #fff;
            text-align: center;
            z-index: 26;
            margin-left: 5px;
        }
        
        .plugin-count {
            color: #fff;
        }
        
        .orphaned-qr-section {
            margin-top: 30px;
            padding: 20px;
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .orphaned-qr-section h2 {
            margin-top: 0;
            color: #d63638;
        }
        
        .orphaned-qr-section .description {
            color: #666;
            font-style: italic;
        }
        </style>';
    }

    /**
     * Display orphaned QR codes section in settings
     */
    private function display_orphaned_qr_codes_section() {
        $orphaned_qr_codes = $this->teams->get_orphaned_qr_codes();
        $accessible_teams = $this->teams->get_accessible_teams();
        
        if (empty($orphaned_qr_codes)) {
            echo '<div class="notice notice-success" style="margin-top: 20px;"><p>All QR codes are properly assigned to teams. No orphaned QR codes found.</p></div>';
            return;
        }
        
        echo '<div class="orphaned-qr-section">';
        echo '<h2>Orphaned QR Codes</h2>';
        echo '<p class="description">The following QR codes are not assigned to any team. Select them and assign them to a team to prevent them from becoming orphaned.</p>';
        
        if (empty($accessible_teams)) {
            echo '<div class="notice notice-warning"><p>No teams available. Please create a team first before reassigning QR codes.</p></div>';
            echo '</div>';
            return;
        }
        
        echo '<form method="post">';
        wp_nonce_field('qr_tracker_orphaned_qr_codes');
        echo '<table class="widefat" style="margin-bottom: 15px;">';
        echo '<thead><tr>';
        echo '<th style="width: 30px;"><input type="checkbox" id="select-all-orphaned" onclick="toggleAllOrphaned(this)"></th>';
        echo '<th>Postcode</th>';
        echo '<th>City</th>';
        echo '<th>Tree</th>';
        echo '<th>Label</th>';
        echo '<th>Reporting ID</th>';
        echo '<th>Scan Count</th>';
        echo '<th>Last Scanned</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($orphaned_qr_codes as $qr_code) {
            echo '<tr>';
            echo '<td><input type="checkbox" name="qr_code_ids[]" value="' . $qr_code->id . '" class="orphaned-qr-checkbox"></td>';
            echo '<td>' . esc_html($qr_code->postcode) . '</td>';
            echo '<td>' . esc_html($qr_code->city) . '</td>';
            echo '<td>' . esc_html($qr_code->tree) . '</td>';
            echo '<td>' . esc_html($qr_code->label) . '</td>';
            echo '<td>' . esc_html($qr_code->reporting_id) . '</td>';
            echo '<td>' . number_format($qr_code->scan_count) . '</td>';
            echo '<td>' . esc_html($qr_code->last_scanned ?: 'Never') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        echo '<div style="display: flex; align-items: center; gap: 15px;">';
        echo '<label for="team_id"><strong>Assign to Team:</strong></label>';
        echo '<select name="team_id" id="team_id" required style="min-width: 200px;">';
        echo '<option value="">Select a team...</option>';
        foreach ($accessible_teams as $team) {
            echo '<option value="' . $team->id . '">' . esc_html($team->name) . ' (' . esc_html($team->city) . ')</option>';
        }
        echo '</select>';
        echo '<input type="submit" name="reassign_orphaned_qr_codes" class="button button-primary" value="Reassign Selected QR Codes" onclick="return confirmReassignment()">';
        echo '</div>';
        echo '</form>';
        
        echo '<script>
        function toggleAllOrphaned(checkbox) {
            var checkboxes = document.querySelectorAll(".orphaned-qr-checkbox");
            checkboxes.forEach(function(cb) {
                cb.checked = checkbox.checked;
            });
        }
        
        function confirmReassignment() {
            var selectedQRCodes = document.querySelectorAll(".orphaned-qr-checkbox:checked");
            var selectedTeam = document.getElementById("team_id").value;
            
            if (selectedQRCodes.length === 0) {
                alert("Please select at least one QR code to reassign.");
                return false;
            }
            
            if (!selectedTeam) {
                alert("Please select a team to assign the QR codes to.");
                return false;
            }
            
            return confirm("Are you sure you want to reassign " + selectedQRCodes.length + " QR code(s) to the selected team?");
        }
        </script>';
        echo '</div>';
    }

    private function display_summary_stats($where_clause, $where_params) {
        global $wpdb;
        
        // Get summary statistics using optimized method
        $stats = QRCodeTracker_Report::get_summary_stats($this->log_table, $this->main_table, $where_clause, $where_params);
        
        if ($stats && $stats->total_scans > 0) {
            echo '<div class="qr-stats-summary">';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->total_scans) . '</div>';
            echo '<div class="qr-stat-label">Total Scans</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->unique_days) . '</div>';
            echo '<div class="qr-stat-label">Active Days</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->unique_postcodes) . '</div>';
            echo '<div class="qr-stat-label">Postcodes</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->unique_trees) . '</div>';
            echo '<div class="qr-stat-label">Trees</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<p><strong>Date Range:</strong> ' . esc_html($stats->first_scan) . ' to ' . esc_html($stats->last_scan) . '</p>';
        } else {
            echo '<div class="notice notice-warning"><p>No scan data found for the selected filters.</p></div>';
        }
    }

    public function display_breakdown_report($where_clause, $where_params) {
        global $wpdb;
        
        $group_field = 'l.postcode';
        $group_label = 'Postcode';
        
        $sql = "SELECT 
                    l.postcode,
                    l.city,
                    l.tree,
                    {$group_field} as group_value,
                    t.reporting_id,
                    COUNT(*) as scan_count,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY {$group_field}, l.postcode, l.city, l.tree, t.reporting_id
                ORDER BY scan_count DESC";
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        echo '<h2>Breakdown Report - Grouped by ' . esc_html($group_label) . '</h2>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?action=qr_tracker_export&export_type=breakdown&group_type=postcode&' . http_build_query(array_diff_key($_GET, ['page' => '', 'view' => ''])))) . '" class="button button-primary">Export CSV</a> ';
        echo '<button onclick="showChart()" class="button">Show Daily Activity Chart</button> ';
        echo '<button onclick="showHourChart()" class="button">Show Hour Distribution Chart</button> ';
        echo '<button onclick="showDayChart()" class="button">Show Day of Week Chart</button></p>';
        
        // Show performance info if data is large
        if ($time_series_data['total_days'] > 100) {
            echo '<div class="notice notice-info"><p><strong>Performance Note:</strong> Large dataset detected (' . $time_series_data['total_days'] . ' days). Chart data has been sampled to ' . count($time_series_data['dates']) . ' points for optimal performance. Export CSV for full data.</p></div>';
        }
        // Add chart containers
        echo '<div id="chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Daily Activity Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'breakdown-chart\', \'daily-activity-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="breakdown-chart" width="800" height="400"></canvas>';
        echo '</div>';
        echo '<div id="hour-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 700px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Hour Distribution Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'hour-chart\', \'hour-distribution-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="hour-chart" width="600" height="600"></canvas>';
        echo '</div>';
        echo '<div id="day-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Day of Week Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'day-chart\', \'day-of-week-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="day-chart" width="800" height="400"></canvas>';
        echo '</div>';
        
        // Get time-series data for chart with performance optimization
        $time_series_data = QRCodeTracker_Report::get_time_series_data($this->log_table, $this->main_table, $where_clause, $where_params, 100);
        
        // Add chart data for JavaScript
        echo '<script>';
        echo 'var chartData = {';
        echo 'labels: [';
        $labels = [];
        $values = [];
        foreach ($results as $row) {
            $labels[] = "'" . esc_js($row->group_value) . "'";
            $values[] = $row->scan_count;
        }
        echo implode(', ', $labels);
        echo '],';
        echo 'values: [' . implode(', ', $values) . ']';
        echo '};';
        
        // Add time series data
        echo 'var timeSeriesData = {';
        echo 'dates: [';
        $date_labels = [];
        foreach ($time_series_data['dates'] as $date) {
            $date_labels[] = "'" . esc_js($date) . "'";
        }
        echo implode(', ', $date_labels);
        echo '],';
        echo 'series: [';
        $series_data = [];
        foreach ($time_series_data['series'] as $series_name => $series_values) {
            $series_data[] = '{name: "' . esc_js($series_name) . '", values: [' . implode(', ', $series_values) . ']}';
        }
        echo implode(', ', $series_data);
        echo ']';
        echo '};';
        echo '</script>';
        echo '<script>
        var chartInstance = null;
        
        window.showChart = function() {
            var container = document.getElementById("chart-container");
            
            if (container.style.display === "none") {
                container.style.display = "block";
                
                // Show loading indicator
                container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
                
                // Add spinner CSS
                if (!document.getElementById(\'chart-spinner-style\')) {
                    var style = document.createElement(\'style\');
                    style.id = \'chart-spinner-style\';
                    style.textContent = \'@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }\';
                    document.head.appendChild(style);
                }
                
                // Use setTimeout to allow UI to update before heavy chart creation
                setTimeout(function() {
                    try {
                        // Destroy existing chart if it exists
                        if (chartInstance) {
                            chartInstance.destroy();
                        }
                
                // Prepare data for Chart.js
                var datasets = [];
                var colors = [
                    "#FF6384", "#36A2EB", "#FFCE56", "#4BC0C0", "#9966FF", 
                    "#FF9F40", "#FF6384", "#C9CBCF", "#4BC0C0", "#FF6384",
                    "#FF6384", "#36A2EB", "#FFCE56", "#4BC0C0", "#9966FF"
                ];
                
                for (var i = 0; i < timeSeriesData.series.length; i++) {
                    var series = timeSeriesData.series[i];
                    var colorIndex = i % colors.length;
                    var baseColor = colors[colorIndex];
                    
                    // Create a slightly transparent version for background
                    var backgroundColor = baseColor + "20";
                    
                    datasets.push({
                        label: series.name,
                        data: series.values,
                        borderColor: baseColor,
                        backgroundColor: backgroundColor,
                        borderWidth: 3,
                        fill: false,
                        tension: 0.1,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: baseColor,
                        pointBorderColor: "#ffffff",
                        pointBorderWidth: 2
                    });
                }
                
                // Simplified animation for better performance
                var animation = {
                    duration: 1000,
                    easing: \'easeOutQuart\'
                };
                
                // Restore canvas element
                container.innerHTML = \'<canvas id="breakdown-chart" width="800" height="400"></canvas>\';
                var ctx = document.getElementById("breakdown-chart").getContext("2d");
                chartInstance = new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: timeSeriesData.dates.map(function(date) {
                            var d = new Date(date);
                            return (d.getMonth() + 1) + "/" + d.getDate();
                        }),
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: animation,
                        elements: {
                            point: {
                                radius: timeSeriesData.dates.length > 50 ? 2 : 4,
                                hoverRadius: timeSeriesData.dates.length > 50 ? 3 : 6
                            },
                            line: {
                                tension: 0.1
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: "Daily Scan Activity Over Time"
                            },
                            legend: {
                                display: true,
                                position: "top",
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 6
                                }
                            },
                            tooltip: {
                                mode: "index",
                                intersect: false,
                                enabled: timeSeriesData.dates.length <= 100
                            }
                        },
                        scales: {
                            x: {
                                display: true,
                                title: {
                                    display: true,
                                    text: "Date"
                                },
                                grid: {
                                    display: true
                                }
                            },
                            y: {
                                display: true,
                                title: {
                                    display: true,
                                    text: "Number of Scans"
                                },
                                grid: {
                                    display: true
                                },
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1,
                                    precision: 0
                                }
                            }
                        },
                        interaction: {
                            mode: "nearest",
                            axis: "x",
                            intersect: false
                        }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
            } else {
                container.style.display = "none";
            }
        }
        </script>';
        
        // Add scan hour data for JS with performance optimization
        $hour_series_data = QRCodeTracker_Report::get_scan_hour_series_data($this->log_table, $this->main_table, $where_clause, $where_params, 10);
        
        // Add day of week data for JS
        $day_data = QRCodeTracker_Report::get_scan_day_of_week_data($this->log_table, $this->main_table, $where_clause, $where_params);
        $series_names = array_keys($hour_series_data);
        $series_colors = [
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40',
            '#C9CBCF', '#4BC0C0', '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
            '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384',
            '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384'
        ];
        echo '<script>';
        echo 'var hourChartData = {';
        echo 'labels: [';
        $hour_labels = [];
        for ($h = 0; $h < 24; $h++) {
            $hour_labels[] = "'" . sprintf('%02d:00', $h) . "'";
        }
        echo implode(', ', $hour_labels);
        echo '], datasets: [';
        $dataset_js = [];
        foreach ($series_names as $i => $series) {
            $color = $series_colors[$i % count($series_colors)];
            $data = $hour_series_data[$series];
            $dataset_js[] = '{label: "' . addslashes($series) . '", data: [' . implode(',', $data) . '], fill: false, borderColor: "' . $color . '", backgroundColor: "' . $color . '", pointBackgroundColor: "' . $color . '", pointBorderColor: "#fff", pointHoverBackgroundColor: "#fff", pointHoverBorderColor: "' . $color . '"}';
        }
        echo implode(',', $dataset_js);
        echo ']};';
        
        // Add day of week chart data
        echo 'var dayChartData = {';
        echo 'labels: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],';
        echo 'values: [' . implode(',', array_values($day_data)) . ']';
        echo '};';
        
        echo '
var hourChartInstance = null;
var dayChartInstance = null;
window.showHourChart = function() {
    var container = document.getElementById("hour-chart-container");
    if (container.style.display === "none") {
        container.style.display = "block";
        
        // Show loading indicator
        container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
        
        // Use setTimeout to allow UI to update before heavy chart creation
        setTimeout(function() {
            try {
                if (hourChartInstance) { hourChartInstance.destroy(); }
                
                // Restore canvas element
                container.innerHTML = \'<canvas id="hour-chart" width="600" height="600"></canvas>\';
                var ctx = document.getElementById("hour-chart").getContext("2d");
                hourChartInstance = new Chart(ctx, {
                    type: "radar",
                    data: {
                        labels: hourChartData.labels,
                        datasets: hourChartData.datasets
                    },
                    options: {
                        responsive: true,
                        animation: {
                            duration: 1000,
                            easing: \'easeOutQuart\'
                        },
                        plugins: {
                            legend: { 
                                position: "top",
                                labels: {
                                    usePointStyle: true,
                                    boxWidth: 6
                                }
                            },
                            title: { display: true, text: "Scan Distribution by Hour of Day (Radar/Clock)" }
                        },
                        scales: {
                            r: {
                                beginAtZero: true,
                                min: 0,
                                max: Math.max.apply(null, [].concat.apply([], hourChartData.datasets.map(function(ds){return ds.data;}))) + 1,
                                ticks: { stepSize: 1, precision: 0 },
                                pointLabels: { font: { size: 14 } }
                            }
                        }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
    } else {
        container.style.display = "none";
    }
}

window.showDayChart = function() {
    var container = document.getElementById("day-chart-container");
    if (container.style.display === "none") {
        container.style.display = "block";
        
        // Show loading indicator
        container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
        
        // Use setTimeout to allow UI to update before heavy chart creation
        setTimeout(function() {
            try {
                if (dayChartInstance) { dayChartInstance.destroy(); }
                
                // Restore canvas element
                container.innerHTML = \'<canvas id="day-chart" width="800" height="400"></canvas>\';
                var ctx = document.getElementById("day-chart").getContext("2d");
                dayChartInstance = new Chart(ctx, {
                    type: "bar",
                    data: {
                        labels: dayChartData.labels,
                        datasets: [{
                            label: "Scan Distribution by Day of Week",
                            data: dayChartData.values,
                            backgroundColor: "#36A2EB",
                            borderColor: "#36A2EB",
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 1000,
                            easing: \'easeOutQuart\'
                        },
                        plugins: {
                            title: { display: true, text: "Scan Distribution by Day of Week" }
                        },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                ticks: { stepSize: 1, precision: 0 } 
                            }
                        }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
    } else {
        container.style.display = "none";
    }
}
';
        echo '</script>';
        
        // Add chart export functionality
        echo '<script>
        function exportChartAsImage(canvasId, filename) {
            try {
                const canvas = document.getElementById(canvasId);
                if (!canvas) {
                    alert("Chart not found. Please make sure the chart is displayed first.");
                    return;
                }
                
                // Create a temporary canvas with higher resolution for better quality
                const tempCanvas = document.createElement("canvas");
                const ctx = tempCanvas.getContext("2d");
                
                // Set higher resolution (2x for better quality)
                const scale = 2;
                tempCanvas.width = canvas.width * scale;
                tempCanvas.height = canvas.height * scale;
                
                // Scale the context to ensure correct drawing
                ctx.scale(scale, scale);
                
                // Fill the canvas with white background
                ctx.fillStyle = "#ffffff";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                // Draw the original canvas content to the temp canvas
                ctx.drawImage(canvas, 0, 0);
                
                // Convert to blob and download
                tempCanvas.toBlob(function(blob) {
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement("a");
                    a.style.display = "none";
                    a.href = url;
                    a.download = filename + "_" + new Date().toISOString().slice(0, 10) + ".png";
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                }, "image/png");
                
            } catch (error) {
                console.error("Error exporting chart:", error);
                alert("Error exporting chart. Please try again.");
            }
        }
        </script>';
        
        echo '<table class="widefat"><thead><tr><th>Postcode</th><th>City</th><th>Tree</th><th>Reporting ID</th><th>' . esc_html($group_label) . '</th><th>Total Scans</th><th>Unique Days</th><th>First Scan</th><th>Last Scan</th></tr></thead><tbody>';
        
        $total_scans = 0;
        $total_unique_days = 0;
        
        foreach ($results as $row) {
            echo "<tr>";
            echo "<td>" . esc_html($row->postcode) . "</td>";
            echo "<td><a href='" . admin_url('admin.php?page=qr-city-report&city=' . urlencode($row->city)) . "'>" . esc_html($row->city) . "</a></td>";
            echo "<td>" . esc_html($row->tree) . "</td>";
            echo "<td><a href='" . admin_url('admin.php?page=qr-reporting-id-report&reporting_id=' . urlencode($row->reporting_id)) . "'>" . esc_html($row->reporting_id) . "</a></td>";
            echo "<td>" . esc_html($row->group_value) . "</td>";
            echo "<td>" . number_format($row->scan_count) . "</td>";
            echo "<td>" . number_format($row->unique_days) . "</td>";
            echo "<td>" . esc_html($row->first_scan) . "</td>";
            echo "<td>" . esc_html($row->last_scan) . "</td>";
            echo "</tr>";
            
            $total_scans += $row->scan_count;
            $total_unique_days += $row->unique_days;
        }
        
        echo "<tr style='font-weight: bold; background-color: #f9f9f9;'>";
        echo "<td colspan='5'><strong>Total</strong></td>";
        echo "<td><strong>" . number_format($total_scans) . "</strong></td>";
        echo "<td><strong>" . number_format($total_unique_days) . "</strong></td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";
        echo '</tbody></table>';
    }

    public function display_rollup_report($where_clause, $where_params) {
        global $wpdb;
        
        $group_field = 'l.postcode';
        $other_field = 'l.tree';
        $group_label = 'Postcode';
        $other_label = 'Tree';
        
        $sql = "SELECT 
                    {$group_field} as group_value,
                    {$other_field} as other_value,
                    l.city,
                    t.reporting_id,
                    COUNT(*) as scan_count,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY {$group_field}, {$other_field}, l.city, t.reporting_id
                ORDER BY {$group_field}, scan_count DESC";
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        echo '<h2>Rollup Report - ' . esc_html($group_label) . ' and ' . esc_html($other_label) . '</h2>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?action=qr_tracker_export&export_type=rollup&group_type=postcode&' . http_build_query(array_diff_key($_GET, ['page' => '', 'view' => ''])))) . '" class="button button-primary">Export CSV</a> ';
        echo '<button onclick="showRollupChart()" class="button">Show Daily Activity Chart</button> ';
        echo '<button onclick="showRollupHourChart()" class="button">Show Hour Distribution Chart</button> ';
        echo '<button onclick="showRollupDayChart()" class="button">Show Day of Week Chart</button></p>';
        
        // Add chart containers
        echo '<div id="rollup-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Daily Activity Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'rollup-chart\', \'rollup-daily-activity-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="rollup-chart" width="800" height="400"></canvas>';
        echo '</div>';
        echo '<div id="rollup-hour-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 700px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Hour Distribution Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'rollup-hour-chart\', \'rollup-hour-distribution-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="rollup-hour-chart" width="600" height="600"></canvas>';
        echo '</div>';
        echo '<div id="rollup-day-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Day of Week Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'rollup-day-chart\', \'rollup-day-of-week-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="rollup-day-chart" width="800" height="400"></canvas>';
        echo '</div>';
        
        // Get time-series data for chart with performance optimization
        $time_series_data = QRCodeTracker_Report::get_time_series_data($this->log_table, $this->main_table, $where_clause, $where_params, 100);
        
        // Add scan hour data for JS with performance optimization
        $hour_series_data = QRCodeTracker_Report::get_scan_hour_series_data($this->log_table, $this->main_table, $where_clause, $where_params, 10);
        
        // Add day of week data for JS
        $day_data = QRCodeTracker_Report::get_scan_day_of_week_data($this->log_table, $this->main_table, $where_clause, $where_params);
        
        // Add chart data for JavaScript
        echo '<script>';
        echo 'var rollupTimeSeriesData = {';
        echo 'dates: [';
        $date_labels = [];
        foreach ($time_series_data['dates'] as $date) {
            $date_labels[] = "'" . esc_js($date) . "'";
        }
        echo implode(', ', $date_labels);
        echo '],';
        echo 'series: [';
        $series_data = [];
        foreach ($time_series_data['series'] as $series_name => $series_values) {
            $series_data[] = '{name: "' . esc_js($series_name) . '", values: [' . implode(', ', $series_values) . ']}';
        }
        echo implode(', ', $series_data);
        echo ']';
        echo '};';
        
        // Add hour chart data
        $series_names = array_keys($hour_series_data);
        $series_colors = [
            '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40',
            '#C9CBCF', '#4BC0C0', '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
            '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF', '#4BC0C0', '#FF6384',
            '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384'
        ];
        echo 'var rollupHourChartData = {';
        echo 'labels: [';
        $hour_labels = [];
        for ($h = 0; $h < 24; $h++) {
            $hour_labels[] = "'" . sprintf('%02d:00', $h) . "'";
        }
        echo implode(', ', $hour_labels);
        echo '], datasets: [';
        $dataset_js = [];
        foreach ($series_names as $i => $series) {
            $color = $series_colors[$i % count($series_colors)];
            $data = $hour_series_data[$series];
            $dataset_js[] = '{label: "' . addslashes($series) . '", data: [' . implode(',', $data) . '], fill: false, borderColor: "' . $color . '", backgroundColor: "' . $color . '", pointBackgroundColor: "' . $color . '", pointBorderColor: "#fff", pointHoverBackgroundColor: "#fff", pointHoverBorderColor: "' . $color . '"}';
        }
        echo implode(',', $dataset_js);
        echo ']};';
        
        // Add day of week chart data
        echo 'var rollupDayChartData = {';
        echo 'labels: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],';
        echo 'values: [' . implode(',', array_values($day_data)) . ']';
        echo '};';
        
        echo '
var rollupChartInstance = null;
var rollupHourChartInstance = null;
var rollupDayChartInstance = null;

window.showRollupChart = function() {
    var container = document.getElementById("rollup-chart-container");
    if (container.style.display === "none") {
        container.style.display = "block";
        container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
        
        setTimeout(function() {
            try {
                if (rollupChartInstance) { rollupChartInstance.destroy(); }
                
                var datasets = [];
                var colors = [
                    "#FF6384", "#36A2EB", "#FFCE56", "#4BC0C0", "#9966FF", 
                    "#FF9F40", "#FF6384", "#C9CBCF", "#4BC0C0", "#FF6384",
                    "#FF6384", "#36A2EB", "#FFCE56", "#4BC0C0", "#9966FF"
                ];
                
                for (var i = 0; i < rollupTimeSeriesData.series.length; i++) {
                    var series = rollupTimeSeriesData.series[i];
                    var colorIndex = i % colors.length;
                    var baseColor = colors[colorIndex];
                    var backgroundColor = baseColor + "20";
                    
                    datasets.push({
                        label: series.name,
                        data: series.values,
                        borderColor: baseColor,
                        backgroundColor: backgroundColor,
                        borderWidth: 3,
                        fill: false,
                        tension: 0.1,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: baseColor,
                        pointBorderColor: "#ffffff",
                        pointBorderWidth: 2
                    });
                }
                
                container.innerHTML = \'<canvas id="rollup-chart" width="800" height="400"></canvas>\';
                var ctx = document.getElementById("rollup-chart").getContext("2d");
                rollupChartInstance = new Chart(ctx, {
                    type: "line",
                    data: {
                        labels: rollupTimeSeriesData.dates.map(function(date) {
                            var d = new Date(date);
                            return (d.getMonth() + 1) + "/" + d.getDate();
                        }),
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 1000, easing: \'easeOutQuart\' },
                        elements: {
                            point: { radius: rollupTimeSeriesData.dates.length > 50 ? 2 : 4, hoverRadius: rollupTimeSeriesData.dates.length > 50 ? 3 : 6 },
                            line: { tension: 0.1 }
                        },
                        plugins: {
                            title: { display: true, text: "Daily Scan Activity Over Time" },
                            legend: { display: true, position: "top", labels: { usePointStyle: true, boxWidth: 6 } },
                            tooltip: { mode: "index", intersect: false, enabled: rollupTimeSeriesData.dates.length <= 100 }
                        },
                        scales: {
                            x: { display: true, title: { display: true, text: "Date" }, grid: { display: true } },
                            y: { display: true, title: { display: true, text: "Number of Scans" }, grid: { display: true }, beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                        },
                        interaction: { mode: "nearest", axis: "x", intersect: false }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
    } else {
        container.style.display = "none";
    }
}

window.showRollupHourChart = function() {
    var container = document.getElementById("rollup-hour-chart-container");
    if (container.style.display === "none") {
        container.style.display = "block";
        container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
        
        setTimeout(function() {
            try {
                if (rollupHourChartInstance) { rollupHourChartInstance.destroy(); }
                
                container.innerHTML = \'<canvas id="rollup-hour-chart" width="600" height="600"></canvas>\';
                var ctx = document.getElementById("rollup-hour-chart").getContext("2d");
                rollupHourChartInstance = new Chart(ctx, {
                    type: "radar",
                    data: {
                        labels: rollupHourChartData.labels,
                        datasets: rollupHourChartData.datasets
                    },
                    options: {
                        responsive: true,
                        animation: { duration: 1000, easing: \'easeOutQuart\' },
                        plugins: {
                            legend: { position: "top", labels: { usePointStyle: true, boxWidth: 6 } },
                            title: { display: true, text: "Scan Distribution by Hour of Day (Radar/Clock)" }
                        },
                        scales: {
                            r: {
                                beginAtZero: true,
                                min: 0,
                                max: Math.max.apply(null, [].concat.apply([], rollupHourChartData.datasets.map(function(ds){return ds.data;}))) + 1,
                                ticks: { stepSize: 1, precision: 0 },
                                pointLabels: { font: { size: 14 } }
                            }
                        }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
    } else {
        container.style.display = "none";
    }
}

window.showRollupDayChart = function() {
    var container = document.getElementById("rollup-day-chart-container");
    if (container.style.display === "none") {
        container.style.display = "block";
        container.innerHTML = \'<div style="text-align: center; padding: 40px;"><div class="spinner" style="border: 4px solid #f3f3f3; border-top: 4px solid #0073aa; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div><p>Loading chart...</p></div>\';
        
        setTimeout(function() {
            try {
                if (rollupDayChartInstance) { rollupDayChartInstance.destroy(); }
                
                container.innerHTML = \'<canvas id="rollup-day-chart" width="800" height="400"></canvas>\';
                var ctx = document.getElementById("rollup-day-chart").getContext("2d");
                rollupDayChartInstance = new Chart(ctx, {
                    type: "bar",
                    data: {
                        labels: rollupDayChartData.labels,
                        datasets: [{
                            label: "Scan Distribution by Day of Week",
                            data: rollupDayChartData.values,
                            backgroundColor: "#36A2EB",
                            borderColor: "#36A2EB",
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 1000, easing: \'easeOutQuart\' },
                        plugins: { title: { display: true, text: "Scan Distribution by Day of Week" } },
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } } }
                    }
                });
            } catch (error) {
                container.innerHTML = \'<div style="text-align: center; padding: 40px; color: #d63638;"><p><strong>Error loading chart:</strong> \' + error.message + \'</p><p>Try refreshing the page or reducing the date range.</p></div>\';
            }
        }, 100);
    } else {
        container.style.display = "none";
    }
}
';
        echo '</script>';
        
        echo '<table class="widefat"><thead><tr><th>' . esc_html($group_label) . '</th><th>' . esc_html($other_label) . '</th><th>City</th><th>Reporting ID</th><th>Total Scans</th><th>Unique Days</th><th>First Scan</th><th>Last Scan</th></tr></thead><tbody>';
        
        $current_group = '';
        $group_total = 0;
        $total_scans = 0;
        $total_unique_days = 0;
        
        foreach ($results as $row) {
            if ($current_group != $row->group_value) {
                if ($current_group != '') {
                    echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
                    echo "<td><strong>" . esc_html($current_group) . " Total</strong></td>";
                    echo "<td></td>";
                    echo "<td></td>";
                    echo "<td><strong>" . number_format($group_total) . "</strong></td>";
                    echo "<td colspan='4'></td>";
                    echo "</tr>";
                }
                $current_group = $row->group_value;
                $group_total = 0;
            }
            echo "<tr>";
            echo "<td>" . esc_html($row->group_value) . "</td>";
            echo "<td>" . esc_html($row->other_value) . "</td>";
            echo "<td><a href='" . admin_url('admin.php?page=qr-city-report&city=' . urlencode($row->city)) . "'>" . esc_html($row->city) . "</a></td>";
            echo "<td><a href='" . admin_url('admin.php?page=qr-reporting-id-report&reporting_id=' . urlencode($row->reporting_id)) . "'>" . esc_html($row->reporting_id) . "</a></td>";
            echo "<td>" . number_format($row->scan_count) . "</td>";
            echo "<td>" . number_format($row->unique_days) . "</td>";
            echo "<td>" . esc_html($row->first_scan) . "</td>";
            echo "<td>" . esc_html($row->last_scan) . "</td>";
            echo "</tr>";
            $group_total += $row->scan_count;
            $total_scans += $row->scan_count;
            $total_unique_days += $row->unique_days;
        }
        
        if ($current_group != '') {
            echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
            echo "<td><strong>" . esc_html($current_group) . " Total</strong></td>";
            echo "<td></td>";
            echo "<td></td>";
            echo "<td><strong>" . number_format($group_total) . "</strong></td>";
            echo "<td colspan='4'></td>";
            echo "</tr>";
        }
        
        echo "<tr style='font-weight: bold; background-color: #e0e0e0;'>";
        echo "<td><strong>Grand Total</strong></td>";
        echo "<td></td>";
        echo "<td></td>";
        echo "<td></td>";
        echo "<td><strong>" . number_format($total_scans) . "</strong></td>";
        echo "<td><strong>" . number_format($total_unique_days) . "</strong></td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";
        echo '</tbody></table>';
    }

    /**
     * Hide hidden menu items from the admin menu
     */
    public function hide_hidden_menu_items() {
        echo '<style>
        /* Hide hidden QR tracker pages from menu */
        #adminmenu a[href*="page=qr-single-report"],
        #adminmenu a[href*="page=qr-city-report"],
        #adminmenu a[href*="page=qr-reporting-id-report"] {
            display: none !important;
        }
        </style>';
    }

    /**
     * Display single QR code report page
     */
    public function single_report_page() {
        // Include the single report class
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-single-report.php';
        
        // Create instance and display the page
        $single_report = new QRCodeTracker_SingleReport($this->tracker, $this->teams);
        $single_report->display_single_report_page();
    }

    /**
     * Display city QR code report page
     */
    public function city_report_page() {
        // Include the city report class
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-city-report.php';
        
        // Create instance and display the page
        $city_report = new QRCodeTracker_CityReport($this->tracker, $this->teams);
        $city_report->display_city_report_page();
    }

    /**
     * Display reporting ID QR code report page
     */
    public function reporting_id_report_page() {
        // Include the reporting ID report class
        require_once plugin_dir_path(__FILE__) . 'class-qr-code-reporting-id-report.php';
        
        // Create instance and display the page
        $reporting_id_report = new QRCodeTracker_ReportingIDReport($this->tracker, $this->teams);
        $reporting_id_report->display_reporting_id_report_page();
    }

    /**
     * Display teams management page
     */
    public function teams_page() {
        // Check permissions
        if (!QRCodeTracker_Permissions::can_view_teams()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_view_teams');
            return;
        }
        
        global $wpdb;
        
        echo '<div class="wrap"><h1>QR Tracker Teams</h1>';
        
        // Handle form submissions
        if (isset($_POST['create_team'])) {
            // Check create team permission
            if (!QRCodeTracker_Permissions::can_create_teams()) {
                echo '<div class="error"><p>You do not have permission to create teams.</p></div>';
                return;
            }
            $name = sanitize_text_field($_POST['team_name']);
            $description = sanitize_textarea_field($_POST['team_description']);
            $city = sanitize_text_field($_POST['team_city']);
            
            if (!empty($name)) {
                $team_id = $this->teams->create_team($name, $description, $city);
                if ($team_id) {
                    echo '<div class="updated"><p>Team created successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error creating team.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Team name is required.</p></div>';
            }
        }
        
        if (isset($_POST['update_team'])) {
            $team_id = intval($_POST['team_id']);
            $name = sanitize_text_field($_POST['team_name']);
            $description = sanitize_textarea_field($_POST['team_description']);
            $city = sanitize_text_field($_POST['team_city']);
            
            if (!empty($name) && $this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                $result = $this->teams->update_team($team_id, $name, $description, $city);
                if ($result !== false) {
                    echo '<div class="updated"><p>Team updated successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error updating team.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Team name is required or you don\'t have permission to edit this team.</p></div>';
            }
        }
        
        if (isset($_POST['delete_team'])) {
            // Check delete team permission
            if (!QRCodeTracker_Permissions::can_delete_teams()) {
                echo '<div class="error"><p>You do not have permission to delete teams.</p></div>';
                return;
            }
            
            $team_id = intval($_POST['team_id']);
            
            if ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                $result = $this->teams->delete_team($team_id);
                if ($result !== false) {
                    echo '<div class="updated"><p>Team deleted successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error deleting team.</p></div>';
                }
            } else {
                echo '<div class="error"><p>You don\'t have permission to delete this team.</p></div>';
            }
        }
        
        if (isset($_POST['add_user_to_team'])) {
            // Check assign users permission
            if (!QRCodeTracker_Permissions::can_assign_users_to_teams()) {
                echo '<div class="error"><p>You do not have permission to assign users to teams.</p></div>';
                return;
            }
            
            $team_id = intval($_POST['team_id']);
            $user_id = intval($_POST['user_id']);
            $role = sanitize_text_field($_POST['user_role']);
            
            if ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                $result = $this->teams->add_user_to_team($user_id, $team_id, $role);
                if ($result !== false) {
                    echo '<div class="updated"><p>User added to team successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error adding user to team.</p></div>';
                }
            } else {
                echo '<div class="error"><p>You don\'t have permission to manage this team.</p></div>';
            }
        }
        
        if (isset($_POST['remove_user_from_team'])) {
            // Check remove users permission
            if (!QRCodeTracker_Permissions::can_remove_users_from_teams()) {
                echo '<div class="error"><p>You do not have permission to remove users from teams.</p></div>';
                return;
            }
            
            $team_id = intval($_POST['team_id']);
            $user_id = intval($_POST['user_id']);
            
            if ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                $result = $this->teams->remove_user_from_team($user_id, $team_id);
                if ($result !== false) {
                    echo '<div class="updated"><p>User removed from team successfully.</p></div>';
                } else {
                    echo '<div class="error"><p>Error removing user from team.</p></div>';
                }
            } else {
                echo '<div class="error"><p>You don\'t have permission to manage this team.</p></div>';
            }
        }

        if (isset($_POST['leave_team'])) {
            $team_id = intval($_POST['team_id']);
            $user_id = get_current_user_id();
            
            $result = $this->teams->leave_team($user_id, $team_id);
            if ($result['success']) {
                echo '<div class="updated"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }

        if (isset($_POST['transfer_admin_role'])) {
            $team_id = intval($_POST['team_id']);
            $new_admin_id = intval($_POST['new_admin_id']);
            $current_admin_id = get_current_user_id();
            
            $result = $this->teams->transfer_admin_role($current_admin_id, $new_admin_id, $team_id);
            if ($result['success']) {
                echo '<div class="updated"><p>' . esc_html($result['message']) . '</p></div>';
            } else {
                echo '<div class="error"><p>' . esc_html($result['message']) . '</p></div>';
            }
        }
        
        // Display create team form
        echo '<h2>Create New Team</h2>';
        echo '<form method="post" style="max-width: 600px; margin-bottom: 30px;">';
        echo '<table class="form-table">';
        echo '<tr><th><label for="team_name">Team Name:</label></th><td><input type="text" name="team_name" id="team_name" required style="width: 100%;"></td></tr>';
        echo '<tr><th><label for="team_description">Description:</label></th><td><textarea name="team_description" id="team_description" rows="3" style="width: 100%;"></textarea></td></tr>';
        echo '<tr><th><label for="team_city">City/Town:</label></th><td><input type="text" name="team_city" id="team_city" style="width: 100%;"></td></tr>';
        echo '</table>';
        echo '<p><input type="submit" name="create_team" class="button button-primary" value="Create Team"></p>';
        echo '</form>';
        
        // Display teams list
        $teams = $this->teams->get_all_teams_stats();
        echo '<h2>Teams Overview</h2>';
        echo '<table class="widefat"><thead><tr><th>Team Name</th><th>City/Town</th><th>QR Codes</th><th>Total Scans</th><th>Active QR Codes</th><th>Members</th><th>Actions</th></tr></thead><tbody>';
        
        foreach ($teams as $team) {
            $edit_url = esc_url(add_query_arg(['edit_team' => $team->id]));
            $manage_url = esc_url(add_query_arg(['manage_team' => $team->id]));
            
            echo "<tr>";
            echo "<td><strong>" . esc_html($team->name) . "</strong></td>";
            echo "<td>" . esc_html($team->city) . "</td>";
            echo "<td>" . number_format($team->total_qr_codes) . "</td>";
            echo "<td>" . number_format($team->total_scans) . "</td>";
            echo "<td>" . number_format($team->active_qr_codes) . "</td>";
            echo "<td>" . number_format($team->member_count) . "</td>";
            echo "<td>";
            
            if ($this->teams->user_can_manage_team(get_current_user_id(), $team->id)) {
                echo "<a href='$edit_url' class='button button-secondary'>Edit</a> ";
                if (QRCodeTracker_Permissions::can_view_team_members()) {
                    echo "<a href='$manage_url' class='button button-secondary'>Manage Members</a> ";
                }
                
                if ($team->name !== 'Default Team') {
                    echo "<form method='post' style='display: inline;' onsubmit='return confirm(\"Are you sure you want to delete this team? All QR codes will be moved to the Default Team.\");'>";
                    echo "<input type='hidden' name='team_id' value='{$team->id}'>";
                    echo "<input type='submit' name='delete_team' class='button button-link-delete' value='Delete'>";
                    echo "</form>";
                }
            } elseif ($this->teams->user_can_access_team(get_current_user_id(), $team->id)) {
                if (QRCodeTracker_Permissions::can_view_team_members()) {
                    echo "<a href='$manage_url' class='button button-secondary'>View Members</a> ";
                }
                
                // Add leave team button for team members
                echo "<form method='post' style='display: inline;' onsubmit='return confirm(\"Are you sure you want to leave this team?\");'>";
                echo "<input type='hidden' name='team_id' value='{$team->id}'>";
                echo "<input type='submit' name='leave_team' class='button button-link-delete' value='Leave Team'>";
                echo "</form>";
            } else {
                if (QRCodeTracker_Permissions::can_view_team_members()) {
                    echo "<a href='$manage_url' class='button button-secondary'>View Members</a>";
                }
            }
            
            echo "</td></tr>";
        }
        
        echo '</tbody></table>';
        
        // Handle team editing
        if (isset($_GET['edit_team'])) {
            $team_id = intval($_GET['edit_team']);
            $team = $this->teams->get_team($team_id);
            
            if ($team && $this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                echo '<h2>Edit Team: ' . esc_html($team->name) . '</h2>';
                echo '<form method="post" style="max-width: 600px;">';
                echo '<input type="hidden" name="team_id" value="' . $team_id . '">';
                echo '<table class="form-table">';
                echo '<tr><th><label for="team_name">Team Name:</label></th><td><input type="text" name="team_name" id="team_name" value="' . esc_attr($team->name) . '" required style="width: 100%;"></td></tr>';
                echo '<tr><th><label for="team_description">Description:</label></th><td><textarea name="team_description" id="team_description" rows="3" style="width: 100%;">' . esc_textarea($team->description) . '</textarea></td></tr>';
                echo '<tr><th><label for="team_city">City/Town:</label></th><td><input type="text" name="team_city" id="team_city" value="' . esc_attr($team->city) . '" style="width: 100%;"></td></tr>';
                echo '</table>';
                echo '<p><input type="submit" name="update_team" class="button button-primary" value="Update Team"></p>';
                echo '</form>';
            }
        }
        
        // Handle team member management
        if (isset($_GET['manage_team'])) {
            // Check permission to view team members
            if (!QRCodeTracker_Permissions::can_view_team_members()) {
                echo '<div class="error"><p>You do not have permission to view team members.</p></div>';
                return;
            }
            
            $team_id = intval($_GET['manage_team']);
            $team = $this->teams->get_team($team_id);
            
            if ($team && $this->teams->user_can_access_team(get_current_user_id(), $team_id)) {
                echo '<h2>Manage Team: ' . esc_html($team->name) . '</h2>';
                
                // Display current members
                $members = $this->teams->get_team_members($team_id);
                echo '<h3>Current Members</h3>';
                echo '<table class="widefat"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Joined</th><th>Actions</th></tr></thead><tbody>';
                
                foreach ($members as $member) {
                    echo "<tr>";
                    echo "<td>" . esc_html($member->display_name) . " (" . esc_html($member->user_login) . ")</td>";
                    echo "<td>" . esc_html($member->user_email) . "</td>";
                    echo "<td>" . esc_html(ucfirst($member->role)) . "</td>";
                    echo "<td>" . esc_html($member->created_at) . "</td>";
                    echo "<td>";
                    
                    // Show different actions based on user role and permissions
                    if ($member->ID == get_current_user_id()) {
                        // Current user - show leave team option
                        echo "<form method='post' style='display: inline;' onsubmit='return confirm(\"Are you sure you want to leave this team?\");'>";
                        echo "<input type='hidden' name='team_id' value='{$team_id}'>";
                        echo "<input type='submit' name='leave_team' class='button button-link-delete' value='Leave Team'>";
                        echo "</form>";
                    } elseif ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                        // Team admin can remove other members
                        echo "<form method='post' style='display: inline;' onsubmit='return confirm(\"Are you sure you want to remove this user from the team?\");'>";
                        echo "<input type='hidden' name='team_id' value='{$team_id}'>";
                        echo "<input type='hidden' name='user_id' value='{$member->ID}'>";
                        echo "<input type='submit' name='remove_user_from_team' class='button button-link-delete' value='Remove'>";
                        echo "</form>";
                    }
                    
                    echo "</td></tr>";
                }
                
                echo '</tbody></table>';
                
                // Transfer admin role form (for current admin)
                if ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                    $current_user_role = null;
                    foreach ($members as $member) {
                        if ($member->ID == get_current_user_id()) {
                            $current_user_role = $member->role;
                            break;
                        }
                    }
                    
                    if ($current_user_role === 'admin') {
                        $admin_count = 0;
                        foreach ($members as $member) {
                            if ($member->role === 'admin') {
                                $admin_count++;
                            }
                        }
                        
                        if ($admin_count > 1) {
                            echo '<h3>Transfer Admin Role</h3>';
                            echo '<p class="description">Transfer your admin role to another team member before leaving the team.</p>';
                            echo '<form method="post" style="max-width: 600px;">';
                            echo '<input type="hidden" name="team_id" value="' . $team_id . '">';
                            echo '<table class="form-table">';
                            echo '<tr><th><label for="new_admin_id">Transfer Admin Role to:</label></th><td>';
                            echo '<select name="new_admin_id" id="new_admin_id" required style="width: 100%;">';
                            echo '<option value="">Select a team member...</option>';
                            
                            foreach ($members as $member) {
                                if ($member->ID != get_current_user_id() && $member->role === 'member') {
                                    echo '<option value="' . $member->ID . '">' . esc_html($member->display_name) . ' (' . esc_html($member->user_login) . ')</option>';
                                }
                            }
                            
                            echo '</select></td></tr>';
                            echo '</table>';
                            echo '<p><input type="submit" name="transfer_admin_role" class="button button-secondary" value="Transfer Admin Role" onclick="return confirm(\"Are you sure you want to transfer your admin role? You will become a regular member.\");"></p>';
                            echo '</form>';
                        } else {
                            echo '<div class="notice notice-warning"><p><strong>Note:</strong> You are the only admin of this team. You must assign another member as admin before you can leave the team.</p></div>';
                        }
                    }
                }
                
                // Add new member form
                if ($this->teams->user_can_manage_team(get_current_user_id(), $team_id)) {
                    echo '<h3>Add New Member</h3>';
                    echo '<form method="post" style="max-width: 600px;">';
                    echo '<input type="hidden" name="team_id" value="' . $team_id . '">';
                    echo '<table class="form-table">';
                    echo '<tr><th><label for="user_id">User:</label></th><td>';
                    echo '<select name="user_id" id="user_id" required style="width: 100%;">';
                    echo '<option value="">Select a user...</option>';
                    
                    $available_users = $this->teams->get_users_not_in_team($team_id);
                    foreach ($available_users as $user) {
                        echo '<option value="' . $user->ID . '">' . esc_html($user->display_name) . ' (' . esc_html($user->user_login) . ')</option>';
                    }
                    
                    echo '</select></td></tr>';
                    echo '<tr><th><label for="user_role">Role:</label></th><td>';
                    echo '<select name="user_role" id="user_role" required style="width: 100%;">';
                    echo '<option value="member">Member</option>';
                    echo '<option value="admin">Admin</option>';
                    echo '</select></td></tr>';
                    echo '</table>';
                    echo '<p><input type="submit" name="add_user_to_team" class="button button-primary" value="Add Member"></p>';
                    echo '</form>';
                }
            }
        }
        
        echo '</div>';
    }
} 
