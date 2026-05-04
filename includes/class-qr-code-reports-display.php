<?php
/**
 * QR Code Reports Display functionality
 * Handles the display of reports page with search and filters
 */
class QRCodeTracker_ReportsDisplay {
    private $main_table;
    private $log_table;
    private $search;
    private $teams;

    public function __construct($search, $teams) {
        global $wpdb;
        $this->main_table = $wpdb->prefix . 'qr_tracker';
        $this->log_table = $wpdb->prefix . 'qr_tracker_logs';
        $this->search = $search;
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
     * Display the reports page
     */
    public function display_reports_page() {
        global $wpdb;
        
        echo '<div class="wrap"><h1>QR Code Reports</h1>';
    
        // Get filter parameters
        $view_type = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'breakdown';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $postcode_filter = isset($_GET['postcode']) ? sanitize_text_field($_GET['postcode']) : '';
        $tree_filter = isset($_GET['tree']) ? sanitize_text_field($_GET['tree']) : '';
        $city_filter = isset($_GET['city']) ? sanitize_text_field($_GET['city']) : '';
        $reporting_id_filter = isset($_GET['reporting_id']) ? sanitize_text_field($_GET['reporting_id']) : '';
        $search_terms = isset($_GET['search_terms']) ? array_filter(array_map('trim', explode(',', sanitize_text_field($_GET['search_terms'])))) : [];
    
        // Process search terms to populate filters
        if (!empty($search_terms)) {
            $postcode_filters = [];
            $tree_filters = [];
            $city_filters = [];
            $reporting_id_filters = [];
            
            $this->search->process_search_terms($search_terms, $postcode_filters, $tree_filters, $city_filters, $reporting_id_filters);
            
            // Store the search terms in recent searches
            foreach ($search_terms as $term) {
                $this->search->store_recent_search($term);
            }
        }
    
        // Build WHERE clause for date filtering
        $where_clause = "WHERE 1=1";
        $where_params = [];
        
        // Add team access restrictions
        list($team_restriction, $team_params) = $this->build_team_access_restrictions();
        $where_clause .= $team_restriction;
        $where_params = array_merge($where_params, $team_params);
        
        if (!empty($date_from)) {
            $where_clause .= " AND l.scanned_at >= %s";
            $where_params[] = $date_from . ' 00:00:00';
        }
        
        if (!empty($date_to)) {
            $where_clause .= " AND l.scanned_at <= %s";
            $where_params[] = $date_to . ' 23:59:59';
        }
        
        // Handle multiple search terms as OR conditions
        if (!empty($search_terms)) {
            $search_conditions = [];
            $search_params = [];
            
            // Add postcode conditions
            if (!empty($postcode_filters)) {
                $placeholders = array_fill(0, count($postcode_filters), '%s');
                $search_conditions[] = "l.postcode IN (" . implode(',', $placeholders) . ")";
                $search_params = array_merge($search_params, $postcode_filters);
            }
            
            // Add city conditions
            if (!empty($city_filters)) {
                $placeholders = array_fill(0, count($city_filters), '%s');
                $search_conditions[] = "l.city IN (" . implode(',', $placeholders) . ")";
                $search_params = array_merge($search_params, $city_filters);
            }
            
            // Add tree conditions
            if (!empty($tree_filters)) {
                $placeholders = array_fill(0, count($tree_filters), '%s');
                $search_conditions[] = "l.tree IN (" . implode(',', $placeholders) . ")";
                $search_params = array_merge($search_params, $tree_filters);
            }
            
            // Add reporting ID conditions
            if (!empty($reporting_id_filters)) {
                $placeholders = array_fill(0, count($reporting_id_filters), '%s');
                $search_conditions[] = "t.reporting_id IN (" . implode(',', $placeholders) . ")";
                $search_params = array_merge($search_params, $reporting_id_filters);
            }
            
            // Combine all search conditions with OR
            if (!empty($search_conditions)) {
                $where_clause .= " AND (" . implode(' OR ', $search_conditions) . ")";
                $where_params = array_merge($where_params, $search_params);
            }
        } else {
            // Handle single filter values (for backward compatibility)
            if (!empty($postcode_filter)) {
                $where_clause .= " AND l.postcode = %s";
                $where_params[] = $postcode_filter;
            }
            
            if (!empty($tree_filter)) {
                $where_clause .= " AND l.tree = %s";
                $where_params[] = $tree_filter;
            }
            
            if (!empty($city_filter)) {
                $where_clause .= " AND l.city = %s";
                $where_params[] = $city_filter;
            }
            
            if (!empty($reporting_id_filter)) {
                $where_clause .= " AND t.reporting_id = %s";
                $where_params[] = $reporting_id_filter;
            }
        }
    
        // Get available postcodes, trees, and cities for filters - only those with scan data and team access
        list($team_restriction, $team_params) = $this->build_team_access_restrictions();
        
        $postcodes = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT l.postcode 
            FROM {$this->log_table} l 
            JOIN {$this->main_table} t ON l.tracker_id = t.id 
            WHERE l.postcode IS NOT NULL AND l.postcode != '' {$team_restriction}
            ORDER BY l.postcode
        ", $team_params));
        
        $trees = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT l.tree 
            FROM {$this->log_table} l 
            JOIN {$this->main_table} t ON l.tracker_id = t.id 
            WHERE l.tree IS NOT NULL AND l.tree != '' {$team_restriction}
            ORDER BY l.tree
        ", $team_params));
        
        $cities = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT l.city 
            FROM {$this->log_table} l 
            JOIN {$this->main_table} t ON l.tracker_id = t.id 
            WHERE l.city IS NOT NULL AND l.city != '' {$team_restriction}
            ORDER BY l.city
        ", $team_params));
    
        $this->display_styles();
        $this->display_search_interface($search_terms, $view_type, $date_from, $date_to, $postcode_filter, $tree_filter, $city_filter, $reporting_id_filter, $postcodes, $trees, $cities);
        
        // Check if user wants to show all data
        $show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';
        
        // Only display data if there are active filters or show_all is requested
        $has_active_filters = !empty($postcode_filter) || !empty($tree_filter) || !empty($city_filter) || !empty($reporting_id_filter) || !empty($date_from) || !empty($date_to) || !empty($search_terms) || $show_all;
        
        if ($has_active_filters) {
            // Check if there are any results
            $result_count = $this->get_result_count($where_clause, $where_params);
            
            if ($result_count > 0) {
                // Show search context if search terms were used or show all is requested
                if (!empty($search_terms)) {
                    echo '<div style="background: #e7f3ff; border: 1px solid #0073aa; padding: 10px 15px; margin: 20px 0; border-radius: 4px;">';
                    echo '<strong>Search Results for:</strong> "' . esc_html(implode(', ', $search_terms)) . '" (' . number_format($result_count) . ' results found)';
                    echo '</div>';
                } elseif ($show_all) {
                    echo '<div style="background: #e7f3ff; border: 1px solid #0073aa; padding: 10px 15px; margin: 20px 0; border-radius: 4px;">';
                    echo '<strong>Showing All Data:</strong> (' . number_format($result_count) . ' results found)';
                    echo '</div>';
                }
                
                // Display summary statistics
                $this->display_summary_stats($where_clause, $where_params);
            
                if ($view_type == 'breakdown') {
                    $this->display_breakdown_report($where_clause, $where_params);
                } else {
                    $this->display_rollup_report($where_clause, $where_params);
                }
            } else {
                // Show no results message
                echo '<div style="background: #fff; border: 1px solid #ddd; padding: 30px; margin: 20px 0; border-radius: 4px; text-align: center;">';
                echo '<h2 style="color: #d63638; margin-bottom: 15px;">No Results Found</h2>';
                echo '<p style="font-size: 16px; color: #666; margin-bottom: 20px;">';
                echo 'No QR code data found for your search criteria.';
                echo '</p>';
                echo '<div style="margin: 20px 0;">';
                echo '<strong>Current filters:</strong><br>';
                if (!empty($postcode_filter)) echo 'Postcode: ' . esc_html($postcode_filter) . '<br>';
                if (!empty($city_filter)) echo 'City: ' . esc_html($city_filter) . '<br>';
                if (!empty($tree_filter)) echo 'Tree: ' . esc_html($tree_filter) . '<br>';
                if (!empty($date_from)) echo 'From: ' . esc_html($date_from) . '<br>';
                if (!empty($date_to)) echo 'To: ' . esc_html($date_to) . '<br>';
                echo '</div>';
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-reports')) . '" class="button button-primary">Try a Different Search</a></p>';
                echo '</div>';
            }
        } else {
            // Show welcome message when no filters are active
            $this->display_welcome_message();
        }
    
        echo '</div>';
    }

    /**
     * Display CSS styles
     */
    private function display_styles() {
        echo '<style>
        .qr-filter-form { background: #f9f9f9; padding: 15px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px; }
        .qr-filter-form select, .qr-filter-form input[type="date"] { margin-right: 10px; padding: 5px 28px 5px 8px !important; min-width: 90px; background: #fff; appearance: auto; -webkit-appearance: auto; -moz-appearance: auto; }
        .qr-filter-form label { display: inline-block; margin-right: 5px; font-weight: bold; }
        .qr-stats-summary { display: flex; gap: 20px; margin: 20px 0; }
        .qr-stat-box { background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 4px; flex: 1; text-align: center; }
        .qr-stat-number { font-size: 24px; font-weight: bold; color: #0073aa; }
        .qr-stat-label { color: #666; margin-top: 5px; }
        .qr-search-box { background: #fff; border: 2px solid #0073aa; border-radius: 4px; padding: 15px; margin-bottom: 20px; }
        .qr-search-input { flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .qr-search-input:focus { outline: none; border-color: #0073aa; box-shadow: 0 0 0 1px #0073aa; }
        .qr-welcome-message { background: #fff; border: 1px solid #ddd; padding: 30px; margin: 20px 0; border-radius: 4px; text-align: center; }
        .qr-search-suggestions { position: absolute; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); z-index: 1000; max-width: 300px; }
        .qr-search-suggestion-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee; display: flex; align-items: center; }
        .qr-search-suggestion-item:hover { background-color: #f0f0f0; }
        .qr-search-suggestion-item:last-child { border-bottom: none; }
        .suggestion-type { font-size: 14px; }
        .city-link { color: #0073aa; text-decoration: none; font-weight: 500; }
        .city-link:hover { color: #005a87; text-decoration: underline; }
        .reporting-link { color: #28a745; text-decoration: none; font-weight: 500; }
        .reporting-link:hover { color: #1e7e34; text-decoration: underline; }
        .search-term-tag { display: inline-block; background: #0073aa; color: white; padding: 4px 8px; margin: 2px; border-radius: 12px; font-size: 12px; }
        .remove-term { cursor: pointer; margin-left: 5px; font-weight: bold; }
        .remove-term:hover { color: #ff6b6b; }
        </style>';
    }

    /**
     * Display search interface
     */
    private function display_search_interface($search_terms, $view_type, $date_from, $date_to, $postcode_filter, $tree_filter, $city_filter, $reporting_id_filter, $postcodes, $trees, $cities) {
        $show_all = isset($_GET['show_all']) && $_GET['show_all'] === '1';

        // Include Chart.js
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        
        // Add search functionality JavaScript
        echo '<script>
        // Define ajaxurl for admin area
        var ajaxurl = "' . admin_url('admin-ajax.php') . '";
        var currentSearchTerms = ' . wp_json_encode($search_terms ?: []) . ';
        
        // Make functions globally available
        window.addSearchTerm = function() {
            const searchInput = document.querySelector("input[name=\'search_input\']");
            const term = searchInput.value.trim();
            
            if (term && !currentSearchTerms.includes(term)) {
                currentSearchTerms.push(term);
                updateSearchTermsDisplay();
                searchInput.value = "";
                updateHiddenInput();
            }
        };
        
        window.removeSearchTerm = function(term) {
            currentSearchTerms = currentSearchTerms.filter(t => t !== term);
            updateSearchTermsDisplay();
            updateHiddenInput();
        };
        
        function updateSearchTermsDisplay() {
            const container = document.querySelector("#search-terms-container");
            if (!container) return;
            
            container.innerHTML = "";
            
            currentSearchTerms.forEach(term => {
                const termElement = document.createElement("span");
                termElement.className = "search-term-tag";
                
                const removeButton = document.createElement("span");
                removeButton.className = "remove-term";
                removeButton.innerHTML = "&times;";
                removeButton.onclick = function() {
                    removeSearchTerm(term);
                };
                
                termElement.textContent = term + " ";
                termElement.appendChild(removeButton);
                container.appendChild(termElement);
            });
        }
        
        function updateHiddenInput() {
            const hiddenInput = document.querySelector("input[name=\'search_terms\']");
            if (hiddenInput) {
                hiddenInput.value = currentSearchTerms.join(",");
            }
        }
        
        function fetchSearchSuggestions(query) {
            const data = new FormData();
            data.append("action", "qr_tracker_search_suggestions");
            data.append("query", query);
            data.append("nonce", "' . wp_create_nonce('qr_tracker_search_nonce') . '");
            
            fetch(ajaxurl, {
                method: "POST",
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.length > 0) {
                    displaySuggestions(data.data);
                } else {
                    hideSearchSuggestions();
                }
            })
            .catch(error => {
                console.error("Error fetching suggestions:", error);
                hideSearchSuggestions();
            });
        }
        
        function displaySuggestions(suggestions) {
            hideSearchSuggestions();
            
            const searchInput = document.querySelector("input[name=\'search_input\']");
            if (!searchInput) return;
            
            const suggestionBox = document.createElement("div");
            suggestionBox.id = "search-suggestions";
            suggestionBox.className = "qr-search-suggestions";
            
            suggestions.forEach(suggestion => {
                const item = document.createElement("div");
                item.className = "qr-search-suggestion-item";
                
                // Create the suggestion content with type indicator
                const label = document.createElement("span");
                label.textContent = suggestion.label || suggestion.value;
                
                // Add type-specific styling
                if (suggestion.type) {
                    item.setAttribute("data-type", suggestion.type);
                    const typeIcon = document.createElement("span");
                    typeIcon.className = "suggestion-type";
                    typeIcon.textContent = suggestion.type === "postcode" ? "📮" : 
                                         suggestion.type === "city" ? "🏙️" : 
                                         suggestion.type === "reporting_id" ? "🆔" : "🌳";
                    typeIcon.style.marginRight = "8px";
                    item.appendChild(typeIcon);
                }
                
                item.appendChild(label);
                
                item.addEventListener("click", function() {
                    searchInput.value = suggestion.value;
                    addSearchTerm();
                    hideSearchSuggestions();
                });
                suggestionBox.appendChild(item);
            });
            
            const searchContainer = searchInput.parentElement;
            searchContainer.style.position = "relative";
            searchContainer.appendChild(suggestionBox);
        }
        
        function hideSearchSuggestions() {
            const existing = document.getElementById("search-suggestions");
            if (existing) {
                existing.remove();
            }
        }
        
        document.addEventListener("DOMContentLoaded", function() {
            const searchInput = document.querySelector("input[name=\'search_input\']");
            let searchTimeout;
            
            if (searchInput) {
                // Auto-submit form when Enter is pressed
                searchInput.addEventListener("keypress", function(e) {
                    if (e.key === "Enter") {
                        e.preventDefault();
                        addSearchTerm();
                    }
                });
                
                // Focus on search input when page loads
                searchInput.focus();
                
                // Add placeholder text animation
                const placeholders = [
                    "Enter postcode, city, tree value, or reporting ID...",
                    "Try: SW1A, London, Front door, ABC123...",
                    "Press Enter to add multiple search terms..."
                ];
                let placeholderIndex = 0;
                
                setInterval(function() {
                    searchInput.placeholder = placeholders[placeholderIndex];
                    placeholderIndex = (placeholderIndex + 1) % placeholders.length;
                }, 3000);
                
                // Add search suggestions on input with debouncing
                searchInput.addEventListener("input", function() {
                    const query = this.value.trim();
                    
                    // Clear previous timeout
                    clearTimeout(searchTimeout);
                    
                    if (query.length >= 2) {
                        // Debounce the AJAX call
                        searchTimeout = setTimeout(function() {
                            fetchSearchSuggestions(query);
                        }, 300);
                    } else {
                        hideSearchSuggestions();
                    }
                });
                
                // Hide suggestions when clicking outside
                document.addEventListener("click", function(e) {
                    if (!searchInput.contains(e.target)) {
                        hideSearchSuggestions();
                    }
                });
            }
            
            // Initialize search terms display
            updateSearchTermsDisplay();
        });
        </script>';
        
        echo '<div class="qr-filter-form">';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="qr-reports">';
        
        // Search box section
        echo '<div class="qr-search-box">';
        echo '<h3 style="margin: 0 0 10px 0; color: #0073aa;">Search & Filter</h3>';
        echo '<div style="margin-bottom: 10px;">';
        echo '<label style="font-weight: bold; display: block; margin-bottom: 5px;">Search Terms:</label>';
        echo '<div style="display: flex; align-items: center; gap: 10px;">';
        echo '<input type="text" name="search_input" placeholder="Enter postcode, city, tree value, or reporting ID..." class="qr-search-input">';
        echo '<input type="button" class="button button-secondary" value="Add Term" onclick="addSearchTerm()">';
        echo '<input type="submit" class="button button-primary" value="Search">';
        echo '</div>';
        echo '<input type="hidden" name="search_terms" value="' . esc_attr(implode(',', $search_terms)) . '">';
        echo '<div id="search-terms-container" style="margin-top: 10px; min-height: 30px;"></div>';
        echo '</div>';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
        echo '<div style="font-size: 12px; color: #666;">';
        echo 'Enter multiple search terms separated by pressing Enter. Supports postcodes, cities, tree values, and reporting IDs.';
        echo '</div>';
        echo '<div style="display: flex; gap: 10px;">';
        if ($show_all) {
            echo '<a href="' . remove_query_arg(['show_all']) . '" class="button button-secondary">Clear Filters</a>';
        } else {
            echo '<a href="' . add_query_arg(['show_all' => '1'], remove_query_arg(['search_terms', 'postcode', 'tree', 'city', 'reporting_id', 'date_from', 'date_to'])) . '" class="button button-secondary">Show All Data</a>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        // Only show detailed filters if there are active filters or search term
        $has_active_filters = !empty($postcode_filter) || !empty($tree_filter) || !empty($city_filter) || !empty($date_from) || !empty($date_to) || !empty($search_terms);
        
        if ($has_active_filters) {
            echo '<div style="margin-bottom: 10px;">';
            echo '<label>View:</label><select name="view"><option value="breakdown"' . ($view_type == 'breakdown' ? ' selected' : '') . '>Breakdown View</option><option value="rollup"' . ($view_type == 'rollup' ? ' selected' : '') . '>Rollup View</option></select>';
            echo '</div>';
            echo '<div style="margin-bottom: 10px;">';
            echo '<label>From:</label><input type="date" name="date_from" value="' . esc_attr($date_from) . '">';
            echo '<label>To:</label><input type="date" name="date_to" value="' . esc_attr($date_to) . '">';
            echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => date('Y-m-d', strtotime('-30 days')), 'date_to' => date('Y-m-d')]))) . '" class="button">Last 30 Days</a>';
            echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => date('Y-m-d', strtotime('-7 days')), 'date_to' => date('Y-m-d')]))) . '" class="button">Last 7 Days</a>';
            echo '<a href="' . esc_url(add_query_arg(array_merge($_GET, ['date_from' => '', 'date_to' => '']))) . '" class="button">All Time</a>';
            echo '</div>';
            echo '<div style="margin-bottom: 10px;">';
            echo '<label>Postcode:</label><select name="postcode" style="margin-right: 10px;"><option value="">All</option>';
            foreach ($postcodes as $postcode) {
                echo '<option value="' . esc_attr($postcode) . '"' . ($postcode_filter == $postcode ? ' selected' : '') . '>' . esc_html($postcode) . '</option>';
            }
            echo '</select>';
            echo '<label>Tree:</label><select name="tree" style="margin-right: 10px;"><option value="">All</option>';
            foreach ($trees as $tree) {
                echo '<option value="' . esc_attr($tree) . '"' . ($tree_filter == $tree ? ' selected' : '') . '>' . esc_html($tree) . '</option>';
            }
            echo '</select>';
            echo '<label>City:</label><select name="city" style="margin-right: 10px;"><option value="">All</option>';
            foreach ($cities as $city) {
                echo '<option value="' . esc_attr($city) . '"' . ($city_filter == $city ? ' selected' : '') . '>' . esc_html($city) . '</option>';
            }
            echo '</select>';
            echo '<input type="submit" class="button button-primary" value="Apply Filters">';
            echo ' <a href="' . esc_url(admin_url('admin.php?page=qr-reports')) . '" class="button">Reset Filters</a>';
            echo '</div>';
        }
        echo '</form>';
        echo '</div>';
    }

    /**
     * Display welcome message
     */
    private function display_welcome_message() {
        echo '<div class="qr-welcome-message">';
        echo '<h2 style="color: #0073aa; margin-bottom: 15px;">Welcome to QR Code Reports</h2>';
        echo '<p style="font-size: 16px; color: #666; margin-bottom: 20px;">';
        echo 'Use the search box above to find specific QR code data. You can search by:';
        echo '</p>';
        echo '<div style="display: flex; justify-content: center; gap: 20px; margin: 20px 0; flex-wrap: wrap;">';
        echo '<div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 4px; min-width: 120px;">';
        echo '<div style="font-size: 24px; color: #0073aa; margin-bottom: 5px;">📮</div>';
        echo '<strong>Postcode</strong><br>';
        echo '<small>e.g., "SW1A"</small>';
        echo '</div>';
        echo '<div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 4px; min-width: 120px;">';
        echo '<div style="font-size: 24px; color: #0073aa; margin-bottom: 5px;">🏙️</div>';
        echo '<strong>City</strong><br>';
        echo '<small>e.g., "London"</small>';
        echo '</div>';
        echo '<div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 4px; min-width: 120px;">';
        echo '<div style="font-size: 24px; color: #0073aa; margin-bottom: 5px;">🌳</div>';
        echo '<strong>Tree Value</strong><br>';
        echo '<small>e.g., "Front door"</small>';
        echo '</div>';
        echo '<div style="text-align: center; padding: 15px; background: #f9f9f9; border-radius: 4px; min-width: 120px;">';
        echo '<div style="font-size: 24px; color: #0073aa; margin-bottom: 5px;">🆔</div>';
        echo '<strong>Reporting ID</strong><br>';
        echo '<small>e.g., "ABC123"</small>';
        echo '</div>';
        echo '</div>';
        echo '<p style="font-size: 14px; color: #888;">';
        echo 'Once you search, detailed filters and reports will appear below.';
        echo '</p>';
        
        // Show popular searches if available
        $popular_searches = $this->search->get_popular_searches();
        $recent_searches = $this->search->get_recent_searches();
        
        if (!empty($popular_searches) || !empty($recent_searches)) {
            echo '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">';
            
            if (!empty($recent_searches)) {
                echo '<div style="margin-bottom: 15px;">';
                echo '<h4 style="margin: 0 0 10px 0; color: #0073aa;">Recent Searches:</h4>';
                echo '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
                foreach ($recent_searches as $search) {
                    $search_url = add_query_arg(['page' => 'qr-reports', 'search' => $search], admin_url('admin.php'));
                    echo '<a href="' . esc_url($search_url) . '" class="button button-secondary" style="font-size: 12px;">' . esc_html($search) . '</a>';
                }
                echo '</div>';
                echo '</div>';
            }
            
            if (!empty($popular_searches)) {
                echo '<div>';
                echo '<h4 style="margin: 0 0 10px 0; color: #0073aa;">Popular Searches:</h4>';
                echo '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
                foreach ($popular_searches as $search) {
                    $search_url = add_query_arg(['page' => 'qr-reports', 'search' => $search], admin_url('admin.php'));
                    echo '<a href="' . esc_url($search_url) . '" class="button button-secondary" style="font-size: 12px;">' . esc_html($search) . '</a>';
                }
                echo '</div>';
                echo '</div>';
            }
            
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Get the count of results for the current filters
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     * @return int Number of results
     */
    private function get_result_count($where_clause, $where_params) {
        global $wpdb;
        
        $sql = "SELECT COUNT(DISTINCT l.id) FROM {$this->log_table} l JOIN {$this->main_table} t ON l.tracker_id = t.id {$where_clause}";
        
        if (!empty($where_params)) {
            return (int) $wpdb->get_var($wpdb->prepare($sql, $where_params));
        } else {
            return (int) $wpdb->get_var($sql);
        }
    }

    /**
     * Display summary statistics
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     */
    private function display_summary_stats($where_clause, $where_params) {
        global $wpdb;
        
        // Get summary statistics
        $sql = "SELECT 
                    COUNT(DISTINCT l.id) as total_scans,
                    COUNT(DISTINCT l.tracker_id) as unique_qr_codes,
                    COUNT(DISTINCT DATE(l.scanned_at)) as unique_days
                FROM {$this->log_table} l 
                JOIN {$this->main_table} t ON l.tracker_id = t.id 
                {$where_clause}";
        
        if (!empty($where_params)) {
            $stats = $wpdb->get_row($wpdb->prepare($sql, $where_params));
        } else {
            $stats = $wpdb->get_row($sql);
        }
        
        if ($stats && $stats->total_scans > 0) {
            // Calculate average scans per day
            $avg_scans_per_day = $stats->unique_days > 0 ? $stats->total_scans / $stats->unique_days : 0;
            
            echo '<div class="qr-stats-summary">';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->total_scans) . '</div>';
            echo '<div class="qr-stat-label">Total Scans</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->unique_qr_codes) . '</div>';
            echo '<div class="qr-stat-label">Unique QR Codes</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($stats->unique_days) . '</div>';
            echo '<div class="qr-stat-label">Active Days</div>';
            echo '</div>';
            echo '<div class="qr-stat-box">';
            echo '<div class="qr-stat-number">' . number_format($avg_scans_per_day, 1) . '</div>';
            echo '<div class="qr-stat-label">Avg Scans/Day</div>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning"><p>No scan data found for the selected filters.</p></div>';
        }
    }

    /**
     * Display breakdown report
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     */
    private function display_breakdown_report($where_clause, $where_params) {
        // Use the existing admin class instance if available, otherwise create a new one
        global $wpdb;
        
        // Get the data for breakdown report
        $sql = "SELECT 
                    l.tracker_id,
                    t.reporting_id,
                    t.postcode,
                    t.city,
                    t.tree,
                    COUNT(*) as scan_count,
                    MIN(l.scanned_at) as first_scan,
                    MAX(l.scanned_at) as last_scan
                FROM {$this->log_table} l 
                JOIN {$this->main_table} t ON l.tracker_id = t.id 
                {$where_clause}
                GROUP BY l.tracker_id, t.reporting_id, t.postcode, t.city, t.tree
                ORDER BY scan_count DESC";
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        if (!empty($results)) {
            echo '<h2>Breakdown Report</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>QR Code ID</th>';
            echo '<th>Reporting ID</th>';
            echo '<th>Postcode</th>';
            echo '<th>City</th>';
            echo '<th>Tree</th>';
            echo '<th>Scan Count</th>';
            echo '<th>First Scan</th>';
            echo '<th>Last Scan</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($results as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->tracker_id) . '</td>';
                
                // Make reporting ID clickable with link to reporting ID report
                if (!empty($row->reporting_id)) {
                    $reporting_url = add_query_arg([
                        'page' => 'qr-reporting-id-report',
                        'reporting_id' => urlencode($row->reporting_id)
                    ], admin_url('admin.php'));
                    echo '<td><a href="' . esc_url($reporting_url) . '" class="reporting-link">' . esc_html($row->reporting_id) . '</a></td>';
                } else {
                    echo '<td>' . esc_html($row->reporting_id) . '</td>';
                }
                
                echo '<td>' . esc_html($row->postcode) . '</td>';
                
                // Make city clickable with link to city report
                if (!empty($row->city)) {
                    $city_url = add_query_arg([
                        'page' => 'qr-city-report',
                        'city' => urlencode($row->city)
                    ], admin_url('admin.php'));
                    echo '<td><a href="' . esc_url($city_url) . '" class="city-link">' . esc_html($row->city) . '</a></td>';
                } else {
                    echo '<td>' . esc_html($row->city) . '</td>';
                }
                
                echo '<td>' . esc_html($row->tree) . '</td>';
                echo '<td>' . number_format($row->scan_count) . '</td>';
                echo '<td>' . esc_html($row->first_scan) . '</td>';
                echo '<td>' . esc_html($row->last_scan) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            
            // Add charts section
            $this->display_charts($where_clause, $where_params);
        } else {
            echo '<p>No data found for the selected filters.</p>';
        }
    }

    /**
     * Display rollup report
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     */
    private function display_rollup_report($where_clause, $where_params) {
        global $wpdb;
        
        // Get rollup data by date
        $sql = "SELECT 
                    DATE(l.scanned_at) as scan_date,
                    COUNT(*) as total_scans,
                    COUNT(DISTINCT l.tracker_id) as unique_qr_codes
                FROM {$this->log_table} l 
                JOIN {$this->main_table} t ON l.tracker_id = t.id 
                {$where_clause}
                GROUP BY DATE(l.scanned_at)
                ORDER BY scan_date DESC";
        
        if (!empty($where_params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $where_params));
        } else {
            $results = $wpdb->get_results($sql);
        }
        
        if (!empty($results)) {
            echo '<h2>Rollup Report</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Date</th>';
            echo '<th>Total Scans</th>';
            echo '<th>Unique QR Codes</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($results as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->scan_date) . '</td>';
                echo '<td>' . number_format($row->total_scans) . '</td>';
                echo '<td>' . number_format($row->unique_qr_codes) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No data found for the selected filters.</p>';
        }
    }

    /**
     * Display charts section
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     */
    private function display_charts($where_clause, $where_params) {
        // Include Chart.js
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
        
        // Get chart data
        $time_series_data = $this->get_time_series_data($where_clause, $where_params);
        $hour_data = $this->get_hour_distribution_data($where_clause, $where_params);
        $day_data = $this->get_day_of_week_distribution_data($where_clause, $where_params);
        
        echo '<div style="margin: 20px 0;">';
        echo '<h2>Charts</h2>';
        echo '<button onclick="showLineChart()" class="button">Show Daily Activity Chart</button> ';
        echo '<button onclick="showRadarChart()" class="button">Show Hour Distribution Chart</button> ';
        echo '<button onclick="showBarChart()" class="button">Show Day of Week Chart</button>';
        echo '</div>';
        
        // Line chart container
        echo '<div id="line-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Daily Activity Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'line-chart\', \'daily-activity-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="line-chart" width="800" height="400"></canvas>';
        echo '</div>';
        
        // Radar chart container
        echo '<div id="radar-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 700px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Hour Distribution Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'radar-chart\', \'hour-distribution-chart\')" class="button button-secondary">Export as Image</button>';
        echo '</div>';
        echo '<canvas id="radar-chart" width="600" height="600"></canvas>';
        echo '</div>';
        
        // Bar chart container
        echo '<div id="bar-chart-container" style="display: none; margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; height: 500px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h3 style="margin: 0;">Day of Week Chart</h3>';
        echo '<button onclick="exportChartAsImage(\'bar-chart\', \'day-of-week-chart\')" class="button button-secondary">Export as Image</button>';
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
        echo 'title: { display: true, text: "Daily Scan Activity" }';
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
        echo 'title: { display: true, text: "Scan Distribution by Hour of Day" }';
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
        echo 'title: { display: true, text: "Scan Distribution by Day of Week" }';
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
     * Get time series data for charts
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     * @return array Array with dates and values
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
     * Get hour distribution data for charts
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     * @return array Array with hour distribution data
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
     * Get day of week distribution data for charts
     * @param string $where_clause WHERE clause for the query
     * @param array $where_params Parameters for the WHERE clause
     * @return array Array with day of week distribution data
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

    /**
     * Helper function for JavaScript range
     */
    private function range($start, $end) {
        $result = [];
        for ($i = $start; $i <= $end; $i++) {
            $result[] = $i;
        }
        return $result;
    }
} 