<?php
/**
 * Plugin Name: Rise Scholars Route
 * Description: مسیرهای REST کوچک برای سکوی رایز: فهرست کاربران یک نقش (scholar) و ثبت نویسندهٔ نوشته‌ها.
 * Version: 1.1
 */

// 1) list of users of one role (default: scholar)
add_action('rest_api_init', function () {
    register_rest_route('rise/v1', '/scholars', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback'            => function ($req) {
            $role  = sanitize_key($req->get_param('role') ?: 'scholar');
            $users = get_users(['role' => $role, 'orderby' => 'display_name', 'number' => 500]);
            return array_map(function ($u) {
                return ['id' => (int) $u->ID, 'name' => $u->display_name, 'slug' => $u->user_nicename];
            }, $users);
        },
    ]);

    // 2) set the author of a post of ANY type (also types that do not accept "author" through the standard REST route)
    register_rest_route('rise/v1', '/set-author', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('edit_others_posts'); },
        'callback'            => function ($req) {
            $post_id = (int) $req->get_param('post_id');
            $author  = (int) $req->get_param('author');
            if (!$post_id || !$author || !get_post($post_id) || !get_userdata($author)) {
                return new WP_Error('rise_bad_request', 'post or author not found', ['status' => 400]);
            }
            if (!current_user_can('edit_post', $post_id)) {
                return new WP_Error('rise_forbidden', 'not allowed to edit this post', ['status' => 403]);
            }
            $r = wp_update_post(['ID' => $post_id, 'post_author' => $author], true);
            if (is_wp_error($r)) return $r;
            return ['ok' => true, 'author' => (int) get_post($post_id)->post_author];
        },
    ]);
});

// the Scholaria post type (blogpost) must accept an author through the REST API
add_action('init', function () {
    if (post_type_exists('blogpost') && !post_type_supports('blogpost', 'author')) {
        add_post_type_support('blogpost', 'author');
    }
}, 99);
