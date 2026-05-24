<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_Settings {
    const OPTION_NAME = 'dailyve_sync_settings';

    public static function defaults() {
        return array(
            'enabled' => '0',
            'site_key' => '',
            'api_base_url' => '',
            'api_secret' => '',
            'operator_post_type' => 'post',
            'taxonomy_name' => '',
            'taxonomy_term' => '',
            'parent_page_id' => '0',
            'gallery_field_name' => 'gallery',
            'operator_id_meta_key' => '_dailyve_operator_id',
            'sync_on_save' => '1',
            'last_full_sync_time' => '',
        );
    }

    public static function add_default_options() {
        $existing = get_option(self::OPTION_NAME);
        if (!is_array($existing)) {
            add_option(self::OPTION_NAME, self::defaults(), '', false);
            return;
        }

        update_option(self::OPTION_NAME, wp_parse_args($existing, self::defaults()), false);
    }

    public function init() {
        add_action('admin_init', array($this, 'register_setting'));
    }

    public function register_setting() {
        register_setting(
            'dailyve_sync_settings',
            self::OPTION_NAME,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize'),
                'default' => self::defaults(),
            )
        );
    }

    public function all() {
        $options = get_option(self::OPTION_NAME, array());
        if (!is_array($options)) {
            $options = array();
        }

        return wp_parse_args($options, self::defaults());
    }

    public function get($key, $default = '') {
        $options = $this->all();
        return isset($options[$key]) ? $options[$key] : $default;
    }

    public function is_enabled() {
        return '1' === (string) $this->get('enabled', '0');
    }

    public function is_sync_on_save_enabled() {
        return '1' === (string) $this->get('sync_on_save', '1');
    }

    public function site_key() {
        return sanitize_key((string) $this->get('site_key', ''));
    }

    public function api_secret() {
        return (string) $this->get('api_secret', '');
    }

    public function operator_post_type() {
        $post_type = sanitize_key((string) $this->get('operator_post_type', 'post'));
        return $post_type ? $post_type : 'post';
    }

    public function parent_page_id() {
        return absint($this->get('parent_page_id', 0));
    }

    public function gallery_field_name() {
        $field = sanitize_key((string) $this->get('gallery_field_name', 'gallery'));
        return $field ? $field : 'gallery';
    }

    public function operator_id_meta_key() {
        $key = sanitize_key((string) $this->get('operator_id_meta_key', '_dailyve_operator_id'));
        return $key ? $key : '_dailyve_operator_id';
    }

    public function sanitize($input) {
        $input = is_array($input) ? $input : array();
        $current = $this->all();
        $output = self::defaults();

        $output['enabled'] = !empty($input['enabled']) ? '1' : '0';
        $output['site_key'] = isset($input['site_key']) ? sanitize_key(wp_unslash($input['site_key'])) : '';
        $output['api_base_url'] = isset($input['api_base_url']) ? esc_url_raw(trim(wp_unslash($input['api_base_url']))) : '';
        $output['api_secret'] = isset($input['api_secret']) ? sanitize_text_field(wp_unslash($input['api_secret'])) : '';
        $output['operator_post_type'] = isset($input['operator_post_type']) ? sanitize_key(wp_unslash($input['operator_post_type'])) : 'post';
        $output['taxonomy_name'] = isset($input['taxonomy_name']) ? sanitize_key(wp_unslash($input['taxonomy_name'])) : '';
        $output['taxonomy_term'] = isset($input['taxonomy_term']) ? sanitize_text_field(wp_unslash($input['taxonomy_term'])) : '';
        $output['parent_page_id'] = isset($input['parent_page_id']) ? absint(wp_unslash($input['parent_page_id'])) : 0;
        $output['gallery_field_name'] = isset($input['gallery_field_name']) ? sanitize_key(wp_unslash($input['gallery_field_name'])) : 'gallery';
        $output['operator_id_meta_key'] = isset($input['operator_id_meta_key']) ? sanitize_key(wp_unslash($input['operator_id_meta_key'])) : '_dailyve_operator_id';
        $output['sync_on_save'] = !empty($input['sync_on_save']) ? '1' : '0';
        $output['last_full_sync_time'] = isset($current['last_full_sync_time']) ? sanitize_text_field($current['last_full_sync_time']) : '';

        if (!$output['operator_post_type']) {
            $output['operator_post_type'] = 'post';
        }

        if (!$output['gallery_field_name']) {
            $output['gallery_field_name'] = 'gallery';
        }

        if (!$output['operator_id_meta_key']) {
            $output['operator_id_meta_key'] = '_dailyve_operator_id';
        }

        return $output;
    }

    public function update($values) {
        $current = $this->all();
        $next = wp_parse_args($values, $current);
        update_option(self::OPTION_NAME, $next, false);
    }
}
