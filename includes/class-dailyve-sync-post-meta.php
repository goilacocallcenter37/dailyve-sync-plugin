<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_Post_Meta {
    const DEFAULT_OPERATOR_ID_META_KEY = '_dailyve_operator_id';
    const OPERATOR_NAME_META_KEY = '_dailyve_operator_name';
    const LAST_SYNCED_AT_META_KEY = '_dailyve_last_synced_at';
    const SYNC_STATUS_META_KEY = '_dailyve_sync_status';
    const SYNC_ERROR_META_KEY = '_dailyve_sync_error';

    /**
     * @var Dailyve_Sync_Settings
     */
    private $settings;

    /**
     * @var Dailyve_Sync_Webhook_Client
     */
    private $webhook_client;

    /**
     * @var array<int,bool>
     */
    private $queued_upserts = array();

    /**
     * @var array<int,bool>
     */
    private $sent_upserts = array();

    /**
     * @var array<int,bool>
     */
    private $sent_deletes = array();

    public function __construct(Dailyve_Sync_Settings $settings, Dailyve_Sync_Webhook_Client $webhook_client) {
        $this->settings = $settings;
        $this->webhook_client = $webhook_client;
    }

    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'handle_save_post'), 20, 3);
        add_action('transition_post_status', array($this, 'handle_transition_post_status'), 20, 3);
        add_action('trashed_post', array($this, 'handle_trashed_post'), 20, 1);
        add_action('before_delete_post', array($this, 'handle_before_delete_post'), 20, 1);
        add_action('acf/save_post', array($this, 'handle_acf_save_post'), 20, 1);
        add_action('shutdown', array($this, 'process_queued_upserts'));
        add_action('admin_post_dailyve_sync_post', array($this, 'handle_sync_now'));
    }

    public function add_meta_boxes() {
        add_meta_box(
            'dailyve_operator_mapping',
            __('Dailyve Operator Mapping', 'dailyve-sync'),
            array($this, 'render_meta_box'),
            $this->settings->operator_post_type(),
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('dailyve_sync_save_meta', 'dailyve_sync_meta_nonce');

        $operator_id = $this->get_operator_id($post->ID);
        $operator_name = get_post_meta($post->ID, self::OPERATOR_NAME_META_KEY, true);
        $last_synced_at = get_post_meta($post->ID, self::LAST_SYNCED_AT_META_KEY, true);
        $sync_status = get_post_meta($post->ID, self::SYNC_STATUS_META_KEY, true);
        $sync_error = get_post_meta($post->ID, self::SYNC_ERROR_META_KEY, true);
        $sync_url = wp_nonce_url(
            admin_url('admin-post.php?action=dailyve_sync_post&post_id=' . absint($post->ID)),
            'dailyve_sync_post_' . absint($post->ID)
        );
        ?>
        <p>
            <label for="dailyve_operator_id"><strong><?php esc_html_e('Dailyve Operator ID', 'dailyve-sync'); ?></strong></label>
            <input type="text" class="widefat" id="dailyve_operator_id" name="dailyve_operator_id" value="<?php echo esc_attr($operator_id); ?>" />
        </p>
        <p>
            <label><strong><?php esc_html_e('Dailyve Operator Name', 'dailyve-sync'); ?></strong></label>
            <input type="text" class="widefat" value="<?php echo esc_attr($operator_name); ?>" readonly />
        </p>
        <p>
            <label><strong><?php esc_html_e('Last synced at', 'dailyve-sync'); ?></strong></label><br />
            <code><?php echo esc_html($last_synced_at ? $last_synced_at : '-'); ?></code>
        </p>
        <p>
            <label><strong><?php esc_html_e('Sync status', 'dailyve-sync'); ?></strong></label><br />
            <code><?php echo esc_html($sync_status ? $sync_status : '-'); ?></code>
        </p>
        <?php if ($sync_error) : ?>
            <p>
                <label><strong><?php esc_html_e('Last sync error', 'dailyve-sync'); ?></strong></label><br />
                <span style="color:#b32d2e;"><?php echo esc_html($sync_error); ?></span>
            </p>
        <?php endif; ?>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url($sync_url); ?>"><?php esc_html_e('Sync now', 'dailyve-sync'); ?></a>
        </p>
        <?php
    }

    public function handle_save_post($post_id, $post, $update) {
        if (!$this->is_valid_post_for_hooks($post_id)) {
            return;
        }

        $this->save_mapping_meta($post_id);

        if ($this->settings->is_sync_on_save_enabled() && 'publish' === get_post_status($post_id)) {
            $this->queue_upsert($post_id);
        }
    }

    public function handle_transition_post_status($new_status, $old_status, $post) {
        if (!$post || empty($post->ID) || !$this->is_valid_post_for_hooks($post->ID)) {
            return;
        }

        if ('publish' === $new_status) {
            if ($this->settings->is_sync_on_save_enabled()) {
                $this->queue_upsert((int) $post->ID);
            }
            return;
        }

        if ('publish' === $old_status && in_array($new_status, array('private', 'trash'), true)) {
            $this->send_delete_for_post((int) $post->ID, $new_status);
        }
    }

    public function handle_trashed_post($post_id) {
        if ($this->is_valid_post_for_hooks($post_id)) {
            $this->send_delete_for_post((int) $post_id, 'trash');
        }
    }

    public function handle_before_delete_post($post_id) {
        if ($this->is_valid_post_for_hooks($post_id)) {
            $this->send_delete_for_post((int) $post_id, 'delete');
        }
    }

    public function handle_acf_save_post($post_id) {
        if (!is_numeric($post_id)) {
            return;
        }

        $post_id = (int) $post_id;
        if ($this->settings->is_sync_on_save_enabled() && $this->is_valid_post_for_hooks($post_id) && 'publish' === get_post_status($post_id)) {
            $this->queue_upsert($post_id);
        }
    }

    public function handle_sync_now() {
        $post_id = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You are not allowed to sync this post.', 'dailyve-sync'));
        }

        check_admin_referer('dailyve_sync_post_' . $post_id);
        $result = $this->sync_post($post_id, true);
        $redirect = get_edit_post_link($post_id, 'raw');
        if (!$redirect) {
            $redirect = admin_url('post.php?post=' . $post_id . '&action=edit');
        }

        $status = is_wp_error($result) ? 'failed' : 'synced';
        wp_safe_redirect(add_query_arg('dailyve_sync_status', $status, $redirect));
        exit;
    }

    public function process_queued_upserts() {
        foreach (array_keys($this->queued_upserts) as $post_id) {
            $this->sync_post((int) $post_id, false);
        }

        $this->queued_upserts = array();
    }

    public function sync_post($post_id, $force = false) {
        $post_id = absint($post_id);
        if (!$post_id) {
            return new WP_Error('dailyve_sync_invalid_post', __('Invalid post ID.', 'dailyve-sync'));
        }

        if (!$this->should_sync_post($post_id)) {
            $reasons = array();
            if (!$this->settings->is_enabled()) {
                $reasons[] = 'Plugin is disabled';
            }
            if (!$this->is_valid_post_for_hooks($post_id)) {
                $reasons[] = 'Invalid post type (Expected: ' . $this->settings->operator_post_type() . ', Actual: ' . get_post_type($post_id) . ')';
            }
            if (!$this->matches_taxonomy_filter($post_id)) {
                $reasons[] = 'Failed taxonomy filter';
            }
            if (!$this->matches_parent_filter($post_id)) {
                $reasons[] = 'Failed parent page filter (Parent ID: ' . wp_get_post_parent_id($post_id) . ', Configured: ' . $this->settings->parent_page_id() . ')';
            }
            
            $reason_str = implode(', ', $reasons);
            $error_msg = __('Post is not eligible for Dailyve sync. Reasons: ', 'dailyve-sync') . $reason_str;
            
            $this->mark_sync_failed($post_id, $error_msg);
            return new WP_Error('dailyve_sync_skipped_post', $error_msg);
        }

        if (!$force && isset($this->sent_upserts[$post_id])) {
            return true;
        }

        $status = get_post_status($post_id);
        if ('publish' !== $status) {
            return $this->send_delete_for_post($post_id, $status ? $status : 'delete');
        }

        $payload = $this->build_upsert_payload($post_id);
        $response = $this->webhook_client->send_upsert($payload);

        if (is_wp_error($response)) {
            $this->mark_sync_failed($post_id, $response->get_error_message());
            return $response;
        }

        $this->sent_upserts[$post_id] = true;
        $this->mark_sync_success($post_id);

        return true;
    }

    public function send_delete_for_post($post_id, $wp_status = 'trash') {
        $post_id = absint($post_id);
        if (!$post_id) {
            return new WP_Error('dailyve_sync_invalid_post', __('Invalid post ID.', 'dailyve-sync'));
        }

        if (isset($this->sent_deletes[$post_id])) {
            return true;
        }

        if (!$this->settings->is_enabled() || !$this->is_matching_post_type($post_id) || !$this->matches_taxonomy_filter($post_id) || !$this->matches_parent_filter($post_id)) {
            return new WP_Error('dailyve_sync_skipped_post', __('Post is not eligible for Dailyve sync.', 'dailyve-sync'));
        }

        $payload = $this->build_delete_payload($post_id, $wp_status);
        $response = $this->webhook_client->send_delete($payload);

        if (is_wp_error($response)) {
            $this->mark_sync_failed($post_id, $response->get_error_message());
            return $response;
        }

        $this->sent_deletes[$post_id] = true;
        $this->mark_sync_success($post_id);

        return true;
    }

    public function build_upsert_payload($post_id) {
        $post_id = absint($post_id);
        $gallery_items = $this->gallery_items($post_id);

        return array(
            'event' => 'upsert',
            'site_key' => $this->settings->site_key(),
            'wp_post_id' => $post_id,
            'wp_title' => get_the_title($post_id),
            'wp_slug' => (string) get_post_field('post_name', $post_id),
            'wp_url' => (string) get_permalink($post_id),
            'wp_status' => (string) get_post_status($post_id),
            'operator_id' => $this->get_operator_id($post_id),
            'operator_name' => (string) get_post_meta($post_id, self::OPERATOR_NAME_META_KEY, true),
            'avatar_url' => $this->featured_image_url($post_id),
            'gallery_urls' => wp_list_pluck($gallery_items, 'url'),
            'gallery_items' => $gallery_items,
            'wp_modified_at' => (string) get_post_modified_time('c', true, $post_id),
        );
    }

    public function build_delete_payload($post_id, $wp_status = 'trash') {
        $post_id = absint($post_id);

        return array(
            'event' => 'delete',
            'site_key' => $this->settings->site_key(),
            'wp_post_id' => $post_id,
            'operator_id' => $this->get_operator_id($post_id),
            'wp_status' => sanitize_text_field($wp_status),
        );
    }

    public function query_operator_posts($page = 1, $per_page = 100, $modified_after = '') {
        $per_page = min(max(absint($per_page), 1), 100);
        $page = max(absint($page), 1);

        $args = array(
            'post_type' => $this->settings->operator_post_type(),
            'post_status' => array('publish', 'private', 'draft', 'pending', 'future'),
            'fields' => 'ids',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'ASC',
        );

        $tax_query = $this->tax_query();
        if ($tax_query) {
            $args['tax_query'] = array($tax_query);
        }

        $parent_page_id = $this->settings->parent_page_id();
        if ($parent_page_id > 0) {
            $descendant_ids = $this->get_descendant_ids($parent_page_id);
            if (!empty($descendant_ids)) {
                $args['post__in'] = $descendant_ids;
            } else {
                $args['post__in'] = array(0);
            }
        }

        if ($modified_after) {
            $timestamp = strtotime($modified_after);
            if ($timestamp) {
                $args['date_query'] = array(
                    array(
                        'column' => 'post_modified_gmt',
                        'after' => gmdate('Y-m-d H:i:s', $timestamp),
                        'inclusive' => false,
                    ),
                );
            }
        }

        return new WP_Query($args);
    }

    public function should_sync_post($post_id) {
        if (!$this->settings->is_enabled()) {
            return false;
        }

        if (!$this->is_valid_post_for_hooks($post_id)) {
            return false;
        }

        return $this->matches_taxonomy_filter($post_id) && $this->matches_parent_filter($post_id);
    }

    private function queue_upsert($post_id) {
        $this->queued_upserts[absint($post_id)] = true;
    }

    private function save_mapping_meta($post_id) {
        if (empty($_POST['dailyve_sync_meta_nonce'])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['dailyve_sync_meta_nonce']));
        if (!wp_verify_nonce($nonce, 'dailyve_sync_save_meta')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $operator_id = isset($_POST['dailyve_operator_id']) ? sanitize_text_field(wp_unslash($_POST['dailyve_operator_id'])) : '';
        $meta_key = $this->settings->operator_id_meta_key();
        update_post_meta($post_id, $meta_key, $operator_id);

        if (self::DEFAULT_OPERATOR_ID_META_KEY !== $meta_key) {
            update_post_meta($post_id, self::DEFAULT_OPERATOR_ID_META_KEY, $operator_id);
        }
    }

    private function is_valid_post_for_hooks($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) {
            return false;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return false;
        }

        return $this->is_matching_post_type($post_id);
    }

    private function is_matching_post_type($post_id) {
        return get_post_type($post_id) === $this->settings->operator_post_type();
    }

    private function matches_taxonomy_filter($post_id) {
        $taxonomy = sanitize_key((string) $this->settings->get('taxonomy_name', ''));
        $term = sanitize_text_field((string) $this->settings->get('taxonomy_term', ''));

        if (!$taxonomy || !$term) {
            return true;
        }

        if (!taxonomy_exists($taxonomy)) {
            return false;
        }

        $term_value = is_numeric($term) ? absint($term) : $term;
        return has_term($term_value, $taxonomy, $post_id);
    }

    private function matches_parent_filter($post_id) {
        $parent_page_id = $this->settings->parent_page_id();
        if ($parent_page_id <= 0) {
            return true;
        }

        $direct_parent = absint(wp_get_post_parent_id($post_id));
        if ($direct_parent === $parent_page_id) {
            return true;
        }

        $ancestors = get_post_ancestors($post_id);
        if (is_array($ancestors) && in_array($parent_page_id, $ancestors, true)) {
            return true;
        }

        return false;
    }

    private function get_descendant_ids($parent_id) {
        $descendants = array();
        $direct_children = get_posts(array(
            'post_type' => $this->settings->operator_post_type(),
            'post_parent' => $parent_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'any',
        ));

        if (!empty($direct_children)) {
            $descendants = array_merge($descendants, $direct_children);
            foreach ($direct_children as $child_id) {
                $descendants = array_merge($descendants, $this->get_descendant_ids($child_id));
            }
        }

        return $descendants;
    }

    private function tax_query() {
        $taxonomy = sanitize_key((string) $this->settings->get('taxonomy_name', ''));
        $term = sanitize_text_field((string) $this->settings->get('taxonomy_term', ''));

        if (!$taxonomy || !$term || !taxonomy_exists($taxonomy)) {
            return null;
        }

        return array(
            'taxonomy' => $taxonomy,
            'field' => is_numeric($term) ? 'term_id' : 'slug',
            'terms' => is_numeric($term) ? absint($term) : $term,
        );
    }

    private function get_operator_id($post_id) {
        $configured_key = $this->settings->operator_id_meta_key();
        $value = get_post_meta($post_id, $configured_key, true);

        if ('' === $value && self::DEFAULT_OPERATOR_ID_META_KEY !== $configured_key) {
            $value = get_post_meta($post_id, self::DEFAULT_OPERATOR_ID_META_KEY, true);
        }

        return sanitize_text_field((string) $value);
    }

    private function featured_image_url($post_id) {
        $url = get_the_post_thumbnail_url($post_id, 'full');
        return $url ? esc_url_raw($url) : '';
    }

    private function gallery_items($post_id) {
        if (!function_exists('get_field')) {
            return array();
        }

        $field_name = $this->settings->gallery_field_name();
        $raw_gallery = get_field($field_name, $post_id);
        if (!$raw_gallery || !is_array($raw_gallery)) {
            return array();
        }

        $items = array();
        $sort_order = 0;

        foreach ($raw_gallery as $raw_item) {
            $item = $this->normalize_gallery_item($raw_item, $sort_order);
            if ($item && !empty($item['url'])) {
                $items[] = $item;
                $sort_order++;
            }
        }

        return $items;
    }

    private function normalize_gallery_item($raw_item, $sort_order) {
        $attachment_id = 0;
        $url = '';
        $alt = '';

        if (is_numeric($raw_item)) {
            $attachment_id = absint($raw_item);
        } elseif (is_array($raw_item)) {
            if (!empty($raw_item['ID'])) {
                $attachment_id = absint($raw_item['ID']);
            } elseif (!empty($raw_item['id'])) {
                $attachment_id = absint($raw_item['id']);
            }

            if (!empty($raw_item['url'])) {
                $url = esc_url_raw($raw_item['url']);
            }

            if (!empty($raw_item['alt'])) {
                $alt = sanitize_text_field($raw_item['alt']);
            } elseif (!empty($raw_item['title'])) {
                $alt = sanitize_text_field($raw_item['title']);
            }
        } elseif (is_object($raw_item) && !empty($raw_item->ID)) {
            $attachment_id = absint($raw_item->ID);
        }

        if ($attachment_id) {
            $attachment_url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($attachment_url) {
                $url = esc_url_raw($attachment_url);
            }

            $attachment_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            if ($attachment_alt) {
                $alt = sanitize_text_field($attachment_alt);
            }
        }

        if (!$url) {
            return null;
        }

        return array(
            'url' => $url,
            'alt' => $alt,
            'sort_order' => absint($sort_order),
        );
    }

    private function mark_sync_success($post_id) {
        update_post_meta($post_id, self::LAST_SYNCED_AT_META_KEY, current_time('mysql'));
        update_post_meta($post_id, self::SYNC_STATUS_META_KEY, 'synced');
        delete_post_meta($post_id, self::SYNC_ERROR_META_KEY);
    }

    private function mark_sync_failed($post_id, $message) {
        update_post_meta($post_id, self::SYNC_STATUS_META_KEY, 'failed');
        update_post_meta($post_id, self::SYNC_ERROR_META_KEY, sanitize_text_field($message));
    }
}
