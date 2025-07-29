<?php
/**
 * QR Code Search functionality
 * Handles search processing, AJAX suggestions, and search history
 */
class QRCodeTracker_Search {
    private $main_table;
    private $log_table;

    public function __construct() {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
        
        // Add AJAX handlers
        add_action('wp_ajax_qr_tracker_search_suggestions', [$this, 'ajax_search_suggestions']);
    }

    /**
     * Process search term and populate filters intelligently
     * @param string $search_term The search term entered by user
     * @param string &$postcode_filter Reference to postcode filter
     * @param string &$tree_filter Reference to tree filter  
     * @param string &$city_filter Reference to city filter
     */
    public function process_search_term($search_term, &$postcode_filter, &$tree_filter, &$city_filter) {
        global $wpdb;
        
        $search_term = trim($search_term);
        if (empty($search_term)) {
            return;
        }
        
        // Try to match postcode pattern (UK postcodes are typically 5-7 characters with space)
        if (preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?\s*[0-9][A-Z]{2}$/i', $search_term)) {
            // Check if this postcode exists in our database with scan data
            $postcode = strtoupper($search_term);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE l.postcode = %s",
                $postcode
            ));
            if ($exists > 0) {
                $postcode_filter = $postcode;
                return;
            }
        }
        
        // Try to match as city name (case-insensitive) - only with scan data
        $city = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT l.city FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE LOWER(l.city) = LOWER(%s) AND l.city IS NOT NULL AND l.city != '' 
             LIMIT 1",
            $search_term
        ));
        if ($city) {
            $city_filter = $city;
            return;
        }
        
        // Try to match as tree value (case-insensitive) - only with scan data
        $tree = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT l.tree FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE LOWER(l.tree) = LOWER(%s) AND l.tree IS NOT NULL AND l.tree != '' 
             LIMIT 1",
            $search_term
        ));
        if ($tree) {
            $tree_filter = $tree;
            return;
        }
        
        // If no exact match, try partial matches - only with scan data
        $partial_city = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT l.city FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE LOWER(l.city) LIKE LOWER(%s) AND l.city IS NOT NULL AND l.city != '' 
             LIMIT 1",
            '%' . $search_term . '%'
        ));
        if ($partial_city) {
            $city_filter = $partial_city;
            return;
        }
        
        $partial_tree = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT l.tree FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE LOWER(l.tree) LIKE LOWER(%s) AND l.tree IS NOT NULL AND l.tree != '' 
             LIMIT 1",
            '%' . $search_term . '%'
        ));
        if ($partial_tree) {
            $tree_filter = $partial_tree;
            return;
        }
        
        $partial_postcode = $wpdb->get_var($wpdb->prepare(
            "SELECT DISTINCT l.postcode FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE LOWER(l.postcode) LIKE LOWER(%s) AND l.postcode IS NOT NULL AND l.postcode != '' 
             LIMIT 1",
            '%' . $search_term . '%'
        ));
        if ($partial_postcode) {
            $postcode_filter = $partial_postcode;
            return;
        }
    }

    /**
     * Process multiple search terms and populate filter arrays
     * @param array $search_terms Array of search terms
     * @param array &$postcode_filters Reference to postcode filters array
     * @param array &$tree_filters Reference to tree filters array
     * @param array &$city_filters Reference to city filters array
     * @param array &$reporting_id_filters Reference to reporting ID filters array
     */
    public function process_search_terms($search_terms, &$postcode_filters, &$tree_filters, &$city_filters, &$reporting_id_filters) {
        global $wpdb;
        
        foreach ($search_terms as $search_term) {
            $search_term = trim($search_term);
            if (empty($search_term)) {
                continue;
            }
            
            // Try to match postcode pattern (UK postcodes are typically 5-7 characters with space)
            if (preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?\s*[0-9][A-Z]{2}$/i', $search_term)) {
                // Check if this postcode exists in our database with scan data
                $postcode = strtoupper($search_term);
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->log_table} l 
                     JOIN {$this->main_table} t ON l.tracker_id = t.id 
                     WHERE l.postcode = %s",
                    $postcode
                ));
                if ($exists > 0) {
                    $postcode_filters[] = $postcode;
                    continue;
                }
            }
            
            // Try to match as reporting ID (case-insensitive) - only with scan data
            $reporting_id = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT t.reporting_id FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(t.reporting_id) = LOWER(%s) AND t.reporting_id IS NOT NULL AND t.reporting_id != '' 
                 LIMIT 1",
                $search_term
            ));
            if ($reporting_id) {
                $reporting_id_filters[] = $reporting_id;
                continue;
            }
            
            // Try to match as city name (case-insensitive) - only with scan data
            $city = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT l.city FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(l.city) = LOWER(%s) AND l.city IS NOT NULL AND l.city != '' 
                 LIMIT 1",
                $search_term
            ));
            if ($city) {
                $city_filters[] = $city;
                continue;
            }
            
            // Try to match as tree value (case-insensitive) - only with scan data
            $tree = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT l.tree FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(l.tree) = LOWER(%s) AND l.tree IS NOT NULL AND l.tree != '' 
                 LIMIT 1",
                $search_term
            ));
            if ($tree) {
                $tree_filters[] = $tree;
                continue;
            }
            
            // If no exact match, try partial matches - only with scan data
            $partial_reporting_id = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT t.reporting_id FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(t.reporting_id) LIKE LOWER(%s) AND t.reporting_id IS NOT NULL AND t.reporting_id != '' 
                 LIMIT 1",
                '%' . $search_term . '%'
            ));
            if ($partial_reporting_id) {
                $reporting_id_filters[] = $partial_reporting_id;
                continue;
            }
            
            $partial_city = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT l.city FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(l.city) LIKE LOWER(%s) AND l.city IS NOT NULL AND l.city != '' 
                 LIMIT 1",
                '%' . $search_term . '%'
            ));
            if ($partial_city) {
                $city_filters[] = $partial_city;
                continue;
            }
            
            $partial_tree = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT l.tree FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(l.tree) LIKE LOWER(%s) AND l.tree IS NOT NULL AND l.tree != '' 
                 LIMIT 1",
                '%' . $search_term . '%'
            ));
            if ($partial_tree) {
                $tree_filters[] = $partial_tree;
                continue;
            }
            
            $partial_postcode = $wpdb->get_var($wpdb->prepare(
                "SELECT DISTINCT l.postcode FROM {$this->log_table} l 
                 JOIN {$this->main_table} t ON l.tracker_id = t.id 
                 WHERE LOWER(l.postcode) LIKE LOWER(%s) AND l.postcode IS NOT NULL AND l.postcode != '' 
                 LIMIT 1",
                '%' . $search_term . '%'
            ));
            if ($partial_postcode) {
                $postcode_filters[] = $partial_postcode;
                continue;
            }
        }
    }

    /**
     * Store a recent search term
     * @param string $search_term The search term to store
     */
    public function store_recent_search($search_term) {
        $recent_searches = get_option('qr_tracker_recent_searches', []);
        
        // Remove the search term if it already exists
        $recent_searches = array_filter($recent_searches, function($term) use ($search_term) {
            return $term !== $search_term;
        });
        
        // Add the search term to the beginning
        array_unshift($recent_searches, $search_term);
        
        // Keep only the last 10 searches
        $recent_searches = array_slice($recent_searches, 0, 10);
        
        update_option('qr_tracker_recent_searches', $recent_searches);
    }

    /**
     * Get recent searches
     * @return array Array of recent search terms
     */
    public function get_recent_searches() {
        return get_option('qr_tracker_recent_searches', []);
    }

    /**
     * Get popular searches based on data in the database
     * @return array Array of popular search terms
     */
    public function get_popular_searches() {
        global $wpdb;
        
        $popular_searches = [];
        
        // Get top cities by scan count
        $top_cities = $wpdb->get_col($wpdb->prepare(
            "SELECT l.city FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.city IS NOT NULL AND l.city != '' 
             GROUP BY l.city 
             ORDER BY COUNT(*) DESC 
             LIMIT 3"
        ));
        
        // Get top trees by scan count
        $top_trees = $wpdb->get_col($wpdb->prepare(
            "SELECT l.tree FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.tree IS NOT NULL AND l.tree != '' 
             GROUP BY l.tree 
             ORDER BY COUNT(*) DESC 
             LIMIT 2"
        ));
        
        // Get top postcodes by scan count
        $top_postcodes = $wpdb->get_col($wpdb->prepare(
            "SELECT l.postcode FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.postcode IS NOT NULL AND l.postcode != '' 
             GROUP BY l.postcode 
             ORDER BY COUNT(*) DESC 
             LIMIT 2"
        ));
        
        $popular_searches = array_merge($top_cities, $top_trees, $top_postcodes);
        
        // Limit to 5 total popular searches
        return array_slice($popular_searches, 0, 5);
    }

    /**
     * AJAX handler for search suggestions
     */
    public function ajax_search_suggestions() {
        // Check nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'qr_tracker_search_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        $query = sanitize_text_field($_POST['query']);
        
        if (empty($query) || strlen($query) < 2) {
            wp_send_json_success([]);
            return;
        }
        
        global $wpdb;
        $suggestions = [];
        
        // Search in postcodes
        $postcodes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.postcode 
             FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.postcode LIKE %s AND l.postcode IS NOT NULL AND l.postcode != '' 
             ORDER BY l.postcode 
             LIMIT 3",
            '%' . $wpdb->esc_like($query) . '%'
        ));
        
        // Search in cities
        $cities = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.city 
             FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.city LIKE %s AND l.city IS NOT NULL AND l.city != '' 
             ORDER BY l.city 
             LIMIT 3",
            '%' . $wpdb->esc_like($query) . '%'
        ));
        
        // Search in trees
        $trees = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT l.tree 
             FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE l.tree LIKE %s AND l.tree IS NOT NULL AND l.tree != '' 
             ORDER BY l.tree 
             LIMIT 3",
            '%' . $wpdb->esc_like($query) . '%'
        ));
        
        // Search in reporting IDs
        $reporting_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.reporting_id 
             FROM {$this->log_table} l 
             JOIN {$this->main_table} t ON l.tracker_id = t.id 
             WHERE t.reporting_id LIKE %s AND t.reporting_id IS NOT NULL AND t.reporting_id != '' 
             ORDER BY t.reporting_id 
             LIMIT 3",
            '%' . $wpdb->esc_like($query) . '%'
        ));
        
        // Combine all suggestions with type information
        $suggestions = [];
        
        foreach ($postcodes as $postcode) {
            $suggestions[] = [
                'value' => $postcode,
                'type' => 'postcode',
                'label' => $postcode . ' (Postcode)'
            ];
        }
        
        foreach ($cities as $city) {
            $suggestions[] = [
                'value' => $city,
                'type' => 'city',
                'label' => $city . ' (City)'
            ];
        }
        
        foreach ($trees as $tree) {
            $suggestions[] = [
                'value' => $tree,
                'type' => 'tree',
                'label' => $tree . ' (Tree)'
            ];
        }
        
        foreach ($reporting_ids as $reporting_id) {
            $suggestions[] = [
                'value' => $reporting_id,
                'type' => 'reporting_id',
                'label' => $reporting_id . ' (Reporting ID)'
            ];
        }
        
        // Remove duplicates based on value and limit to 8 suggestions
        $unique_suggestions = [];
        $seen_values = [];
        
        foreach ($suggestions as $suggestion) {
            if (!in_array($suggestion['value'], $seen_values) && count($unique_suggestions) < 8) {
                $unique_suggestions[] = $suggestion;
                $seen_values[] = $suggestion['value'];
            }
        }
        
        wp_send_json_success($unique_suggestions);
    }
} 