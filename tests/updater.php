<?php
// Isolated regression harness: no WordPress site or external requests required.
define('ABSPATH', '/unused/');
$GLOBALS['hooks'] = [];
function add_action($name, $callback) { $GLOBALS['hooks'][$name][] = $callback; }
function add_filter($name, $callback, ...$args) { $GLOBALS['hooks'][$name][] = $callback; }
function register_activation_hook(...$args) {}
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_basename($file) { return $GLOBALS['installed_folder'] . '/' . basename($file); }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function untrailingslashit($path) { return rtrim($path, '/'); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { public function __construct(public $code, public $message) {} }
// Skip unrelated endpoint/UI bootstrapping, but load the actual updater code.
$code = file_get_contents(__DIR__ . '/../md-control-receiver.php');
$start = strpos($code, "add_action('init'");
$end = strpos($code, "register_activation_hook(__FILE__, 'md_control_activation_redirect')");
$GLOBALS['installed_folder'] = 'md-control-receiver-main';
eval(substr($code, $start, $end - $start));
function check($condition, $label) { if (!$condition) { throw new Exception($label); } }
// Calling init without is_admin() defined proves no admin-only dependency remains.
$GLOBALS['hooks']['init'][0]();
check(isset($GLOBALS['hooks']['upgrader_source_selection']), 'background hook registered');
check(!isset($GLOBALS['hooks']['upgrader_post_install']), 'no post-install mover');
class TestFilesystem {
    public $moves = [];
    public $fail = false;
    public $missing = false;
    public $collision = false;
    function exists($path) { return str_ends_with($path, '.php') ? !$this->missing : $this->collision; }
    function move($source, $destination) { $this->moves[] = [$source, $destination]; return !$this->fail; }
}
foreach (['md-control-receiver-main', 'md-control-receiver-1.2.6', 'md-control-receiver'] as $folder) {
    $GLOBALS['installed_folder'] = $folder;
    $updater = new MD_GitHub_Updater('/installed/md-control-receiver.php', ['user' => 'test', 'repo' => 'test']);
    $wp_filesystem = new TestFilesystem();
    $extra = ['type' => 'plugin', 'action' => 'update', 'plugin' => "$folder/md-control-receiver.php"];
    $source = '/tmp/unpacked/md-control-receiver/';
    check($updater->select_source($source, '/tmp/unpacked/', null, $extra) === "/tmp/unpacked/$folder/", 'preserve installed folder');
    $wp_filesystem = new TestFilesystem();
    check($updater->select_source($source, '/tmp/unpacked/', null, []) === $source, 'ignore installs');
    $other = $extra; $other['plugin'] = 'other/other.php';
    check($updater->select_source($source, '/tmp/unpacked/', null, $other) === $source, 'ignore other plugin');
    check(!$wp_filesystem->moves, 'unrelated updates do not move files');
    $source = '/tmp/unpacked/md-control-receiver-9.0/';
    foreach (['fail', 'missing', 'collision'] as $failure) {
        $wp_filesystem = new TestFilesystem(); $wp_filesystem->$failure = true;
        check(is_wp_error($updater->select_source($source, '/tmp/unpacked/', null, $extra)), 'surface ' . $failure);
    }
    $error = new WP_Error('prior_error', 'prior');
    check($updater->select_source($error, '/tmp/unpacked/', null, $extra) === $error, 'preserve prior error');
    $method = new ReflectionMethod($updater, 'release_package');
    check($method->invoke($updater, (object) []) === false, 'no source ZIP fallback');
    check($method->invoke($updater, (object) ['assets' => [(object) ['name' => 'md-control-receiver.zip', 'browser_download_url' => 'https://example.test/package.zip']]]) === 'https://example.test/package.zip', 'select release asset');
}
$endpoints = file_get_contents(__DIR__ . '/../includes/api/endpoints.php');
check(!preg_match('/backup|ai1wm|wpress/i', $endpoints), 'backup API removed');
check(!file_exists(__DIR__ . '/../run-backup.php'), 'backup runner removed');
echo "Updater and backup removal checks passed.\n";
