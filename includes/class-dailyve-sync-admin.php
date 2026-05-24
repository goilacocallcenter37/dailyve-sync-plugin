<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_Admin {
    /**
     * @var Dailyve_Sync_Settings
     */
    private $settings;

    /**
     * @var Dailyve_Sync_Post_Meta
     */
    private $post_meta;

    public function __construct(Dailyve_Sync_Settings $settings, Dailyve_Sync_Post_Meta $post_meta) {
        $this->settings = $settings;
        $this->post_meta = $post_meta;
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_dailyve_sync_save_settings', array($this, 'handle_save_settings'));
        add_action('wp_ajax_dailyve_sync_manual_batch', array($this, 'handle_manual_batch'));
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    public function add_menu() {
        add_menu_page(
            __('Dailyve Sync', 'dailyve-sync'),
            __('Dailyve Sync', 'dailyve-sync'),
            'manage_options',
            'dailyve-sync',
            array($this, 'render_page'),
            'dashicons-update',
            58
        );
    }

    public function admin_notices() {
        if (empty($_GET['dailyve_sync_status'])) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['dailyve_sync_status']));
        if ('synced' === $status) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Dailyve sync completed.', 'dailyve-sync') . '</p></div>';
            return;
        }

        if ('failed' === $status) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Dailyve sync failed. Check the post sync error field.', 'dailyve-sync') . '</p></div>';
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->settings->all();
        $option_name = Dailyve_Sync_Settings::OPTION_NAME;
        $post_types = get_post_types(array('show_ui' => true), 'objects');
        $current_post_type = $this->settings->operator_post_type();
        $ajax_nonce = wp_create_nonce('dailyve_sync_manual_batch');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Dailyve Sync', 'dailyve-sync'); ?></h1>

            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'dailyve-sync'); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="dailyve_sync_save_settings" />
                <?php wp_nonce_field('dailyve_sync_save_settings'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable sync', 'dailyve-sync'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[enabled]" value="1" <?php checked($options['enabled'], '1'); ?> />
                                    <?php esc_html_e('Send operator media/profile metadata to Dailyve.', 'dailyve-sync'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_site_key"><?php esc_html_e('Site key', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="text" id="dailyve_site_key" class="regular-text" name="<?php echo esc_attr($option_name); ?>[site_key]" value="<?php echo esc_attr($options['site_key']); ?>" placeholder="web_a" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_api_base_url"><?php esc_html_e('App API base URL', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="url" id="dailyve_api_base_url" class="regular-text code" name="<?php echo esc_attr($option_name); ?>[api_base_url]" value="<?php echo esc_attr($options['api_base_url']); ?>" placeholder="https://api.dailyve.com" />
                                <p class="description"><?php esc_html_e('Use the API root. If your app is mounted at /api/v2, include /api/v2 here.', 'dailyve-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_api_secret"><?php esc_html_e('API secret', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="password" id="dailyve_api_secret" class="regular-text" name="<?php echo esc_attr($option_name); ?>[api_secret]" value="<?php echo esc_attr($options['api_secret']); ?>" autocomplete="new-password" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_operator_post_type"><?php esc_html_e('Operator post type', 'dailyve-sync'); ?></label></th>
                            <td>
                                <select id="dailyve_operator_post_type" name="<?php echo esc_attr($option_name); ?>[operator_post_type]">
                                    <?php
                                    if (!isset($post_types[$current_post_type])) {
                                        echo '<option value="' . esc_attr($current_post_type) . '" selected>' . esc_html($current_post_type) . '</option>';
                                    }

                                    foreach ($post_types as $post_type => $post_type_object) :
                                        ?>
                                        <option value="<?php echo esc_attr($post_type); ?>" <?php selected($current_post_type, $post_type); ?>>
                                            <?php echo esc_html($post_type_object->labels->singular_name . ' (' . $post_type . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_parent_page_id"><?php esc_html_e('Parent page ID', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="number" id="dailyve_parent_page_id" class="regular-text" name="<?php echo esc_attr($option_name); ?>[parent_page_id]" value="<?php echo esc_attr($options['parent_page_id']); ?>" placeholder="12345" min="0" />
                                <p class="description"><?php esc_html_e('Only sync children of this parent page ID (leave as 0 to ignore). Useful when using "page" as the post type.', 'dailyve-sync'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_taxonomy_name"><?php esc_html_e('Taxonomy name', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="text" id="dailyve_taxonomy_name" class="regular-text" name="<?php echo esc_attr($option_name); ?>[taxonomy_name]" value="<?php echo esc_attr($options['taxonomy_name']); ?>" placeholder="category" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_taxonomy_term"><?php esc_html_e('Term ID or slug', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="text" id="dailyve_taxonomy_term" class="regular-text" name="<?php echo esc_attr($option_name); ?>[taxonomy_term]" value="<?php echo esc_attr($options['taxonomy_term']); ?>" placeholder="nha-xe" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_gallery_field_name"><?php esc_html_e('ACF gallery field name', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="text" id="dailyve_gallery_field_name" class="regular-text" name="<?php echo esc_attr($option_name); ?>[gallery_field_name]" value="<?php echo esc_attr($options['gallery_field_name']); ?>" placeholder="gallery" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="dailyve_operator_id_meta_key"><?php esc_html_e('Operator ID meta key', 'dailyve-sync'); ?></label></th>
                            <td>
                                <input type="text" id="dailyve_operator_id_meta_key" class="regular-text code" name="<?php echo esc_attr($option_name); ?>[operator_id_meta_key]" value="<?php echo esc_attr($options['operator_id_meta_key']); ?>" placeholder="_dailyve_operator_id" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Sync on save', 'dailyve-sync'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($option_name); ?>[sync_on_save]" value="1" <?php checked($options['sync_on_save'], '1'); ?> />
                                    <?php esc_html_e('Automatically sync when an operator post is saved or updated.', 'dailyve-sync'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last full sync time', 'dailyve-sync'); ?></th>
                            <td>
                                <code><?php echo esc_html($options['last_full_sync_time'] ? $options['last_full_sync_time'] : '-'); ?></code>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save settings', 'dailyve-sync')); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Manual sync', 'dailyve-sync'); ?></h2>
            <p><?php esc_html_e('Runs in small AJAX batches to avoid request timeouts.', 'dailyve-sync'); ?></p>
            <p>
                <button type="button" class="button" id="dailyve-sync-recent" data-mode="recent"><?php esc_html_e('Sync recent updated posts', 'dailyve-sync'); ?></button>
                <button type="button" class="button button-primary" id="dailyve-sync-all" data-mode="all"><?php esc_html_e('Sync all posts', 'dailyve-sync'); ?></button>
            </p>
            <pre id="dailyve-sync-log" style="display:none;max-width:900px;max-height:320px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;"></pre>
        </div>

        <script>
        (function($) {
            var nonce = '<?php echo esc_js($ajax_nonce); ?>';
            var isRunning = false;

            function log(message) {
                $('#dailyve-sync-log').show().append(message + "\n");
            }

            function runBatch(mode, page, totals) {
                $.post(ajaxurl, {
                    action: 'dailyve_sync_manual_batch',
                    nonce: nonce,
                    mode: mode,
                    page: page,
                    per_page: 100
                }).done(function(response) {
                    if (!response || !response.success) {
                        var message = response && response.data && response.data.message ? response.data.message : 'Unknown error';
                        log('ERROR: ' + message);
                        finish();
                        return;
                    }

                    totals.synced += response.data.synced;
                    totals.failed += response.data.failed;
                    log('Batch ' + page + ': fetched=' + response.data.fetched + ', synced=' + response.data.synced + ', failed=' + response.data.failed);

                    if (response.data.has_more) {
                        runBatch(mode, page + 1, totals);
                        return;
                    }

                    log('Done. Total synced=' + totals.synced + ', failed=' + totals.failed);
                    finish();
                }).fail(function(xhr) {
                    log('ERROR: HTTP ' + xhr.status);
                    finish();
                });
            }

            function finish() {
                isRunning = false;
                $('#dailyve-sync-recent, #dailyve-sync-all').prop('disabled', false);
            }

            $('#dailyve-sync-recent, #dailyve-sync-all').on('click', function() {
                if (isRunning) {
                    return;
                }

                isRunning = true;
                $('#dailyve-sync-recent, #dailyve-sync-all').prop('disabled', true);
                $('#dailyve-sync-log').text('').show();
                log('Starting ' + $(this).data('mode') + ' sync...');
                runBatch($(this).data('mode'), 1, { synced: 0, failed: 0 });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Dailyve Sync settings.', 'dailyve-sync'));
        }

        check_admin_referer('dailyve_sync_save_settings');

        $option_name = Dailyve_Sync_Settings::OPTION_NAME;
        $raw = isset($_POST[$option_name]) && is_array($_POST[$option_name]) ? wp_unslash($_POST[$option_name]) : array();
        $sanitized = $this->settings->sanitize($raw);
        update_option($option_name, $sanitized, false);

        wp_safe_redirect(add_query_arg('settings-updated', '1', admin_url('admin.php?page=dailyve-sync')));
        exit;
    }

    public function handle_manual_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Forbidden.', 'dailyve-sync')), 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'dailyve_sync_manual_batch')) {
            wp_send_json_error(array('message' => __('Invalid nonce.', 'dailyve-sync')), 403);
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'recent';
        $page = isset($_POST['page']) ? max(absint(wp_unslash($_POST['page'])), 1) : 1;
        $per_page = isset($_POST['per_page']) ? min(max(absint(wp_unslash($_POST['per_page'])), 1), 100) : 100;
        $modified_after = '';

        if ('recent' === $mode) {
            $modified_after = (string) $this->settings->get('last_full_sync_time', '');
            if (!$modified_after) {
                $modified_after = gmdate('c', time() - DAY_IN_SECONDS);
            }
        }

        $query = $this->post_meta->query_operator_posts($page, $per_page, $modified_after);
        $synced = 0;
        $failed = 0;

        foreach ($query->posts as $post_id) {
            $result = $this->post_meta->sync_post((int) $post_id, true);
            if (is_wp_error($result)) {
                $failed++;
            } else {
                $synced++;
            }
        }

        $has_more = $page < (int) $query->max_num_pages;
        if (!$has_more && 'all' === $mode) {
            $this->settings->update(array('last_full_sync_time' => gmdate('c')));
        }

        wp_send_json_success(
            array(
                'fetched' => count($query->posts),
                'synced' => $synced,
                'failed' => $failed,
                'has_more' => $has_more,
                'page' => $page,
                'per_page' => $per_page,
            )
        );
    }
}
