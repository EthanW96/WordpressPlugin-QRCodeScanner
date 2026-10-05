<?php
/**
 * Minimal Mailchimp Marketing API client.
 *
 * The API key is never logged or echoed; error messages carry Mailchimp's
 * explanation only.
 */
class QRCodeTracker_Mailchimp_Client {

    const TIMEOUT_SECONDS = 20;

    private $api_key;
    private $base_url;

    public function __construct($api_key) {
        $this->api_key  = trim((string) $api_key);
        $data_center    = self::data_center($this->api_key);
        $this->base_url = $data_center !== '' ? 'https://' . $data_center . '.api.mailchimp.com/3.0' : '';
    }

    /**
     * Mailchimp keys end in "-<data centre>", e.g. "...-us21".
     */
    public static function data_center($api_key) {
        $dash = strrpos((string) $api_key, '-');
        if ($dash === false) {
            return '';
        }
        $data_center = substr($api_key, $dash + 1);
        return preg_match('/^[a-z]+[0-9]+$/', $data_center) ? $data_center : '';
    }

    public static function subscriber_hash($email) {
        return md5(strtolower(trim((string) $email)));
    }

    /**
     * @return array ['ok' => bool, 'status' => int, 'data' => array, 'error' => string]
     */
    public function request($method, $path, ?array $body = null) {
        if ($this->base_url === '') {
            return $this->failure(0, 'The API key is not a valid Mailchimp key (it should end in something like "-us21").');
        }

        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode('qr-tracker:' . $this->api_key),
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->base_url . $path, $args);
        if (is_wp_error($response)) {
            return $this->failure(0, 'Could not reach Mailchimp: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);
        $data   = is_array($data) ? $data : [];

        if ($status < 200 || $status >= 300) {
            $detail = trim(($data['title'] ?? '') . ': ' . ($data['detail'] ?? ''), ': ');
            return $this->failure($status, 'Mailchimp returned ' . $status . ($detail !== '' ? ' — ' . $detail : '') . '.');
        }

        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
    }

    private function failure($status, $message) {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $message];
    }

    /**
     * @return array ['ok', 'lists' => [id => name], 'error']
     */
    public function get_lists() {
        $response = $this->request('GET', '/lists?count=100&fields=lists.id,lists.name');
        if (!$response['ok']) {
            return ['ok' => false, 'lists' => [], 'error' => $response['error']];
        }

        $lists = [];
        foreach ($response['data']['lists'] ?? [] as $list) {
            $lists[(string) $list['id']] = (string) $list['name'];
        }
        return ['ok' => true, 'lists' => $lists, 'error' => ''];
    }

    /**
     * @return array ['ok', 'tags' => string[], 'error']
     */
    public function get_merge_field_tags($list_id) {
        $response = $this->request('GET', '/lists/' . rawurlencode($list_id) . '/merge-fields?count=100&fields=merge_fields.tag');
        if (!$response['ok']) {
            return ['ok' => false, 'tags' => [], 'error' => $response['error']];
        }
        return ['ok' => true, 'tags' => array_column($response['data']['merge_fields'] ?? [], 'tag'), 'error' => ''];
    }

    public function create_merge_field($list_id, $tag, $name, $type) {
        return $this->request('POST', '/lists/' . rawurlencode($list_id) . '/merge-fields', [
            'tag'    => $tag,
            'name'   => $name,
            'type'   => $type,
            'public' => false,
        ]);
    }

    // Members fetched per page when reading an audience's first names.
    const MEMBERS_PAGE_SIZE = 1000;

    /**
     * Every member of an audience that has a first name, so a sync can avoid
     * overwriting it.
     *
     * @return array ['ok', 'names' => [lowercased email => first name], 'error']
     */
    public function get_first_names($list_id) {
        $names  = [];
        $offset = 0;

        do {
            $response = $this->request('GET', sprintf(
                '/lists/%s/members?count=%d&offset=%d&fields=total_items,members.email_address,members.merge_fields.FNAME',
                rawurlencode($list_id), self::MEMBERS_PAGE_SIZE, $offset
            ));
            if (!$response['ok']) {
                return ['ok' => false, 'names' => [], 'error' => $response['error']];
            }

            $members = $response['data']['members'] ?? [];
            foreach ($members as $member) {
                $first_name = trim((string) ($member['merge_fields']['FNAME'] ?? ''));
                if ($first_name !== '') {
                    $names[strtolower((string) $member['email_address'])] = $first_name;
                }
            }
            $offset += self::MEMBERS_PAGE_SIZE;
            $total   = (int) ($response['data']['total_items'] ?? 0);
        } while (!empty($members) && $offset < $total);

        return ['ok' => true, 'names' => $names, 'error' => ''];
    }

    public function start_batch(array $operations) {
        return $this->request('POST', '/batches', ['operations' => $operations]);
    }

    public function get_batch($batch_id) {
        return $this->request('GET', '/batches/' . rawurlencode($batch_id));
    }
}
