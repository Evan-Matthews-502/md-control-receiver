<?php
// Exercise the key rotation handler with small WordPress stand-ins.
class WP_Error {
    public function __construct(public $code, public $message, public $data = []) {}
}
class WP_REST_Response {
    public function __construct(public $data = null, public $status = 200) {}
}
class WP_REST_Request {
    public function __construct(private $params = [], private $key = null) {}
    public function get_header($name) { return $this->key; }
    public function get_param($name) { return $this->params[$name] ?? null; }
}
function check($condition, $label) { if (!$condition) throw new Exception($label); }
function get_option($name) { return $GLOBALS['stale_option'] ?? $GLOBALS['options'][$name] ?? false; }
function wp_cache_delete($name, $group) { $GLOBALS['invalidations'][] = [$name, $group]; }
class RotationDatabase {
    public $options = 'wp_options';
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_var($query) { return $GLOBALS['options']['md_control_api_key'] ?? null; }
    public function query($query) {
        check(str_contains($query[0], 'AND BINARY option_value = %s'), 'atomic key comparison required');
        if ($GLOBALS['fail_writes']) return false;
        if (isset($GLOBALS['before_cas'])) {
            $callback = $GLOBALS['before_cas'];
            unset($GLOBALS['before_cas']);
            $callback();
        }
        [$new, $name, $old] = $query[1];
        if (($GLOBALS['options'][$name] ?? null) !== $old) return 0;
        $GLOBALS['options'][$name] = $new;
        return 1;
    }
}
$GLOBALS['wpdb'] = new RotationDatabase();
require __DIR__ . '/../includes/auth/middleware.php';
function get_bloginfo($name) { return 'Example Site'; }
function home_url() { return 'https://example.test'; }

define('ABSPATH', __DIR__ . '/');
define('MD_CONTROL_RECEIVER_VERSION', '1.2.9');
$code = file_get_contents(__DIR__ . '/../includes/api/endpoints.php');
eval(substr($code, strpos($code, '// === Handlers ===')));

$GLOBALS['fail_writes'] = false;
$old = str_repeat('a', 32);
$new = bin2hex(random_bytes(32));
$GLOBALS['options'] = ['md_control_api_key' => $old];

foreach ([null, '', [], 'not-hex', strtoupper($new), substr($new, 0, 63), $new . '0', $new . "\n"] as $invalid) {
    $result = md_control_rotate_key(new WP_REST_Request(['new_key' => $invalid], $old));
    check($result instanceof WP_Error && $result->data['status'] === 400, 'invalid key rejected');
    check($GLOBALS['options']['md_control_api_key'] === $old, 'invalid key leaves the current key');
}

$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $new], $GLOBALS['options']['md_control_api_key']));
check($result instanceof WP_REST_Response && $result->data['status'] === 'rotated', 'valid key rotated');
check($GLOBALS['options']['md_control_api_key'] === $new, 'new key stored');

$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $new], $GLOBALS['options']['md_control_api_key']));
check($result instanceof WP_REST_Response && $result->data['status'] === 'unchanged', 'repeat rotation is idempotent');

$GLOBALS['fail_writes'] = true;
$another = bin2hex(random_bytes(32));
$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $another], $new));
check($result instanceof WP_Error && $result->data['status'] === 500, 'failed write reported');
check($GLOBALS['options']['md_control_api_key'] === $new, 'failed write keeps the working key');

$GLOBALS['fail_writes'] = false;
$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $another], $old));
check($result instanceof WP_Error && $result->data['status'] === 401, 'old credentials rejected after rotation');
check($GLOBALS['options']['md_control_api_key'] === $new, 'stale request cannot overwrite current key');
$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $another]));
check($result instanceof WP_Error && $result->data['status'] === 401, 'handler rechecks missing credentials');

$winner = bin2hex(random_bytes(32));
$GLOBALS['before_cas'] = function () use ($winner) { $GLOBALS['options']['md_control_api_key'] = $winner; };
$result = md_control_rotate_key(new WP_REST_Request(['new_key' => $another], $new));
check($result instanceof WP_Error && $result->data['status'] === 409, 'concurrent key change returns conflict');
check($GLOBALS['options']['md_control_api_key'] === $winner, 'losing request preserves winning key');
check(in_array(['md_control_api_key', 'options'], $GLOBALS['invalidations'], true), 'individual option cache cleared');
check(in_array(['alloptions', 'options'], $GLOBALS['invalidations'], true), 'autoload cache cleared');
check(in_array(['notoptions', 'options'], $GLOBALS['invalidations'], true), 'missing option cache cleared');

// Check real route registration/authentication, not only a direct handler call.
$GLOBALS['actions'] = [];
function add_action($name, $callback) { $GLOBALS['actions'][$name] = $callback; }
function register_rest_route($namespace, $path, $args) { $GLOBALS['routes'][$path] = $args; }
$registration = substr($code, strpos($code, "add_action('rest_api_init'"));
$registration = substr($registration, 0, strpos($registration, '// === One-Click Login ==='));
eval($registration);
$GLOBALS['actions']['rest_api_init']();
$route = $GLOBALS['routes']['/rotate-key'];
check($route['methods'] === 'POST' && $route['permission_callback'] === 'md_control_authenticate', 'rotation route requires key authentication');
$GLOBALS['stale_option'] = $old;
check(md_control_authenticate(new WP_REST_Request([], $old)) === false, 'old key rejected by actual permission callback');
check(md_control_authenticate(new WP_REST_Request([], $winner)) === true, 'new key accepted by actual permission callback');

$ping = md_control_ping(new WP_REST_Request());
check($ping['plugin_version'] === '1.2.9' && !isset($ping['updates']), 'ping reports the plugin version');

$header = file_get_contents(__DIR__ . '/../md-control-receiver.php');
preg_match('/\* Version: (\S+)/', $header, $version);
preg_match("/define\('MD_CONTROL_RECEIVER_VERSION', '([^']+)'\)/", $header, $constant);
check(($version[1] ?? null) === ($constant[1] ?? false), 'version constant matches the plugin header');

echo "Key rotation checks passed.\n";
