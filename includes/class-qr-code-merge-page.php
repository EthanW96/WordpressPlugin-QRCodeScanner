<?php
require_once __DIR__ . '/class-qr-code-merge-planner.php';
require_once __DIR__ . '/class-qr-code-merge-executor.php';
require_once __DIR__ . '/class-qr-code-merge-format.php';
require_once __DIR__ . '/class-qr-code-merge-view.php';
require_once __DIR__ . '/class-qr-code-merge-preview-view.php';

/**
 * The "Merge QR Codes" admin screen.
 *
 * A merge moves through five steps, each a POST carrying the whole plan
 * forward in hidden inputs:
 *
 *   select → roles → configure → preview (the dry run) → commit
 *
 * Nothing is written until commit, and commit re-plans from the database and
 * refuses to write if the result differs from the dry run that was confirmed.
 */
class QRCodeTracker_Merge_Page {

    const PAGE_SLUG    = 'qr-merge';
    const NONCE_ACTION = 'qr_tracker_merge';
    const INPUT_KEY    = 'qr_merge';

    const STAGE_SELECT    = 'select';
    const STAGE_ROLES     = 'roles';
    const STAGE_CONFIGURE = 'configure';
    const STAGE_PREVIEW   = 'preview';
    const STAGE_COMMIT    = 'commit';

    private $teams;
    private $planner;
    private $view;
    private $preview_view;

    public function __construct($teams) {
        $this->teams        = $teams;
        $this->planner      = new QRCodeTracker_Merge_Planner($teams);
        $format             = new QRCodeTracker_Merge_Format($teams, $this->planner);
        $this->view         = new QRCodeTracker_Merge_View($format);
        $this->preview_view = new QRCodeTracker_Merge_Preview_View($format);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public static function can_merge() {
        return QRCodeTracker_Permissions::can_edit_qr_codes() && QRCodeTracker_Permissions::can_delete_qr_codes();
    }

    public static function url(array $args = []) {
        return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $args), admin_url('admin.php'));
    }

    public function enqueue_assets($hook) {
        if (substr((string) $hook, -strlen(self::PAGE_SLUG)) !== self::PAGE_SLUG) {
            return;
        }

        $base_url  = plugin_dir_url(dirname(__FILE__));
        $base_path = plugin_dir_path(dirname(__FILE__));

        wp_enqueue_style(
            'qr-tracker-merge',
            $base_url . 'assets/css/qr-tracker-merge.css',
            [],
            filemtime($base_path . 'assets/css/qr-tracker-merge.css') ?: '1.0.0'
        );
        wp_enqueue_script(
            'qr-tracker-merge',
            $base_url . 'assets/js/qr-tracker-merge.js',
            [],
            filemtime($base_path . 'assets/js/qr-tracker-merge.js') ?: '1.0.0',
            true
        );
    }

    public function render() {
        if (!self::can_merge()) {
            QRCodeTracker_Permissions::display_permission_denied_notice('qr_tracker_delete_qr_codes');
            return;
        }

        echo '<div class="wrap qr-merge">';
        echo '<h1>Merge QR Codes</h1>';

        $stage = $this->current_stage();
        if ($stage !== self::STAGE_SELECT) {
            check_admin_referer(self::NONCE_ACTION);
        }

        $this->dispatch($stage, $this->read_input());
        echo '</div>';
    }

    private function current_stage() {
        if (empty($_POST['qr_merge_stage'])) {
            return self::STAGE_SELECT;
        }

        $stage   = sanitize_key(wp_unslash($_POST['qr_merge_stage']));
        $allowed = [self::STAGE_SELECT, self::STAGE_ROLES, self::STAGE_CONFIGURE, self::STAGE_PREVIEW, self::STAGE_COMMIT];
        return in_array($stage, $allowed, true) ? $stage : self::STAGE_SELECT;
    }

    /**
     * The submitted plan. The planner sanitises every value it uses, so this
     * only unslashes; nothing here is trusted beyond that.
     */
    private function read_input() {
        if (!isset($_POST[self::INPUT_KEY]) || !is_array($_POST[self::INPUT_KEY])) {
            return [];
        }
        return wp_unslash($_POST[self::INPUT_KEY]);
    }

    private function dispatch($stage, array $input) {
        switch ($stage) {
            case self::STAGE_ROLES:
                $this->show_roles($input);
                return;
            case self::STAGE_CONFIGURE:
                $this->show_configure($input);
                return;
            case self::STAGE_PREVIEW:
                $this->show_preview($input);
                return;
            case self::STAGE_COMMIT:
                $this->commit($input);
                return;
            default:
                $this->show_select($input);
        }
    }

    // ------------------------------------------------------------------
    // Stages
    // ------------------------------------------------------------------

    private function show_select(array $input, array $errors = []) {
        $preselected = isset($input['ids']) ? array_map('absint', (array) $input['ids']) : [];
        if (empty($preselected) && isset($_GET['merge_id'])) {
            $preselected = [absint($_GET['merge_id'])];
        }

        $this->view->render_select($this->selectable_codes(), $preselected, $errors);
    }

    private function show_roles(array $input) {
        // Roles are chosen fresh: any later choices are dropped, so changing
        // roles can never leave allocations pointing at codes no longer kept.
        $dry_run = $this->planner->build(['ids' => $input['ids'] ?? [], 'role' => $input['role'] ?? []]);
        if (empty($dry_run['plan'])) {
            $this->show_select($input, $dry_run['errors']);
            return;
        }

        $this->view->render_roles($dry_run);
    }

    private function show_configure(array $input) {
        $dry_run = $this->planner->build(['ids' => $input['ids'] ?? [], 'role' => $input['role'] ?? []] + $this->configure_input($input));
        if (!empty($this->role_errors($dry_run)) || empty($dry_run['plan'])) {
            $this->show_roles($input);
            return;
        }

        $this->view->render_configure($dry_run);
    }

    private function show_preview(array $input, array $notices = []) {
        $dry_run = $this->planner->build($input);
        if (empty($dry_run['plan'])) {
            $this->show_select($input, $dry_run['errors']);
            return;
        }

        $this->preview_view->render($dry_run, $notices);
    }

    private function commit(array $input) {
        $fingerprint   = isset($_POST['qr_merge_fingerprint']) ? sanitize_text_field(wp_unslash($_POST['qr_merge_fingerprint'])) : '';
        $confirm_count = isset($_POST['qr_merge_confirm']) ? absint($_POST['qr_merge_confirm']) : -1;

        $executor = new QRCodeTracker_Merge_Executor($this->planner);
        $result   = $executor->execute($input, $fingerprint, $confirm_count);

        if (!$result['ok']) {
            $this->show_preview($input, [['level' => 'error', 'message' => $result['message']]]);
            return;
        }

        $this->preview_view->render_success($result);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Errors that belong to the roles step (as opposed to later choices).
     */
    private function role_errors(array $dry_run) {
        if (empty($dry_run['plan'])) {
            return [];
        }
        $plan = $dry_run['plan'];
        if (empty($plan['keep_ids'])) {
            return ['Mark at least one QR code to keep.'];
        }
        if (empty($plan['remove_ids'])) {
            return ['Mark at least one QR code to remove — otherwise there is nothing to merge.'];
        }
        return [];
    }

    /**
     * Only the configure-step choices, used when arriving at configure from
     * the dry run's "Back" button so earlier choices are kept.
     */
    private function configure_input(array $input) {
        $keys = ['configured', 'field', 'alloc_scan', 'alloc_social', 'alias', 'rewrite_logs', 'seen'];
        return array_intersect_key($input, array_flip($keys));
    }

    /**
     * QR codes this user may merge: those they can access, with every team
     * check the planner will repeat at commit.
     */
    private function selectable_codes() {
        $codes = $this->teams->get_accessible_qr_codes();
        return is_array($codes) ? $codes : [];
    }
}
