<?php

require_once plugin_dir_path(__DIR__) . 'auth/middleware.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

add_action('rest_api_init', function () {
    // Site Ping
    register_rest_route('md-control/v1', '/ping', [
        'methods' => 'POST',
        'callback' => 'md_control_ping',
        'permission_callback' => 'md_control_authenticate',
    ]);

    // Key Rotation
    register_rest_route('md-control/v1', '/rotate-key', [
        'methods' => 'POST',
        'callback' => 'md_control_rotate_key',
        'permission_callback' => 'md_control_authenticate',
    ]);

    // Users
    register_rest_route('md-control/v1', '/users', [
        'methods' => 'GET',
        'callback' => 'md_control_list_users',
        'permission_callback' => 'md_control_authenticate',
    ]);

    register_rest_route('md-control/v1', '/users', [
        'methods' => 'POST',
        'callback' => 'md_control_create_user',
        'permission_callback' => 'md_control_authenticate',
    ]);

    register_rest_route('md-control/v1', '/users/(?P<id>\d+)', [
        'methods' => 'POST',
        'callback' => 'md_control_update_user',
        'permission_callback' => 'md_control_authenticate',
    ]);

    register_rest_route('md-control/v1', '/users/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'md_control_delete_user',
        'permission_callback' => 'md_control_authenticate',
    ]);


});

// === One-Click Login ===

add_action('init', function () {
    if (!isset($_GET['user'], $_GET['expires'], $_GET['sig'])) return;
    if (!str_contains($_SERVER['REQUEST_URI'] ?? '', 'wp-login.php')) return;
    if (!is_string($_GET['user']) || !is_string($_GET['expires']) || !is_string($_GET['sig'])) {
        wp_die('Invalid login link.');
    }

    $email = sanitize_email($_GET['user']);
    $expires = intval($_GET['expires']);
    $sig = sanitize_text_field($_GET['sig']);

    if (time() > $expires) wp_die('Login link expired.');

    $secret = md_control_get_api_key();
    if (!is_string($secret) || $secret === '') wp_die('API key is not configured.');
    $payload = "user=$email&expires=$expires";
    $expected = hash_hmac('sha256', $payload, $secret);

    if (!hash_equals($expected, $sig)) wp_die('Invalid signature.');

    $user = get_user_by('email', $email);
    if (!$user) wp_die('User not found.');

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID);

    $redirect = isset($_GET['redirect_to']) && is_string($_GET['redirect_to'])
        ? esc_url_raw($_GET['redirect_to']) : admin_url();
    wp_safe_redirect($redirect);
    exit;
});


// === Handlers === //

function md_control_ping(WP_REST_Request $request) {
    return [
        'site_name' => get_bloginfo('name'),
        'url' => home_url(),
        'plugin_version' => MD_CONTROL_RECEIVER_VERSION,
    ];
}

function md_control_rotate_key(WP_REST_Request $request) {
    global $wpdb;
    $new_key = $request->get_param('new_key');
    if (!is_string($new_key) || !preg_match('/\A[a-f0-9]{64}\z/', $new_key)) {
        return new WP_Error('invalid_key', 'The new key must be 64 lowercase hexadecimal characters.', ['status' => 400]);
    }

    // Recheck the actual database value: permission callbacks/options caches may be stale.
    $provided_key = $request->get_header('md-control-key');
    $option_name = 'md_control_api_key';
    $current_key = md_control_get_api_key();
    if (!is_string($provided_key) || !is_string($current_key) || $current_key === ''
        || !hash_equals($current_key, $provided_key)) {
        return new WP_Error('rest_forbidden', 'The current API key is required.', ['status' => 401]);
    }
    if (hash_equals($current_key, $new_key)) {
        return new WP_REST_Response(['status' => 'unchanged'], 200);
    }

    // Compare-and-swap prevents two already-authenticated requests from overwriting each other.
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = %s",
        $new_key, $option_name, $current_key
    ));
    if ($updated === false) {
        return new WP_Error('rotate_failed', 'The new key could not be saved.', ['status' => 500]);
    }
    wp_cache_delete($option_name, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    if ($updated !== 1) {
        return new WP_Error('rotation_conflict', 'The API key changed during this request. Retry using the current key.', ['status' => 409]);
    }
    return new WP_REST_Response(['status' => 'rotated'], 200);
}

function md_control_create_user(WP_REST_Request $request) {
    $username = sanitize_user($request->get_param('username'));
    $email = sanitize_email($request->get_param('email'));
    $password = $request->get_param('password');
    $role = sanitize_text_field($request->get_param('role'));

    if (!$username || !$email || !$password || !$role) {
        return new WP_Error('missing_fields', 'Missing one or more required fields.', ['status' => 400]);
    }

    if (username_exists($username) || email_exists($email)) {
        return new WP_Error('user_exists', 'User already exists.', ['status' => 409]);
    }

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        return new WP_Error('create_failed', $user_id->get_error_message(), ['status' => 500]);
    }

    $updated = wp_update_user(['ID' => $user_id, 'role' => $role]);
    if (is_wp_error($updated)) {
        return new WP_Error('role_failed', $updated->get_error_message(), ['status' => 500]);
    }

    return new WP_REST_Response(['status' => 'created', 'user_id' => $user_id], 201);
}

function md_control_update_user(WP_REST_Request $request) {
    $user_id = (int) $request->get_param('id');
    if (!get_userdata($user_id)) {
        return new WP_Error('user_not_found', 'User not found.', ['status' => 404]);
    }

    $data = ['ID' => $user_id];
    $password = $request->get_param('password');
    $role = sanitize_text_field($request->get_param('role'));

    if ($password) $data['user_pass'] = $password;

    $updated = wp_update_user($data);
    if (is_wp_error($updated)) {
        return new WP_Error('update_failed', $updated->get_error_message(), ['status' => 500]);
    }

    if ($role) {
        $user = new WP_User($user_id);
        $user->set_role($role);
    }

    return new WP_REST_Response(['status' => 'updated', 'user_id' => $user_id], 200);
}

function md_control_delete_user(WP_REST_Request $request) {
    $user_id = (int) $request->get_param('id');
    if (!get_userdata($user_id)) {
        return new WP_Error('user_not_found', 'User not found.', ['status' => 404]);
    }

    if (get_current_user_id() === $user_id) {
        return new WP_Error('cannot_delete_self', 'You cannot delete the current user.', ['status' => 403]);
    }

    $deleted = wp_delete_user($user_id, 1);
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Could not delete user.', ['status' => 500]);
    }

    return new WP_REST_Response(['status' => 'deleted', 'user_id' => $user_id], 200);
}

function md_control_list_users(WP_REST_Request $request) {
    $role_filter = sanitize_text_field($request->get_param('role'));
    $args = $role_filter ? ['role' => $role_filter] : [];

    $users = array_map(function($user) {
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'role' => $user->roles[0] ?? 'unknown',
        ];
    }, get_users($args));

    return new WP_REST_Response($users, 200);
}
