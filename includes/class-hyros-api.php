<?php
defined('ABSPATH') || exit;

/**
 * Hyros API wrapper.
 * Base URL: https://api.hyros.com/v1/api/v1.0
 * Auth: API-Key header
 */
class Hyros_API {

    /** @var string */
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
     * GET /scripts — returns list of tracking scripts.
     *
     * @return array{success: bool, scripts: array, error: string}
     */
    public function get_scripts(): array {
        $url      = HYROS_WOO_API_BASE . '/scripts';
        $response = wp_remote_get($url, $this->base_args('GET'));

        if (is_wp_error($response)) {
            return ['success' => false, 'scripts' => [], 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $message = isset($body['message']) ? $body['message'] : 'HTTP ' . $code;
            return ['success' => false, 'scripts' => [], 'error' => $message];
        }

        $scripts = is_array($body) ? $body : [];
        return ['success' => true, 'scripts' => $scripts, 'error' => ''];
    }

    /**
     * POST /orders — track a new sale.
     *
     * @param array $payload
     * @return array{success: bool, error: string}
     */
    public function send_order(array $payload): array {
        $url      = HYROS_WOO_API_BASE . '/orders';
        $response = wp_remote_post($url, $this->base_args('POST', $payload));

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = isset($body['message']) ? $body['message'] : 'HTTP ' . $code;
            return ['success' => false, 'error' => $message];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * DELETE /orders/{orderId} — refund an order.
     *
     * @param string $order_id
     * @param float  $amount   Optional refunded amount.
     * @return array{success: bool, error: string}
     */
    public function send_refund(string $order_id, float $amount = 0.0): array {
        $url = HYROS_WOO_API_BASE . '/orders/' . rawurlencode($order_id);
        if ($amount > 0) {
            $url = add_query_arg('refundedAmount', $amount, $url);
        }

        $args     = $this->base_args('DELETE');
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = isset($body['message']) ? $body['message'] : 'HTTP ' . $code;
            return ['success' => false, 'error' => $message];
        }

        return ['success' => true, 'error' => ''];
    }

    /**
     * Validate an API key by calling get_scripts().
     *
     * @param string $api_key
     * @return bool
     */
    public static function validate_key(string $api_key): bool {
        if (empty($api_key)) {
            return false;
        }
        $instance = new self($api_key);
        $result   = $instance->get_scripts();
        return $result['success'];
    }
}
