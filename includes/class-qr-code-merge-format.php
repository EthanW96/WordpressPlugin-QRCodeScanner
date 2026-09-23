<?php
/**
 * Display and form helpers shared by the merge screens.
 *
 * Every method that returns markup returns it already escaped.
 */
class QRCodeTracker_Merge_Format {

    // Characters of a message shown before it is truncated in a grid cell.
    const MESSAGE_PREVIEW_LENGTH = 80;

    private $teams;
    private $planner;
    private $team_names = null;

    public function __construct($teams, QRCodeTracker_Merge_Planner $planner) {
        $this->teams   = $teams;
        $this->planner = $planner;
    }

    public function planner() {
        return $this->planner;
    }

    /**
     * "POSTCODE / city / Tree (ID 12)" — escaped.
     */
    public function describe($row) {
        return esc_html($this->planner->describe($row));
    }

    /**
     * A field value as a short, human-readable, escaped string.
     */
    public function value($field, $value) {
        switch ($field) {
            case 'team_id':
                return esc_html($this->team_name($value));
            case 'show_popup':
            case 'show_shop_link':
                return ((int) $value === 1) ? 'Yes' : 'No';
            case 'message_1':
            case 'message_2':
                return $this->message_preview($value);
            default:
                return ((string) $value === '') ? '<em class="qr-merge-empty">(empty)</em>' : esc_html((string) $value);
        }
    }

    public function datetime($value) {
        return empty($value) ? '<em class="qr-merge-empty">Never</em>' : esc_html((string) $value);
    }

    private function message_preview($html) {
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $html)));
        if ($text === '') {
            return '<em class="qr-merge-empty">(empty)</em>';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > self::MESSAGE_PREVIEW_LENGTH) {
            $text = mb_substr($text, 0, self::MESSAGE_PREVIEW_LENGTH) . '…';
        }
        return esc_html($text);
    }

    private function team_name($team_id) {
        if (empty($team_id)) {
            return 'No team';
        }
        if ($this->team_names === null) {
            $this->team_names = [];
            foreach ((array) $this->teams->get_all_teams() as $team) {
                $this->team_names[(int) $team->id] = $team->name;
            }
        }
        return isset($this->team_names[(int) $team_id]) ? $this->team_names[(int) $team_id] : 'Team #' . (int) $team_id;
    }

    /**
     * "12 visits, 2025-11-01 → 2025-12-24", or "none".
     */
    public function range(array $range) {
        if ((int) $range['count'] === 0) {
            return 'none';
        }
        $text = number_format((int) $range['count']) . ' log row' . ((int) $range['count'] === 1 ? '' : 's');
        if (!empty($range['from'])) {
            $text .= ', ' . substr($range['from'], 0, 10) . ' → ' . substr($range['to'], 0, 10);
        }
        return esc_html($text);
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    /**
     * The normalised plan, in the shape the form submits.
     *
     * Every later step carries this rather than the raw input, so the dry
     * run, its "Back" button and the final commit all work from exactly the
     * plan that was shown.
     */
    public function plan_to_input(array $plan) {
        return [
            'ids'          => $plan['ids'],
            'role'         => $plan['roles'],
            'configured'   => 1,
            'field'        => $plan['fields'],
            'alloc_scan'   => $plan['alloc'][QRCodeTracker_Merge_Planner::TYPE_SCAN],
            'alloc_social' => $plan['alloc'][QRCodeTracker_Merge_Planner::TYPE_SOCIAL],
            'alias'        => $plan['alias'],
            'rewrite_logs' => $plan['rewrite_logs'] ? 1 : 0,
            'seen'         => $plan['seen'],
        ];
    }

    /**
     * Hidden inputs for a nested array, e.g. qr_merge[alloc_scan][4][7] = 12.
     */
    public function hidden_inputs(array $data, $prefix) {
        $html = '';
        foreach ($data as $key => $value) {
            $name = $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $html .= $this->hidden_inputs($value, $name);
                continue;
            }
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        return $html;
    }

    /**
     * Opening tag and nonce for a step form, plus the stage it submits to.
     *
     * Pass a null stage for forms whose submit buttons carry the stage
     * themselves (a form with both "Back" and "Continue").
     */
    public function form_open($stage, $class = '') {
        $html  = '<form method="post" action="' . esc_url(QRCodeTracker_Merge_Page::url()) . '" class="' . esc_attr($class) . '">';
        $html .= wp_nonce_field(QRCodeTracker_Merge_Page::NONCE_ACTION, '_wpnonce', true, false);
        if ($stage !== null) {
            $html .= '<input type="hidden" name="qr_merge_stage" value="' . esc_attr($stage) . '">';
        }
        return $html;
    }

    /**
     * A submit button that moves to the given stage.
     */
    public function stage_button($stage, $label, $class = 'button') {
        return '<button type="submit" name="qr_merge_stage" value="' . esc_attr($stage) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
    }

    /**
     * Error or notice boxes. Each message is plain text.
     *
     * @param string[] $messages
     */
    public function notices(array $messages, $level = 'error') {
        $html = '';
        foreach ($messages as $message) {
            $html .= '<div class="notice notice-' . esc_attr($level) . ' inline qr-merge-notice"><p>' . esc_html($message) . '</p></div>';
        }
        return $html;
    }

    public function steps($current) {
        $steps = [
            QRCodeTracker_Merge_Page::STAGE_SELECT    => '1. Select',
            QRCodeTracker_Merge_Page::STAGE_ROLES     => '2. Keep or remove',
            QRCodeTracker_Merge_Page::STAGE_CONFIGURE => '3. Choose what to keep',
            QRCodeTracker_Merge_Page::STAGE_PREVIEW   => '4. Dry run',
        ];

        $html = '<ol class="qr-merge-steps">';
        foreach ($steps as $stage => $label) {
            $class = ($stage === $current) ? ' class="is-current"' : '';
            $html .= '<li' . $class . '>' . esc_html($label) . '</li>';
        }
        return $html . '</ol>';
    }
}
