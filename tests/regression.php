<?php
/** Standalone checks: php tests/regression.php (no WordPress installation needed). */
define('ABSPATH', __DIR__ . '/');
define('TLMS_PS_DB_VERSION', '22');
define('WP_DEBUG', true);
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$root = dirname(__DIR__) . '/tutor-lms-suite';
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if ($file->getExtension() === 'php') {
        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
        ++$count;
    }
}
check($count > 0, "Parsed $count PHP files");
class TCSP_Settings {
    public $promotion = false;
    public function get($key) { return $key === 'promote_paid_courses' ? $this->promotion : ($key === 'paid_course_weight' ? 10 : false); }
    public function promotion_tiers() { return []; }
}
class TCSP_Plugin {
    const PROMOTION_TIER_META = 'tier', PROMOTED_META = 'promoted', PRICE_TYPE_META = 'price', PROMOTION_START_META = 'start', PROMOTION_END_META = 'end', PRICE_TYPE_PAID = 'paid';
}
class TestDB {
    public $posts = 'wp_posts', $users = 'wp_users', $postmeta = 'wp_postmeta';
    public function prepare($sql, ...$args) { return $sql; }
    public function __call($name, $args) { throw new RuntimeException("Unexpected database work: $name"); }
}
$wpdb = new TestDB();
require $root . '/modules/course-search-pro/includes/class-tcsp-suggest.php';
function wp_strip_all_tags($text) { return strip_tags($text); }
$query = new class { public function get($key) { return $key === 'tcsp_is_suggest' ? true : ''; } };
$settings = new TCSP_Settings();
$suggest = new TCSP_Suggest($settings);
foreach (['', 'wp_posts.post_date DESC'] as $existing) {
    $clauses = $suggest->suggest_clauses(['join'=>'', 'where'=>'', 'groupby'=>'', 'orderby'=>$existing], $query);
    $expected = ($existing ? "$existing, " : '') . 'wp_posts.ID DESC';
    check($clauses['orderby'] === $expected, 'Disabled ranking preserves valid order: ' . $expected);
}
$settings->promotion = true;
$clauses = $suggest->suggest_clauses(['join'=>'', 'where'=>'', 'groupby'=>'', 'orderby'=>''], $query);
check(strpos($clauses['orderby'], 'CASE WHEN') !== false, 'Enabled paid ranking retains score');
function get_option($name, $default = false) { return $name === 'tlms_ps_db_version' ? TLMS_PS_DB_VERSION : $default; }
require $root . '/modules/private-sessions/class-private-sessions.php';
$class = new ReflectionClass('TLMS_Private_Sessions');
$instance = $class->newInstanceWithoutConstructor();
$property = $class->getProperty('wpdb'); if (PHP_VERSION_ID < 80100) $property->setAccessible(true); $property->setValue($instance, $wpdb);
$method = $class->getMethod('maybe_upgrade'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true); $method->invoke($instance);
check(true, 'Current schema performs no database work');
// Exercise the denial path with debug enabled and a correctly signed URL.
function absint($value) { return abs((int)$value); }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 123; }
function sanitize_text_field($value) { return $value; }
function wp_unslash($value) { return $value; }
function wp_salt($scheme) { return 'test-secret'; }
function esc_html__($value, $domain) { return $value; }
function wp_die($message, $title, $args) { throw new RuntimeException($args['response'] . ':' . $message); }
require __DIR__ . '/stubs-orders.php';
require $root . '/modules/instructor-offline-payment/includes/class-uploads.php';
$expires = time() + 60;
$_GET = ['order_id'=>42, 'tioc_expires'=>$expires, 'tioc_sig'=>hash_hmac('sha256', "42|$expires|0", 'test-secret')];
try {
    (new TutorInstructorOfflinePayment\Uploads())->serve();
    throw new RuntimeException('Expected access denial');
} catch (RuntimeException $e) {
    check($e->getMessage() === '403:You are not allowed to view this receipt.', 'Debug-enabled denial reveals no order details');
}
