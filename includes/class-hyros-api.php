<?php
defined('ABSPATH') || exit;

/**
 * Hyros API wrapper.
 * Base URL: https://api.hyros.com/v1/api/v1.0
 * Auth: API-Key header.
 */
class Hyros_API {

    private string $api_key;

    public function __construct(string $api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Build common request args.
     */
    private function base_args(string $method = 'GET', array $body = []): array {
        $args = [
            'method'    => $method,
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'API-Key'      => $this->api_key,
            ],
        ];
        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }
        return $args;
    }

    /**
     * Normalize any Hyros/WP response into one shape.
     */
    private function request(string $method, string $path, array $payload = [], bool $expect_json = true): array {
        $url      = HYROS_WOO_API_BASE . $path;
        $args     = $this->base_args($method, $payload);
        $response = 'GET' === $method ? wp_remote_get($url, $args) : wp_remote_request($url, $args);

        $result = [
            'success'     => false,
            'status_code' => 0,
            'data'        => $expect_json ? [] : '',
            'error'       => '',
            'retryable'   => false,
            'retry_after' => 0,
        ];
        if (is_wp_error($response)) {
            $result['error']     = $response->get_error_message();
            $result['retryable'] = true;
            return $result;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $body   = $expect_json ? json_decode($raw, true) : $raw;

        $result['status_code'] = $status;
        $result['data']        = is_array($body) || is_string($body) ? $body : [];

        if ($status >= 200 && $status < 300) {
            $result['success'] = true;
            return $result;
        }

        $result['retryable'] = (429 === $status || $status >= 500);
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        if (!empty($retry_after) && is_numeric($retry_after)) {
            $result['retry_after'] = max(0, (int) $retry_after);
        }

        if ($expect_json && is_array($body)) {
            if (isset($body['message'])) {
                $result['error'] = is_array($body['message']) ? implode(', ', array_map('strval', $body['message'])) : (string) $body['message'];
            } else {
                $result['error'] = 'HTTP ' . $status;
            }
        } else {
            $result['error'] = !empty($raw) ? trim($raw) : 'HTTP ' . $status;
        }

        return $result;
    }

    /**
     * POST /orders — track a new sale.
     *
     * @param array $payload
     */
    public function send_order(array $payload): array {
        $response = $this->request('POST', '/orders', $payload, true);
        if (!$response['success']) {
            return [
                'success'     => false,
                'hyros_id'    => '',
                'request_id'  => '',
                'error'       => $response['error'],
                'status_code' => $response['status_code'],
                'retryable'   => $response['retryable'],
                'retry_after' => $response['retry_after'],
            ];
        }

        $body = is_array($response['data']) ? $response['data'] : [];
        return [
            'success'     => true,
            'hyros_id'    => self::extract_hyros_id($body),
            'request_id'  => isset($body['request_id']) ? (string) $body['request_id'] : '',
            'error'       => '',
            'status_code' => $response['status_code'],
            'retryable'   => false,
            'retry_after' => 0,
        ];
    }

    /**
     * DELETE /orders/{orderId} — refund an order.
     *
     * @param string $order_id
     * @param float  $amount   Optional refunded amount.
     */
    public function send_refund(string $order_id, float $amount = 0.0): array {
        $path = '/orders/' . rawurlencode($order_id);
        if ($amount > 0) {
            $path .= '?refundedAmount=' . rawurlencode((string) $amount);
        }

        $response = $this->request('DELETE', $path, [], true);
        if (!$response['success']) {
            return [
                'success'     => false,
                'hyros_id'    => '',
                'request_id'  => '',
                'error'       => $response['error'],
                'status_code' => $response['status_code'],
                'retryable'   => $response['retryable'],
                'retry_after' => $response['retry_after'],
            ];
        }

        $body = is_array($response['data']) ? $response['data'] : [];
        return [
            'success'     => true,
            // Refund responses return request_id; there is no Hyros entity id for the refund itself.
            'hyros_id'    => '',
            'request_id'  => isset($body['request_id']) ? (string) $body['request_id'] : '',
            'error'       => '',
            'status_code' => $response['status_code'],
            'retryable'   => false,
            'retry_after' => 0,
        ];
    }

    /**
     * POST /clicks — register a click event for a lead.
     *
     * @param array $payload
     */
    public function send_click(array $payload): array {
        $response = $this->request('POST', '/clicks', $payload, true);
        if (!$response['success']) {
            return [
                'success'     => false,
                'request_id'  => '',
                'error'       => $response['error'],
                'status_code' => $response['status_code'],
                'retryable'   => $response['retryable'],
                'retry_after' => $response['retry_after'],
            ];
        }

        $body = is_array($response['data']) ? $response['data'] : [];
        return [
            'success'     => true,
            'request_id'  => isset($body['request_id']) ? (string) $body['request_id'] : '',
            'error'       => '',
            'status_code' => $response['status_code'],
            'retryable'   => false,
            'retry_after' => 0,
        ];
    }

    /**
     * POST /carts — create or update an abandoned cart.
     *
     * @param array $payload
     */
    public function send_cart(array $payload): array {
        $response = $this->request('POST', '/carts', $payload, true);
        if (!$response['success']) {
            return [
                'success'     => false,
                'cart_id'     => '',
                'hyros_id'    => '',
                'request_id'  => '',
                'error'       => $response['error'],
                'status_code' => $response['status_code'],
                'retryable'   => $response['retryable'],
                'retry_after' => $response['retry_after'],
            ];
        }

        $body     = is_array($response['data']) ? $response['data'] : [];
        $hyros_id = self::extract_hyros_id($body);
        return [
            'success'     => true,
            'cart_id'     => $hyros_id,
            'hyros_id'    => $hyros_id,
            'request_id'  => isset($body['request_id']) ? (string) $body['request_id'] : '',
            'error'       => '',
            'status_code' => $response['status_code'],
            'retryable'   => false,
            'retry_after' => 0,
        ];
    }

    /**
     * GET /domains — returns the list of domains registered in this account.
     *
     * @return array{success: bool, domains: string[], error: string, status_code: int}
     */
    public function get_domains(): array {
        $response = $this->request('GET', '/domains', [], true);
        if (!$response['success']) {
            return [
                'success'     => false,
                'domains'     => [],
                'error'       => $response['error'],
                'status_code' => $response['status_code'],
            ];
        }

        $domains = $response['data'];
        return [
            'success'     => true,
            'domains'     => is_array($domains) ? $domains : [],
            'error'       => '',
            'status_code' => $response['status_code'],
        ];
    }

    /**
     * GET /tracking-script — returns the raw HTML <script> block for this account.
     *
     * @param string $domain Optional domain to get the domain-specific script.
     * @return array{success: bool, script: string, error: string}
     */
    public function get_tracking_script(string $domain = ''): array {
        $path = '/tracking-script';
        if ('' !== $domain) {
            $path .= '?domain=' . rawurlencode($domain);
        }

        $response = $this->request('GET', $path, [], false);
        if (!$response['success']) {
            return [
                'success' => false,
                'script'  => '',
                'error'   => $response['error'],
            ];
        }

        $script = trim((string) $response['data']);
        return ['success' => true, 'script' => $script, 'error' => ''];
    }

    /**
     * GET /user-info — returns account profile and tracking configuration.
     *
     * @return array{success: bool, data: array, error: string}
     */
    public function get_account_info(): array {
        $response = $this->request('GET', '/user-info', [], true);
        if (!$response['success']) {
            return ['success' => false, 'data' => [], 'error' => $response['error']];
        }

        $body = is_array($response['data']) ? $response['data'] : [];
        if (empty($body['result']) || !is_array($body['result'])) {
            return ['success' => false, 'data' => [], 'error' => __('Invalid user-info response.', 'hyros-woo')];
        }
        $result  = $body['result'];
        $profile = $result['userProfile'] ?? [];
        $tracking = $result['trueTrackingData'] ?? [];

        return [
            'success' => true,
            'data'    => [
                'email'          => $profile['email'] ?? '',
                'firstName'      => $profile['firstName'] ?? '',
                'lastName'       => $profile['lastName'] ?? '',
                'companyName'    => $profile['companyName'] ?? '',
                'timezone'       => $profile['timezone'] ?? '',
                'currency'       => $tracking['INBOUND_CURRENCY'] ?? '',
                'attribution'    => $tracking['LEAD_ATTRIBUTION_TIMEFRAME'] ?? '',
                'pendingMode'    => $tracking['PENDING_CONVERSIONS_ATTRIBUTION_MODE'] ?? '',
                'ignoreOrganic'  => $tracking['PENDING_CONVERSIONS_IGNORE_ORGANIC_SOURCES'] ?? '',
                'saleGrouping'   => $tracking['SALE_GROUPING_ENABLED'] ?? '',
                'groupingWindow' => $tracking['SALE_GROUPING_TIMEFRAME'] ?? '',
                'recurringEnabled' => $tracking['RECURRING_PRODUCTS_ENABLED'] ?? '',
                'trackEu'        => $tracking['TRACK_EU_CUSTOMERS'] ?? '',
            ],
            'error' => '',
        ];
    }

    /**
     * Validate an API key by calling GET /leads.
     * Returns true if the key is accepted (HTTP 200), false otherwise.
     *
     * @param string $api_key
     * @return bool
     */
    public static function validate_key(string $api_key): bool {
        if (empty($api_key)) {
            return false;
        }
        $instance = new self($api_key);
        $url      = HYROS_WOO_API_BASE . '/leads';
        $response = wp_remote_get($url, $instance->base_args('GET'));

        if (is_wp_error($response)) {
            return false;
        }

        return (int) wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Parse the Hyros response body and extract the primary ID.
     *
     * Hyros returns IDs in the message array as "key: value" strings, e.g.:
     *   "message": ["external_cart_id: d49b708b...", "request_id: 78c6d764..."]
     * Falls back to request_id if no typed ID is found.
     *
     * @param array $body Decoded JSON response body.
     * @return string
     */
    private static function extract_hyros_id(array $body): string {
        $messages = isset($body['message']) && is_array($body['message']) ? $body['message'] : [];

        // Priority: typed IDs first, then request_id as fallback.
        $priority = ['external_cart_id', 'external_order_id', 'order_id', 'lead_id', 'request_id'];

        $found = [];
        foreach ($messages as $msg) {
            if (strpos($msg, ':') !== false) {
                [$key, $val] = explode(':', $msg, 2);
                $found[trim($key)] = trim($val);
            }
        }

        foreach ($priority as $key) {
            if (!empty($found[$key])) {
                return $found[$key];
            }
        }

        // Last resort: top-level request_id.
        return isset($body['request_id']) ? (string) $body['request_id'] : '';
    }
}
