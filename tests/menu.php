<?php
// Verify registration timing and capability without relaxing admin access.
define('ABSPATH', __DIR__ . '/');
function __($v, $domain) { return $v; }
function add_action($hook, $callback, $priority = 10) {
    if ($hook === 'admin_menu' && $callback === 'tls_suite_register_hub_page' && $priority <= 10) throw new RuntimeException('Hub registered before Tutor parent');
}
function add_submenu_page($parent, $title, $label, $cap, $slug, $callback) {
    if ($parent !== 'tutor' || $cap !== 'manage_options' || $slug !== 'tutor-lms-suite' || !is_callable($callback)) throw new RuntimeException('Invalid hub registration');
}
require dirname(__DIR__) . '/tutor-lms-suite/includes/class-suite-hub.php';
tls_suite_register_hub_page();
echo "PASS: Overview registers late and retains administrator capability\n";
