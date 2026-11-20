<?php
/**
 * Plugin Name: Rise Scholars Route
 * Description: مسیر REST کوچک برای دریافت فهرست کاربران یک نقش (پیش‌فرض scholar) برای سکوی رایز.
 * Version: 1.0
 */
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
});
