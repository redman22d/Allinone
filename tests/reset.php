<?php
// Reset must leave category/search/pagination contexts for the course archive.
define('ABSPATH', __DIR__ . '/');
class TCSP_Plugin { const COURSE_CPT = 'courses'; }
function get_post_type_archive_link($type) { global $archive; return $archive; }
function home_url($path) { return 'https://example.test' . $path; }
require dirname(__DIR__) . '/tutor-lms-suite/modules/course-search-pro/includes/class-tcsp-filters-ui.php';
$class = new ReflectionClass('TCSP_Filters_UI');
$instance = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('reset_url');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
foreach (['https://example.test/courses/', false] as $archive) {
    if ($method->invoke($instance) !== ($archive ?: 'https://example.test/')) throw new RuntimeException('Reset URL incorrect');
}
echo "PASS: Global reset returns archive with safe fallback\n";
