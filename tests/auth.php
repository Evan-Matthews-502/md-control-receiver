<?php
// Exercise actual authentication code with small WordPress stand-ins.
class WP_Error {
    public function __construct(public $code, public $message, public $data = []) {}
}
class WP_REST_Request {
    public function __construct(private $key) {}
    public function get_header($name) { return $this->key; }
}
function get_option($name) { return $GLOBALS['secret']; }
function check($condition, $label) { if (!$condition) throw new Exception($label); }
require __DIR__ . '/../includes/auth/middleware.php';
$GLOBALS['secret'] = 'configured-key';
check(md_control_authenticate(new WP_REST_Request(null)) === false, 'missing header rejected');
check(md_control_authenticate(new WP_REST_Request('wrong')) === false, 'wrong key rejected');
check(md_control_authenticate(new WP_REST_Request('configured-key')) === true, 'correct key accepted');
$GLOBALS['secret'] = false;
check(md_control_authenticate(new WP_REST_Request('')) instanceof WP_Error, 'missing stored key rejected');
function add_action($name, $callback) { $GLOBALS['login'] = $callback; }
function sanitize_email($value) { return $value; }
function sanitize_text_field($value) { return $value; }
function wp_die($message) { throw new RuntimeException($message); }
function get_user_by($field, $value) { return (object) ['ID' => 42]; }
function wp_set_current_user($id) { $GLOBALS['current_user'] = $id; }
function wp_set_auth_cookie($id) { $GLOBALS['cookie'] = $id; }
function admin_url() { return 'https://example.test/wp-admin/'; }
function esc_url_raw($url) { return $url; }
function wp_safe_redirect($url) { throw new RuntimeException('safe_redirect'); }
$code = file_get_contents(__DIR__ . '/../includes/api/endpoints.php');
$start = strpos($code, "add_action('init'");
$end = strpos($code, '// === Handlers ===');
eval(substr($code, $start, $end - $start));
$_SERVER['REQUEST_URI'] = '/wp-login.php';
function attempt($params, $expected) {
    $_GET = $params;
    try { $GLOBALS['login'](); throw new Exception('login unexpectedly returned'); }
    catch (RuntimeException $error) { check($error->getMessage() === $expected, $expected); }
}
$params = ['user' => 'admin@example.test', 'expires' => (string) (time() + 300), 'sig' => 'wrong'];
// Even an otherwise correct signature using an empty secret must fail closed.
$GLOBALS['secret'] = false;
$empty_key = $params;
$empty_key['sig'] = hash_hmac('sha256', 'user=' . $params['user'] . '&expires=' . $params['expires'], '');
attempt($empty_key, 'API key is not configured.');
check(empty($GLOBALS['cookie']), 'missing secret cannot create session');
$GLOBALS['secret'] = 'configured-key';
attempt($params, 'Invalid signature.');
$malformed = $params; $malformed['sig'] = [];
attempt($malformed, 'Invalid login link.');
$expired = $params; $expired['expires'] = '1';
attempt($expired, 'Login link expired.');
$params['sig'] = hash_hmac('sha256', 'user=' . $params['user'] . '&expires=' . $params['expires'], $GLOBALS['secret']);
attempt($params, 'safe_redirect');
check($GLOBALS['cookie'] === 42 && $GLOBALS['current_user'] === 42, 'valid signed login retained');
echo "Authentication checks passed.\n";
