<?php
/**
 * Plugin Name: MD Control Receiver
 * Description: Remote management receiver for MD Control.
 * Version: 1.2.7
 * Author: Matthews Design
 */

defined('ABSPATH') || exit;

register_activation_hook(__FILE__, 'md_control_generate_api_key');

function md_control_generate_api_key() {
    $option_name = 'md_control_api_key';
    if (!get_option($option_name)) {
        $api_key = bin2hex(random_bytes(16));
        add_option($option_name, $api_key, '', false);
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/api/endpoints.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/access.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/settings-page.php';

add_action('init', function () {
    new MD_GitHub_Updater(__FILE__, [
        'user'  => 'Evan-Matthews-502',
        'repo'  => 'md-control-receiver',
    ]);
});

class MD_GitHub_Updater {
    private $file, $slug, $user, $repo;

    public function __construct($file, $args) {
        $this->file  = $file;
        $this->slug  = plugin_basename($file);
        $this->user  = $args['user'];
        $this->repo  = $args['repo'];

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'select_source'], 10, 4);
    }

    private function api_request($url) {
        $headers = ['User-Agent' => 'WordPress', 'Accept' => 'application/vnd.github.v3+json'];
        $response = wp_remote_get($url, ['headers' => $headers]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return false;

        return json_decode(wp_remote_retrieve_body($response));
    }

    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $release = $this->api_request("https://api.github.com/repos/{$this->user}/{$this->repo}/releases/latest");
        if (!$release || empty($release->tag_name) || !$this->release_package($release)) return $transient;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugin_data = get_plugin_data($this->file);
        $current = $plugin_data['Version'];
        $remote  = ltrim($release->tag_name, 'v');

        if (version_compare($remote, $current, '>')) {
            $transient->response[$this->slug] = (object)[
                'slug'        => dirname($this->slug),
                'plugin'      => $this->slug,
                'new_version' => $remote,
                'package'     => $this->release_package($release),
                'url'         => $release->html_url,
            ];
        }

        return $transient;
    }

    public function plugins_api($res, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== dirname($this->slug)) return $res;

        $release = $this->api_request("https://api.github.com/repos/{$this->user}/{$this->repo}/releases/latest");
        if (!$release || empty($release->tag_name) || !$this->release_package($release)) return $res;

        return (object)[
            'name'           => 'MD Control Receiver',
            'slug'           => dirname($this->slug),
            'version'        => ltrim($release->tag_name, 'v'),
            'author'         => 'Matthews Design',
            'homepage'       => $release->html_url,
            'download_link'  => $this->release_package($release),
            'sections'       => [
                'description' => $release->body ?? '',
            ],
        ];
    }

    private function release_package($release) {
        foreach (($release->assets ?? []) as $asset) {
            if (($asset->name ?? '') === 'md-control-receiver.zip') {
                return $asset->browser_download_url ?? false;
            }
        }
        return false;
    }

    public function select_source($source, $remote_source, $upgrader, $extra) {
        global $wp_filesystem;

        // Only touch an update explicitly targeting this installed plugin.
        if (is_wp_error($source) || ($extra['type'] ?? '') !== 'plugin'
            || ($extra['action'] ?? '') !== 'update'
            || ($extra['plugin'] ?? '') !== $this->slug) {
            return $source;
        }

        $destination = trailingslashit($remote_source) . dirname($this->slug);
        if (!$wp_filesystem->exists(trailingslashit($source) . basename($this->file))) {
            return new WP_Error('md_control_invalid_package', 'The update package is missing the plugin entry file.');
        }
        if (untrailingslashit($source) === untrailingslashit($destination)) {
            return $source;
        }
        if ($wp_filesystem->exists($destination)
            || !$wp_filesystem->move($source, $destination)) {
            return new WP_Error('md_control_source_move_failed', 'Could not prepare the plugin update directory.');
        }
        return trailingslashit($destination);
    }

}

add_filter('auto_update_plugin', function ($update, $item) {
    return ($item->plugin === plugin_basename(__FILE__)) ? true : $update;
}, 10, 2);

register_activation_hook(__FILE__, 'md_control_activation_redirect');

function md_control_activation_redirect() {
    add_option('md_control_do_activation_redirect', true);
}

add_action('admin_init', function () {
    if (get_option('md_control_do_activation_redirect')) {
        delete_option('md_control_do_activation_redirect');

        if (md_control_can_manage() && !isset($_GET['activate-multi'])) {
            wp_safe_redirect(admin_url('options-general.php?page=md-control-settings'));
            exit;
        }
    }
});
