<?php
$GLOBALS['filters'] = [];
function add_filter($name, $callback) { $GLOBALS['filters'][$name] = $callback; }
function add_action(...$args) {}
function current_user_can($capability) { return $GLOBALS['can_manage']; }
function wp_get_current_user() { return (object) ['user_email' => $GLOBALS['email']]; }
function plugin_basename($file) { return $GLOBALS['folder'] . '/' . basename($file); }
function add_options_page(...$args) { $GLOBALS['page_registered'] = true; }
function wp_die($message, $title, $args) { throw new RuntimeException((string) $args['response']); }
function check($condition, $label) { if (!$condition) throw new Exception($label); }
require __DIR__ . '/../includes/admin/access.php';
require __DIR__ . '/../includes/admin/settings-page.php';
foreach (['md-control-receiver', 'md-control-receiver-main', 'md-control-receiver-1.2.6'] as $folder) {
    $GLOBALS['folder'] = $folder;
    foreach ([
        ['evan@matthewsdesign.co', true, true],
        ['webdev@matthewsdesign.co', true, true],
        ['EVAN@MATTHEWSDESIGN.CO', true, true],
        ['client@example.test', true, false],
        ['evan@matthewsdesign.co.attacker.test', true, false],
        ['evan@matthewsdesign.co', false, false],
        ['', false, false],
    ] as [$email, $capability, $allowed]) {
        $GLOBALS['email'] = $email;
        $GLOBALS['can_manage'] = $capability;
        check(md_control_can_manage() === $allowed, 'email and capability required');
        $key = "$folder/md-control-receiver.php";
        $plugins = $GLOBALS['filters']['all_plugins']([$key => [], 'other/other.php' => []]);
        check(isset($plugins[$key]) === $allowed, 'plugin visibility');
        check(isset($plugins['other/other.php']), 'other plugins unchanged');
        $GLOBALS['page_registered'] = false;
        md_control_add_settings_page();
        check($GLOBALS['page_registered'] === $allowed, 'settings visibility');
        if (!$allowed) {
            $_POST['regenerate_md_control_key'] = '1';
            try {
                md_control_render_settings_page();
                throw new Exception('settings access unexpectedly accepted');
            } catch (RuntimeException $error) {
                check($error->getMessage() === '403', 'direct access and key regeneration denied');
            }
        }
    }
}
echo "Admin access checks passed.\n";
