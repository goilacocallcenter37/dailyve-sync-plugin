<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_REST_Controller {
    /**
     * @var Dailyve_Sync_Settings
     */
    private $settings;

    /**
     * @var Dailyve_Sync_Post_Meta
     */
    private $post_meta;

    /**
     * @var Dailyve_Sync_Webhook_Client
     */
    private $webhook_client;

    public function __construct(Dailyve_Sync_Settings $settings, Dailyve_Sync_Post_Meta $post_meta) {
        $this->settings = $settings;
        $this->post_meta = $post_meta;
        $this->webhook_client = new Dailyve_Sync_Webhook_Client($settings);
    }

    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(
            'dailyve/v1',
            '/operators/media',
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_items'),
                'permission_callback' => array($this, 'permissions_check'),
                'args' => array(
                    'modified_after' => array(
                        'required' => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'page' => array(
                        'required' => false,
                        'sanitize_callback' => 'absint',
                        'default' => 1,
                    ),
                    'per_page' => array(
                        'required' => false,
                        'sanitize_callback' => 'absint',
                        'default' => 100,
                    ),
                ),
            )
        );
    }

    public function permissions_check(WP_REST_Request $request) {
        if (!$this->settings->is_enabled()) {
            return new WP_Error('dailyve_sync_disabled', __('Dailyve Sync is disabled.', 'dailyve-sync'), array('status' => 403));
        }

        $site_key = $request->get_header('x-dailyve-site-key');
        $timestamp = $request->get_header('x-dailyve-timestamp');
        $signature = $request->get_header('x-dailyve-signature');
        $raw_body = (string) $request->get_body();

        return $this->webhook_client->verify_incoming_request($site_key, $timestamp, $signature, $raw_body);
    }

    public function get_items(WP_REST_Request $request) {
        $page = max(absint($request->get_param('page')), 1);
        $per_page = min(max(absint($request->get_param('per_page')), 1), 100);
        $modified_after = sanitize_text_field((string) $request->get_param('modified_after'));
        $query = $this->post_meta->query_operator_posts($page, $per_page, $modified_after);
        $items = array();

        foreach ($query->posts as $post_id) {
            $items[] = $this->post_meta->build_upsert_payload((int) $post_id);
        }

        return rest_ensure_response(
            array(
                'items' => $items,
                'page' => $page,
                'per_page' => $per_page,
                'has_more' => $page < (int) $query->max_num_pages,
            )
        );
    }
}
