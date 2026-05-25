<?php
/**
 * Plugin Name: Dailyve Sync
 * Description: Sync WordPress operator post media/profile metadata to Dailyve NestJS without changing local content or media.
 * Version: 1.0.0
 * Author: Nghia Le
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: dailyve-sync
 */

defined('ABSPATH') || exit;

define('DAILYVE_SYNC_VERSION', '1.0.0');
define('DAILYVE_SYNC_FILE', __FILE__);
define('DAILYVE_SYNC_DIR', plugin_dir_path(__FILE__));
define('DAILYVE_SYNC_URL', plugin_dir_url(__FILE__));

require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-settings.php';
require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-webhook-client.php';
require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-post-meta.php';
require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-rest-controller.php';
require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-admin.php';
require_once DAILYVE_SYNC_DIR . 'includes/class-dailyve-sync-cli.php';

final class Dailyve_Sync_Plugin {
    /**
     * @var Dailyve_Sync_Plugin|null
     */
    private static $instance = null;

    /**
     * @var Dailyve_Sync_Settings
     */
    public $settings;

    /**
     * @var Dailyve_Sync_Webhook_Client
     */
    public $webhook_client;

    /**
     * @var Dailyve_Sync_Post_Meta
     */
    public $post_meta;

    /**
     * @var Dailyve_Sync_REST_Controller
     */
    public $rest_controller;

    /**
     * @var Dailyve_Sync_Admin
     */
    public $admin;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->settings = new Dailyve_Sync_Settings();
        $this->webhook_client = new Dailyve_Sync_Webhook_Client($this->settings);
        $this->post_meta = new Dailyve_Sync_Post_Meta($this->settings, $this->webhook_client);
        $this->rest_controller = new Dailyve_Sync_REST_Controller($this->settings, $this->post_meta);
        $this->admin = new Dailyve_Sync_Admin($this->settings, $this->post_meta);

        $this->settings->init();
        $this->post_meta->init();
        $this->rest_controller->init();
        $this->admin->init();

        if (defined('WP_CLI') && WP_CLI) {
            $cli = new Dailyve_Sync_CLI($this->post_meta);
            WP_CLI::add_command('dailyve sync', array($cli, 'sync'));
        }
    }

    public static function activate() {
        Dailyve_Sync_Settings::add_default_options();
    }
}

register_activation_hook(__FILE__, array('Dailyve_Sync_Plugin', 'activate'));

function dailyve_sync() {
    return Dailyve_Sync_Plugin::instance();
}

add_action('plugins_loaded', 'dailyve_sync');
