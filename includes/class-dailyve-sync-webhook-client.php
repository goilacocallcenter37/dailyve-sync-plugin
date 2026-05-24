<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_Webhook_Client {
    /**
     * @var Dailyve_Sync_Settings
     */
    private $settings;

    public function __construct(Dailyve_Sync_Settings $settings) {
        $this->settings = $settings;
    }

    public function send_upsert(array $payload) {
        return $this->send('/wp-sync/operator-profile', $payload);
    }

    public function send_delete(array $payload) {
        return $this->send('/wp-sync/operator-profile/delete', $payload);
    }

    public function send($path, array $payload) {
        $api_base_url = untrailingslashit((string) $this->settings->get('api_base_url', ''));
        $site_key = $this->settings->site_key();
        $api_secret = $this->settings->api_secret();

        if (!$api_base_url || !$site_key || !$api_secret) {
            return new WP_Error('dailyve_sync_missing_config', __('Dailyve Sync API config is incomplete.', 'dailyve-sync'));
        }

        $raw_body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw_body)) {
            return new WP_Error('dailyve_sync_invalid_payload', __('Unable to encode Dailyve payload.', 'dailyve-sync'));
        }

        $timestamp = (string) time();
        $signature = $this->signature($timestamp, $raw_body, $api_secret);
        $url = $this->endpoint_url($path, $api_base_url);

        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 10,
                'headers' => array(
                    'content-type' => 'application/json',
                    'x-dailyve-site-key' => $site_key,
                    'x-dailyve-timestamp' => $timestamp,
                    'x-dailyve-signature' => $signature,
                ),
                'body' => $raw_body,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $message = wp_remote_retrieve_body($response);
            if (!$message) {
                $message = sprintf('Dailyve API returned HTTP %d.', $code);
            }

            return new WP_Error('dailyve_sync_http_error', wp_strip_all_tags($message), array('status' => $code));
        }

        return $response;
    }

    public function verify_incoming_request($site_key, $timestamp, $signature, $raw_body = '') {
        $configured_site_key = $this->settings->site_key();
        $api_secret = $this->settings->api_secret();

        if (!$configured_site_key || !$api_secret) {
            return new WP_Error('dailyve_sync_missing_config', __('Dailyve Sync API config is incomplete.', 'dailyve-sync'), array('status' => 401));
        }

        if (!$site_key || !hash_equals($configured_site_key, sanitize_key($site_key))) {
            return new WP_Error('dailyve_sync_invalid_site', __('Invalid Dailyve site key.', 'dailyve-sync'), array('status' => 401));
        }

        $timestamp_ms = $this->timestamp_to_milliseconds($timestamp);
        if (!$timestamp_ms || abs((int) round(microtime(true) * 1000) - $timestamp_ms) > 5 * 60 * 1000) {
            return new WP_Error('dailyve_sync_expired_timestamp', __('Dailyve request timestamp expired.', 'dailyve-sync'), array('status' => 401));
        }

        $expected = $this->signature((string) $timestamp, (string) $raw_body, $api_secret);
        $normalized = preg_replace('/^sha256=/i', '', trim((string) $signature));

        if (!$normalized || !hash_equals($expected, $normalized)) {
            return new WP_Error('dailyve_sync_invalid_signature', __('Invalid Dailyve signature.', 'dailyve-sync'), array('status' => 401));
        }

        return true;
    }

    public function signature($timestamp, $raw_body, $secret) {
        return hash_hmac('sha256', (string) $timestamp . '.' . (string) $raw_body, (string) $secret);
    }

    private function endpoint_url($path, $api_base_url) {
        $base_path = (string) wp_parse_url($api_base_url, PHP_URL_PATH);
        $base_path = untrailingslashit($base_path);
        $prefix = preg_match('#/api(?:/v[0-9]+)?$#', $base_path) ? '' : '/api';
        $url = untrailingslashit($api_base_url) . $prefix . '/' . ltrim($path, '/');

        return apply_filters('dailyve_sync_endpoint_url', $url, $path, $api_base_url);
    }

    private function timestamp_to_milliseconds($timestamp) {
        if (!is_numeric($timestamp)) {
            return 0;
        }

        $value = (int) $timestamp;
        if (strlen((string) $value) <= 10) {
            return $value * 1000;
        }

        return $value;
    }
}
