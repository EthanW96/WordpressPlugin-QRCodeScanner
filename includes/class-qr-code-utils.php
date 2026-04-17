<?php

class QRCodeTracker_Utils {
    private const MAX_GENERATION_ATTEMPTS = 30;

    public static function generate_unique_code($table_name, $column_name = 'unique_code', $length = 6) {
        global $wpdb;

        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';

        for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $characters[wp_rand(0, strlen($characters) - 1)];
            }

            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE {$column_name} = %s",
                $code
            ));

            if ($exists === 0) {
                return $code;
            }
        }

        // Fallback keeps at least 8 chars to reduce collision risk if random retries are exhausted.
        return substr(wp_hash(uniqid((string) wp_rand(), true)), 0, max(8, $length));
    }
}
