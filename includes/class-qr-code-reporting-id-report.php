<?php
// Reporting ID QR Code Report functionality
class QRCodeTracker_ReportingIDReport {
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
    }

    /**
     * Build team access restrictions for queries
     * @return array Array with WHERE clause and parameters
     */
    private function build_team_access_restrictions() {
        if (!$this->teams) {
            return ['', []];
        }
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            return ['', []];
        }
        
        // Super admins can access all data
        if (current_user_can('manage_network')) {
            return ['', []];
        }
        
        // Get user's accessible teams
        $accessible_teams = $this->teams->get_accessible_teams();
        if (empty($accessible_teams)) {
            // User has no teams, return no access
            return [' AND 1=0', []];
        }
        
        // Build team restriction
        $team_ids = array_column($accessible_teams, 'id');
        $placeholders = implode(',', array_fill(0, count($team_ids), '%d'));
        
        return [" AND t.team_id IN ($placeholders)", $team_ids];
    }

    /**
     * Display the reporting ID QR code report page
     */
    public function display_reporting_id_report_page() {
        global $wpdb;
        
        // Get reporting ID from URL parameter
        $reporting_id = isset($_GET['reporting_id']) ? sanitize_text_field($_GET['reporting_id']) : '';
        
        if (empty($reporting_id)) {
            echo '<div class="wrap"><h1>Reporting ID QR Code Report</h1>';
            echo '<div class="error"><p>No reporting ID specified.</p></div>';
            echo '<p><a href="' . admin_url('admin.php?page=qr-tracker') . '" class="button">Back to QR Tracker</a></p>';
            echo '</div>';
            return;
        }

        // Get filter parameters
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';

        // Build WHERE clause for this specific reporting ID
        $where_clause = "WHERE t.reporting_id = %s";
        $where_params = [$reporting_id];
        
        if (!empty($date_from)) {
            $where_clause .= " AND l.scanned_at >= %s";
            $where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_clause .= " AND l.scanned_at <= %s";
            $where_params[] = $date_to . ' 23:59:59';
        }

        echo '<div class="wrap"><h1>Reporting ID QR Code Report</h1>';
        
        // Back button
        echo '<p><a href="' . admin_url('admin.php?page=qr-tracker') . '" class="button">&larr; Back to QR Tracker</a></p>';

        // Reporting ID Details Section
        $this->display_reporting_id_details($reporting_id);

        // Filters Section
        $this->display_filters($reporting_id, $date_from, $date_to);

        // Summary Statistics
        $this->display_summary_stats($where_clause, $where_params, $reporting_id);

        // Charts Section
        $this->display_charts($where_clause, $where_params, $reporting_id);

        // QR Codes with this Reporting ID
        $this->display_reporting_id_qr_codes($where_clause, $where_params, $reporting_id);

        // Detailed Scan Logs
        $this->display_scan_logs($where_clause, $where_params, $reporting_id);

        echo '</div>';
    }

    /**
     * Display reporting ID details
     */
    private function display_reporting_id_details($reporting_id) {
        global $wpdb;
        
        // Get reporting ID statistics with team access restrictions
        list($team_restriction, $team_params) = $this->build_team_access_restrictions();
        $reporting_id_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(DISTINCT t.id) as total_qr_codes,
                SUM(t.scan_count) as total_scans,
                MAX(t.last_scanned) as last_scanned,
                COUNT(DISTINCT t.postcode) as unique_postcodes,
                COUNT(DISTINCT t.city) as unique_cities,
                COUNT(DISTINCT t.tree) as unique_trees
            FROM {$this->main_table} t
            WHERE t.reporting_id = %s{$team_restriction}",
            array_merge([$reporting_id], $team_params)
        ));

        echo '<div style="background: #fff; border: 1px solid #ddd; padding: 20px; margin: 20px 0; border-radius: 4px;">';
        echo '<h2>Reporting ID Details: ' . esc_html($reporting_id) . '</h2>';
        echo '<table class="form-table" style="margin: 0;">';
        echo '<tr><th style="width: 150px;">Reporting ID:</th><td><strong>' . esc_html($reporting_id) . '</strong></td></tr>';
        echo '<tr><th>Total QR Codes:</th><td><strong>' . number_format($reporting_id_stats->total_qr_codes) . '</strong></td></tr>';
        echo '<tr><th>Total Scans:</th><td><strong>' . number_format($reporting_id_stats->total_scans) . '</strong></td></tr>';
        echo '<tr><th>Unique Postcodes:</th><td><strong>' . number_format($reporting_id_stats->unique_postcodes) . '</strong></td></tr>';
        echo '<tr><th>Unique Cities:</th><td><strong>' . number_format($reporting_id_stats->unique_cities) . '</strong></td></tr>';
        echo '<tr><th>Unique Trees:</th><td><strong>' . number_format($reporting_id_stats->unique_trees) . '</strong></td></tr>';
        echo '<tr><th>Last Scanned:</th><td>' . esc_html($reporting_id_stats->last_scanned) . '</td></tr>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Display filters
     */
    private function display_filters($reporting_id, $date_from, $date_to) {
        echo '<div style="background: #f9f9f9; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<h3>Filters</h3>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="qr-reporting-id-report">';
        echo '<input type="hidden" name="reporting_id" value="' . esc_attr($reporting_id) . '">';
        echo '<div style="margin-bottom: 10px;">';
        echo '<label>From:</label><input type="date" name="date_from" value="' . esc_attr($date_from) . '" style="margin-right: 10px;">';
        echo '<label>To:</label><input type="date" name="date_to" value="' . esc_attr($date_to) . '" style="margin-right: 10px;">';
        echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => date('Y-m-d', strtotime('-30 days')), 'date_to' => date('Y-m-d')]))) . '" class="button">Last 30 Days</a> ';
        echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => date('Y-m-d', strtotime('-7 days')), 'date_to' => date('Y-m-d')]))) . '" class="button">Last 7 Days</a> ';
        echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => '', 'date_to' => '']))) . '" class="button">All Time</a> ';
        echo '<input type="submit" class="button button-primary" value="Apply Filters">';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * Display summary statistics
     */
    private function display_summary_stats($where_clause, $where_params, $reporting_id) {
        global $wpdb;
        
        // Get detailed statistics for this reporting ID
        $sql = "SELECT 
                    COUNT(*) as total_scans,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan,
                    AVG(HOUR(l.scanned_at)) as avg_hour,
                    COUNT(DISTINCT HOUR(l.scanned_at)) as active_hours,
                    COUNT(DISTINCT l.tracker_id) as active_qr_codes
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}";
        
        $stats = $wpdb->get_row($wpdb->prepare($sql, $where_params));
        
        if ($stats && $stats->total_scans > 0) {
            echo '<div class="qr-summary-cards">';
            echo '<div class="qr-summary-card">';
            echo '<div class="qr-summary-card-number">' . number_format($stats->total_scans) . '</div>';
            echo '<div class="qr-summary-card-label">Total Scans</div>';
            echo '</div>';
            echo '<div class="qr-summary-card">';
            echo '<div class="qr-summary-card-number">' . number_format($stats->unique_days) . '</div>';
            echo '<div class="qr-summary-card-label">Active Days</div>';
            echo '</div>';
            echo '<div class="qr-summary-card">';
            echo '<div class="qr-summary-card-number">' . number_format($stats->active_qr_codes) . '</div>';
            echo '<div class="qr-summary-card-label">Active QR Codes</div>';
            echo '</div>';
            echo '<div class="qr-summary-card">';
            echo '<div class="qr-summary-card-number">' . sprintf('%.1f', $stats->avg_hour) . '</div>';
            echo '<div class="qr-summary-card-label">Avg Hour</div>';
            echo '</div>';
            echo '</div>';
            
            echo '<p><strong>Date Range:</strong> ' . esc_html($stats->first_scan) . ' to ' . esc_html($stats->last_scan) . '</p>';
        } else {
            echo '<div class="notice notice-warning"><p>No scan data found for the selected filters.</p></div>';
        }
    }

    /**
     * Display charts
     */
    private function display_charts($where_clause, $where_params, $reporting_id) {
        // Include Chart.js
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        
        // Get time series data for this reporting ID
        $time_series_data = $this->get_time_series_data($where_clause, $where_params);
        
        // Get hour distribution data
        $hour_data = $this->get_hour_distribution_data($where_clause, $where_params);
        
        // Get day of week distribution data
        $day_data = $this->get_day_of_week_distribution_data($where_clause, $where_params);
        
        echo '<div style="margin: 20px 0;">';
        echo '<h2>Charts</h2>';
        echo '<button onclick="showLineChart()" class="button">Show Daily Activity Chart</button> ';
        echo '<button onclick="showRadarChart()" class="button">Show Hour Distribution Chart</button> ';
        echo '<button onclick="showBarChart()" class="button">Show Day of Week Chart</button>';
        echo '</div>';
        
        // Line chart container
        echo '<div id="line-chart-container" class="qr-chart-container" style="display: none;">';
        echo '<div class="qr-chart-header">';
        echo '<h3>Daily Activity Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'line-chart\', \'reporting-id-daily-activity-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="line-chart" width="800" height="400"></canvas>';
        echo '</div>';
        
        // Radar chart container
        echo '<div id="radar-chart-container" class="qr-chart-container" style="display: none; height: 700px;">';
        echo '<div class="qr-chart-header">';
        echo '<h3>Hour Distribution Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'radar-chart\', \'reporting-id-hour-distribution-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="radar-chart" width="600" height="600"></canvas>';
        echo '</div>';
        
        // Bar chart container
        echo '<div id="bar-chart-container" class="qr-chart-container" style="display: none;">';
        echo '<div class="qr-chart-header">';
        echo '<h3>Day of Week Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'bar-chart\', \'reporting-id-day-of-week-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="bar-chart" width="800" height="400"></canvas>';
        echo '</div>';
        
        // JavaScript for charts
        echo '<script>';
        echo 'var lineChartData = {';
        echo 'labels: [' . implode(',', array_map(function($date) { return "'" . $date . "'"; }, $time_series_data['dates'])) . '],';
        echo 'values: [' . implode(',', $time_series_data['values']) . ']';
        echo '};';
        
        echo 'var radarChartData = {';
        echo 'labels: [' . implode(',', array_map(function($hour) { return "'" . sprintf('%02d:00', $hour) . "'"; }, range(0, 23))) . '],';
        echo 'values: [' . implode(',', $hour_data) . ']';
        echo '};';
        
        echo 'var barChartData = {';
        echo 'labels: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"],';
        echo 'values: [' . implode(',', array_values($day_data)) . ']';
        echo '};';
        
        echo 'var lineChartInstance = null;';
        echo 'var radarChartInstance = null;';
        echo 'var barChartInstance = null;';
        
        echo 'window.showLineChart = function() {';
        echo 'var container = document.getElementById("line-chart-container");';
        echo 'if (container.style.display === "none") {';
        echo 'container.style.display = "block";';
        echo 'setTimeout(function() {';
        echo 'if (lineChartInstance) { lineChartInstance.destroy(); }';
        echo 'var ctx = document.getElementById("line-chart").getContext("2d");';
        echo 'lineChartInstance = new Chart(ctx, {';
        echo 'type: "line",';
        echo 'data: {';
        echo 'labels: lineChartData.labels.map(function(date) { var d = new Date(date); return (d.getMonth() + 1) + "/" + d.getDate(); }),';
        echo 'datasets: [{';
        echo 'label: "Daily Scans",';
        echo 'data: lineChartData.values,';
        echo 'borderColor: "#0073aa",';
        echo 'backgroundColor: "#0073aa20",';
        echo 'borderWidth: 3,';
        echo 'fill: true,';
        echo 'tension: 0.1,';
        echo 'pointRadius: 4,';
        echo 'pointHoverRadius: 6';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'maintainAspectRatio: false,';
        echo 'plugins: {';
        echo 'title: { display: true, text: "Daily Scan Activity - ' . esc_js($reporting_id) . '" }';
        echo '},';
        echo 'scales: {';
        echo 'y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }';
        echo '}';
        echo '}';
        echo '});';
        echo '}, 100);';
        echo '} else {';
        echo 'container.style.display = "none";';
        echo '}';
        echo '};';
        
        echo 'window.showRadarChart = function() {';
        echo 'var container = document.getElementById("radar-chart-container");';
        echo 'if (container.style.display === "none") {';
        echo 'container.style.display = "block";';
        echo 'setTimeout(function() {';
        echo 'if (radarChartInstance) { radarChartInstance.destroy(); }';
        echo 'var ctx = document.getElementById("radar-chart").getContext("2d");';
        echo 'radarChartInstance = new Chart(ctx, {';
        echo 'type: "radar",';
        echo 'data: {';
        echo 'labels: radarChartData.labels,';
        echo 'datasets: [{';
        echo 'label: "Scan Distribution by Hour",';
        echo 'data: radarChartData.values,';
        echo 'borderColor: "#FF6384",';
        echo 'backgroundColor: "#FF638420",';
        echo 'pointBackgroundColor: "#FF6384",';
        echo 'pointBorderColor: "#fff",';
        echo 'pointHoverBackgroundColor: "#fff",';
        echo 'pointHoverBorderColor: "#FF6384"';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'plugins: {';
        echo 'title: { display: true, text: "Scan Distribution by Hour of Day - ' . esc_js($reporting_id) . '" }';
        echo '},';
        echo 'scales: {';
        echo 'r: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }';
        echo '}';
        echo '}';
        echo '});';
        echo '}, 100);';
        echo '} else {';
        echo 'container.style.display = "none";';
        echo '}';
        echo '};';
        
        echo 'window.showBarChart = function() {';
        echo 'var container = document.getElementById("bar-chart-container");';
        echo 'if (container.style.display === "none") {';
        echo 'container.style.display = "block";';
        echo 'setTimeout(function() {';
        echo 'if (barChartInstance) { barChartInstance.destroy(); }';
        echo 'var ctx = document.getElementById("bar-chart").getContext("2d");';
        echo 'barChartInstance = new Chart(ctx, {';
        echo 'type: "bar",';
        echo 'data: {';
        echo 'labels: barChartData.labels,';
        echo 'datasets: [{';
        echo 'label: "Scan Distribution by Day of Week",';
        echo 'data: barChartData.values,';
        echo 'backgroundColor: "#36A2EB",';
        echo 'borderColor: "#36A2EB",';
        echo 'borderWidth: 1';
        echo '}]';
        echo '},';
        echo 'options: {';
        echo 'responsive: true,';
        echo 'maintainAspectRatio: false,';
        echo 'plugins: {';
        echo 'title: { display: true, text: "Scan Distribution by Day of Week - ' . esc_js($reporting_id) . '" }';
        echo '},';
        echo 'scales: {';
        echo 'y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }';
        echo '}';
        echo '}';
        echo '});';
        echo '}, 100);';
        echo '} else {';
        echo 'container.style.display = "none";';
        echo '}';
        echo '};';
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
    }

    /**
     * Display QR codes with this reporting ID
     */
    private function display_reporting_id_qr_codes($where_clause, $where_params, $reporting_id) {
        global $wpdb;
        
        // Get QR codes for this reporting ID
        $sql = "SELECT 
                    t.id,
                    t.postcode,
                    t.city,
                    t.tree,
                    t.label,
                    t.scan_count,
                    t.last_scanned,
                    t.url
                FROM {$this->main_table} t
                WHERE t.reporting_id = %s
                ORDER BY t.scan_count DESC";
        
        $qr_codes = $wpdb->get_results($wpdb->prepare($sql, $reporting_id));

        echo '<div style="margin: 20px 0;">';
        echo '<h2>QR Codes with Reporting ID: ' . esc_html($reporting_id) . '</h2>';
        
        if (!empty($qr_codes)) {
            echo '<div class="qr-table-responsive"><table class="widefat">';
            echo '<thead><tr><th>Postcode</th><th>City</th><th>Tree</th><th>Label</th><th>Scans</th><th>Last Scanned</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($qr_codes as $qr_code) {
                echo '<tr>';
                echo '<td>' . esc_html($qr_code->postcode) . '</td>';
                echo '<td><a href="' . admin_url('admin.php?page=qr-city-report&city=' . urlencode($qr_code->city)) . '">' . esc_html($qr_code->city) . '</a></td>';
                echo '<td>' . esc_html($qr_code->tree) . '</td>';
                echo '<td>' . esc_html($qr_code->label) . '</td>';
                echo '<td>' . number_format($qr_code->scan_count) . '</td>';
                echo '<td>' . esc_html($qr_code->last_scanned) . '</td>';
                echo '<td><a href="' . admin_url('admin.php?page=qr-single-report&qr_id=' . $qr_code->id) . '" class="button">View Report</a></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table></div>';
        } else {
            echo '<p>No QR codes found for this reporting ID.</p>';
        }
        
        echo '</div>';
    }

    /**
     * Display scan logs
     */
    private function display_scan_logs($where_clause, $where_params, $reporting_id) {
        global $wpdb;
        
        // Get filter parameters
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // Get pagination parameters
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $limit;

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$this->log_table} l JOIN {$this->main_table} t ON l.tracker_id = t.id {$where_clause}";
        $total_records = $wpdb->get_var($wpdb->prepare($count_sql, $where_params));
        
        $total_pages = ceil($total_records / $limit);
        $page = min($page, $total_pages);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        // Get scan logs
        $sql = "SELECT l.*, t.postcode, t.city, t.tree FROM {$this->log_table} l JOIN {$this->main_table} t ON l.tracker_id = t.id {$where_clause} ORDER BY l.scanned_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($where_params, [$limit, $offset]);
        $logs = $wpdb->get_results($wpdb->prepare($sql, $query_params));

        echo '<div style="margin: 20px 0;">';
        echo '<h2>Recent Scan Logs for Reporting ID: ' . esc_html($reporting_id) . '</h2>';
        
        // Export button
        $export_url = admin_url('admin.php?action=qr_tracker_export&export_type=reporting_id&reporting_id=' . urlencode($reporting_id));
        if (!empty($date_from)) $export_url .= '&date_from=' . urlencode($date_from);
        if (!empty($date_to)) $export_url .= '&date_to=' . urlencode($date_to);
        echo '<p><a href="' . esc_url($export_url) . '" class="button button-primary">Export CSV</a></p>';

        if (!empty($logs)) {
            // Top pagination
            $this->display_pagination($page, $total_pages, $total_records, $limit, $offset, 'top');
            
            echo '<div class="qr-table-responsive"><table class="widefat">';
            echo '<thead><tr><th>ID</th><th>Postcode</th><th>City</th><th>Tree</th><th>Scanned At</th><th>Hour</th><th>Day of Week</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($logs as $log) {
                $scanned_at = new DateTime($log->scanned_at);
                $hour = $scanned_at->format('H:i');
                $day_of_week = $scanned_at->format('l');
                
                echo '<tr>';
                echo '<td>' . esc_html($log->id) . '</td>';
                echo '<td>' . esc_html($log->postcode) . '</td>';
                echo '<td><a href="' . admin_url('admin.php?page=qr-city-report&city=' . urlencode($log->city)) . '">' . esc_html($log->city) . '</a></td>';
                echo '<td>' . esc_html($log->tree) . '</td>';
                echo '<td>' . esc_html($log->scanned_at) . '</td>';
                echo '<td>' . esc_html($hour) . '</td>';
                echo '<td>' . esc_html($day_of_week) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table></div>';
            
            // Bottom pagination
            $this->display_pagination($page, $total_pages, $total_records, $limit, $offset, 'bottom');
        } else {
            echo '<p>No scan logs found for the selected filters.</p>';
        }
        
        echo '</div>';
    }

    /**
     * Display pagination controls
     */
    private function display_pagination($page, $total_pages, $total_records, $limit, $offset, $position = 'bottom') {
        if ($total_pages <= 1) {
            return;
        }

        $start_record = $offset + 1;
        $end_record = min($offset + $limit, $total_records);
        
        echo '<div style="margin: 20px 0; text-align: center;">';
        
        // Display pagination info
        echo '<div style="margin-bottom: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
        echo '<strong>Showing records ' . number_format($start_record) . ' to ' . number_format($end_record) . ' of ' . number_format($total_records) . ' total records</strong>';
        echo '</div>';
        
        // Display pagination controls
        echo '<div class="tablenav-pages" style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">';
        
        // Previous page link
        if ($page > 1) {
            $prev_url = add_query_arg(['paged' => $page - 1], $_SERVER['REQUEST_URI']);
            echo '<a class="prev page-numbers" href="' . esc_url($prev_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">&laquo; Previous</a>';
        }
        
        // Page numbers
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);
        
        if ($start_page > 1) {
            $first_url = add_query_arg(['paged' => 1], $_SERVER['REQUEST_URI']);
            echo '<a class="page-numbers" href="' . esc_url($first_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">1</a>';
            if ($start_page > 2) {
                echo '<span class="page-numbers dots" style="margin: 0 4px; padding: 5px 10px;">…</span>';
            }
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                echo '<span class="page-numbers current" style="margin: 0 4px; padding: 5px 10px; border: 1px solid #0073aa; border-radius: 3px; background: #0073aa; color: white; font-weight: bold;">' . $i . '</span>';
            } else {
                $page_url = add_query_arg(['paged' => $i], $_SERVER['REQUEST_URI']);
                echo '<a class="page-numbers" href="' . esc_url($page_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">' . $i . '</a>';
            }
        }
        
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo '<span class="page-numbers dots" style="margin: 0 4px; padding: 5px 10px;">…</span>';
            }
            $last_url = add_query_arg(['paged' => $total_pages], $_SERVER['REQUEST_URI']);
            echo '<a class="page-numbers" href="' . esc_url($last_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">' . $total_pages . '</a>';
        }
        
        // Next page link
        if ($page < $total_pages) {
            $next_url = add_query_arg(['paged' => $page + 1], $_SERVER['REQUEST_URI']);
            echo '<a class="next page-numbers" href="' . esc_url($next_url) . '" style="margin: 0 4px; padding: 5px 10px; text-decoration: none; border: 1px solid #ddd; border-radius: 3px; background: #f9f9f9;">Next &raquo;</a>';
        }
        
        echo '</div>';
        echo '</div>';
    }

    /**
     * Get time series data for this reporting ID
     */
    private function get_time_series_data($where_clause, $where_params) {
        global $wpdb;
        
        // Get date range from filters or use last 30 days
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d', strtotime('-30 days'));
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : date('Y-m-d');
        
        // Use the filtered where clause but ensure we get the date range for chart display
        $sql = "SELECT 
                    DATE(l.scanned_at) as scan_date,
                    COUNT(*) as scan_count
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY DATE(l.scanned_at)
                ORDER BY scan_date";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        
        // Generate all dates in range for chart display
        $dates = [];
        $values = [];
        $current_date = new DateTime($date_from);
        $end_date = new DateTime($date_to);
        
        while ($current_date <= $end_date) {
            $date_key = $current_date->format('Y-m-d');
            $dates[] = $date_key;
            
            // Find scan count for this date
            $scan_count = 0;
            foreach ($results as $row) {
                if ($row->scan_date == $date_key) {
                    $scan_count = (int)$row->scan_count;
                    break;
                }
            }
            $values[] = $scan_count;
            
            $current_date->add(new DateInterval('P1D'));
        }
        
        return [
            'dates' => $dates,
            'values' => $values
        ];
    }

    /**
     * Get hour distribution data for this reporting ID
     */
    private function get_hour_distribution_data($where_clause, $where_params) {
        global $wpdb;
        
        $sql = "SELECT 
                    HOUR(l.scanned_at) as scan_hour,
                    COUNT(*) as scan_count
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY scan_hour
                ORDER BY scan_hour";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        
        $hour_data = array_fill(0, 24, 0);
        foreach ($results as $row) {
            $hour = (int)$row->scan_hour;
            $hour_data[$hour] = (int)$row->scan_count;
        }
        
        return $hour_data;
    }

    /**
     * Get day of week distribution data for this reporting ID
     */
    private function get_day_of_week_distribution_data($where_clause, $where_params) {
        global $wpdb;
        
        $sql = "SELECT 
                    DAYOFWEEK(l.scanned_at) as day_of_week,
                    COUNT(*) as scan_count
                FROM {$this->log_table} l
                JOIN {$this->main_table} t ON l.tracker_id = t.id
                {$where_clause}
                GROUP BY day_of_week
                ORDER BY day_of_week";
        
        $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        
        $day_data = array_fill(1, 7, 0);
        foreach ($results as $row) {
            $day = (int)$row->day_of_week;
            $day_data[$day] = (int)$row->scan_count;
        }
        
        return $day_data;
    }
}

// Helper function for JavaScript
if (!function_exists('range')) {
    function range($start, $end) {
        $result = [];
        for ($i = $start; $i <= $end; $i++) {
            $result[] = $i;
        }
        return $result;
    }
} 