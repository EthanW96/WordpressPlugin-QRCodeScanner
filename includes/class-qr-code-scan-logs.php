<?php
// Scan Logs Page Logic for QR Code Tracker
class QRCodeTracker_ScanLogs {
    private $main_table;
    private $log_table;

    public function __construct($tracker) {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
    }

    public function display_scan_logs_page() {
        global $wpdb;
        echo '<div class="wrap"><h1>QR Code Scan Logs</h1>';
    
        // Get filter parameters
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $postcode_filter = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $tree_filter = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        $source_filter = isset($_GET['source']) ? sanitize_text_field($_GET['source']) : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $limit;
    
        // Build WHERE clause
        $where_clause = "WHERE 1=1";
        $where_params = [];
        
        if (!empty($date_from)) {
            $where_clause .= " AND scanned_at >= %s";
            $where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_clause .= " AND scanned_at <= %s";
            $where_params[] = $date_to . ' 23:59:59';
        }
        
        if (!empty($postcode_filter)) {
            $where_clause .= " AND postcode = %s";
            $where_params[] = $postcode_filter;
        }
        
        if (!empty($tree_filter)) {
            $where_clause .= " AND tree = %s";
            $where_params[] = $tree_filter;
        }

        if (!empty($source_filter)) {
            $where_clause .= " AND scan_source = %s";
            $where_params[] = $source_filter;
        }

        // Get available postcodes, trees, and sources for filters
        $postcodes = $wpdb->get_col("SELECT DISTINCT postcode FROM {$this->log_table} WHERE postcode IS NOT NULL AND postcode != '' ORDER BY postcode");
        $trees = $wpdb->get_col("SELECT DISTINCT tree FROM {$this->log_table} WHERE tree IS NOT NULL AND tree != '' ORDER BY tree");
        $sources = $wpdb->get_col("SELECT DISTINCT scan_source FROM {$this->log_table} WHERE scan_source IS NOT NULL AND scan_source != '' ORDER BY scan_source");
    
        // Get total count for pagination
        $count_sql = "SELECT COUNT(*) FROM {$this->log_table} {$where_clause}";
        if (!empty($where_params)) {
            $total_records = $wpdb->get_var($wpdb->prepare($count_sql, $where_params));
        } else {
            $total_records = $wpdb->get_var($count_sql);
        }
        
        $total_pages = ceil($total_records / $limit);
        $page = min($page, $total_pages);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;
    
        // Build and execute query with pagination
        $sql = "SELECT * FROM {$this->log_table} {$where_clause} ORDER BY scanned_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($where_params, [$limit, $offset]);
        
        if (!empty($query_params)) {
            $logs = $wpdb->get_results($wpdb->prepare($sql, $query_params));
        } else {
            $logs = $wpdb->get_results($wpdb->prepare($sql, [$limit, $offset]));
        }
    
        // Display filters
        echo '<div class="qr-filter-form">';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="qr-scan-logs">';
        echo '<div class="qr-filter-row">';
        echo '<div class="qr-filter-field"><label>From:</label><input type="date" name="date_from" value="' . esc_attr($date_from) . '"></div>';
        echo '<div class="qr-filter-field"><label>To:</label><input type="date" name="date_to" value="' . esc_attr($date_to) . '"></div>';
        echo '<div class="qr-filter-field"><label>Limit:</label><select name="limit"><option value="100"' . ($limit == 100 ? ' selected' : '') . '>100</option><option value="500"' . ($limit == 500 ? ' selected' : '') . '>500</option><option value="1000"' . ($limit == 1000 ? ' selected' : '') . '>1000</option><option value="5000"' . ($limit == 5000 ? ' selected' : '') . '>5000</option></select></div>';
        echo '</div>';
        echo '<div class="qr-filter-row">';
        echo '<div class="qr-filter-field"><label>Postcode:</label><select name="postcode"><option value="">All</option>';
        foreach ($postcodes as $postcode) {
            echo '<option value="' . esc_attr($postcode) . '"' . ($postcode_filter == $postcode ? ' selected' : '') . '>' . esc_html($postcode) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="qr-filter-field"><label>Tree:</label><select name="tree"><option value="">All</option>';
        foreach ($trees as $tree) {
            echo '<option value="' . esc_attr($tree) . '"' . ($tree_filter == $tree ? ' selected' : '') . '>' . esc_html($tree) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="qr-filter-field"><label>Source:</label><select name="source"><option value="">All</option>';
        foreach ($sources as $source) {
            echo '<option value="' . esc_attr($source) . '"' . ($source_filter == $source ? ' selected' : '') . '>' . esc_html($source) . '</option>';
        }
        echo '</select></div>';
        echo '<input type="submit" class="button button-primary" value="Apply Filters">';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    
        // Export button
        $export_params = $_GET;
        unset($export_params['page']);
        unset($export_params['paged']);
        echo '<p><a href="' . esc_url(admin_url('admin.php?action=qr_tracker_export&export_type=logs&' . http_build_query($export_params))) . '" class="button button-primary">Export CSV</a></p>';
    
        // Helper function to generate pagination controls
        $generate_pagination = function($page, $total_pages, $start_record, $end_record, $total_records) {
            $pagination_html = '';
            
            // Display pagination info
            $pagination_html .= '<div style="margin: 20px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
            $pagination_html .= '<strong>Showing records ' . number_format($start_record) . ' to ' . number_format($end_record) . ' of ' . number_format($total_records) . ' total records</strong>';
            $pagination_html .= '</div>';
            
            // Display pagination controls
            if ($total_pages > 1) {
                $pagination_html .= '<div style="margin: 20px 0; text-align: center;">';
                $pagination_html .= '<div class="tablenav-pages" style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">';
                
                // Previous page link
                if ($page > 1) {
                    $prev_url = add_query_arg(['paged' => $page - 1], $_SERVER['REQUEST_URI']);
                    $pagination_html .= '<a class="prev page-numbers" href="' . esc_url($prev_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">&laquo; Previous</a>';
                }
                
                // Page numbers
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                if ($start_page > 1) {
                    $first_url = add_query_arg(['paged' => 1], $_SERVER['REQUEST_URI']);
                    $pagination_html .= '<a class="page-numbers" href="' . esc_url($first_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">1</a>';
                    if ($start_page > 2) {
                        $pagination_html .= '<span class="page-numbers dots" style="margin: 0 4px; padding: 5px 10px;">…</span>';
                    }
                }
                
                for ($i = $start_page; $i <= $end_page; $i++) {
                    if ($i == $page) {
                        $pagination_html .= '<span class="page-numbers current" style="margin: 0 4px; padding: 5px 10px; border: 1px solid #0073aa; border-radius: 3px; background: #0073aa; color: white; font-weight: bold;">' . $i . '</span>';
                    } else {
                        $page_url = add_query_arg(['paged' => $i], $_SERVER['REQUEST_URI']);
                        $pagination_html .= '<a class="page-numbers" href="' . esc_url($page_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">' . $i . '</a>';
                    }
                }
                
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        $pagination_html .= '<span class="page-numbers dots" style="margin: 0 4px; padding: 5px 10px;">…</span>';
                    }
                    $last_url = add_query_arg(['paged' => $total_pages], $_SERVER['REQUEST_URI']);
                    $pagination_html .= '<a class="page-numbers" href="' . esc_url($last_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">' . $total_pages . '</a>';
                }
                
                // Next page link
                if ($page < $total_pages) {
                    $next_url = add_query_arg(['paged' => $page + 1], $_SERVER['REQUEST_URI']);
                    $pagination_html .= '<a class="next page-numbers" href="' . esc_url($next_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">Next &raquo;</a>';
                }
                
                $pagination_html .= '</div>';
                $pagination_html .= '</div>';
            }
            
            return $pagination_html;
        };

        // Calculate pagination values
        $start_record = $offset + 1;
        $end_record = min($offset + $limit, $total_records);
        
        // Display top pagination
        echo $generate_pagination($page, $total_pages, $start_record, $end_record, $total_records);

        echo '<div class="qr-table-responsive"><table class="widefat qr-dt-no-controls"><thead><tr><th>ID</th><th>Tracker ID</th><th>Postcode</th><th>City</th><th>Tree</th><th>Source</th><th>Scanned At</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            echo "<tr><td>{$log->id}</td><td>{$log->tracker_id}</td><td>{$log->postcode}</td><td>{$log->city}</td><td>{$log->tree}</td><td>{$log->scan_source}</td><td>{$log->scanned_at}</td></tr>";
        }
        echo '</tbody></table></div>';
        
        // Display bottom pagination
        echo $generate_pagination($page, $total_pages, $start_record, $end_record, $total_records);
        
        echo '</div>';
    }
} 