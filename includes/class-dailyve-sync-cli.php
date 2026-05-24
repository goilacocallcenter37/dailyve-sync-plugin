<?php

defined('ABSPATH') || exit;

class Dailyve_Sync_CLI {
    /**
     * @var Dailyve_Sync_Post_Meta
     */
    private $post_meta;

    public function __construct(Dailyve_Sync_Post_Meta $post_meta) {
        $this->post_meta = $post_meta;
    }

    /**
     * Sync Dailyve operator media/profile metadata.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Sync all configured operator posts.
     *
     * [--post_id=<id>]
     * : Sync one post ID.
     *
     * [--modified_after=<datetime>]
     * : Sync posts modified after an ISO datetime.
     *
     * ## EXAMPLES
     *
     *     wp dailyve sync --all
     *     wp dailyve sync --post_id=123
     *     wp dailyve sync --modified_after="2026-05-24T00:00:00+07:00"
     */
    public function sync($args, $assoc_args) {
        if (!empty($assoc_args['post_id'])) {
            $post_id = absint($assoc_args['post_id']);
            $result = $this->post_meta->sync_post($post_id, true);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success('Synced post ' . $post_id . '.');
            return;
        }

        $modified_after = isset($assoc_args['modified_after']) ? sanitize_text_field($assoc_args['modified_after']) : '';
        if (empty($assoc_args['all']) && !$modified_after) {
            WP_CLI::error('Use --all, --post_id=<id>, or --modified_after=<datetime>.');
        }

        $page = 1;
        $per_page = 100;
        $synced = 0;
        $failed = 0;

        do {
            $query = $this->post_meta->query_operator_posts($page, $per_page, $modified_after);
            foreach ($query->posts as $post_id) {
                $result = $this->post_meta->sync_post((int) $post_id, true);
                if (is_wp_error($result)) {
                    $failed++;
                    WP_CLI::warning('Post ' . (int) $post_id . ': ' . $result->get_error_message());
                } else {
                    $synced++;
                }
            }

            $has_more = $page < (int) $query->max_num_pages;
            $page++;
        } while ($has_more);

        WP_CLI::success('Dailyve sync completed. Synced=' . $synced . ', failed=' . $failed . '.');
    }
}
