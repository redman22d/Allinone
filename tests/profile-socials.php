<?php
namespace TUTOR {
    class Icon { const GLOBE = 'globe'; const ANDROID_PHONE = 'phone'; }
}
namespace {
    define('ABSPATH', __DIR__ . '/');
    function __($text, $domain) { return $text; }
    function wp_strip_all_tags($text) { return strip_tags($text); }
    function check($ok, $message) { if (!$ok) throw new \RuntimeException($message); echo "PASS: $message\n"; }
    require dirname(__DIR__) . '/tutor-lms-suite/modules/instructor-profile-extra/includes/class-plugin.php';
    $plugin = (new \ReflectionClass('Tlms_Ipe_Plugin'))->newInstanceWithoutConstructor();
    $icons = $plugin->register_social_icons([]);
    $key = Tlms_Ipe_Plugin::META_YOUTUBE;
    check($key === '_tutor_profile_youtube' && $icons[$key]['label'] === 'YouTube', 'YouTube uses the payment-import meta key');
    $pattern = '~' . $icons[$key]['pattern'] . '~u';
    foreach (['https://www.youtube.com/@teacher', 'https://youtube.com/channel/UC123', 'https://youtube.com/c/teacher', 'https://youtube.com/user/teacher', 'https://youtube.com/@مدرس', 'https://youtu.be/abc123'] as $url) {
        check(preg_match($pattern, $url) === 1, "Accepted YouTube URL: $url");
    }
    foreach (['javascript:alert(1)', 'https://youtube.com.evil.test/@teacher', 'https://evil.test/youtube.com', 'https://youtube.com/', 'https://youtube.com/@bad name'] as $url) {
        check(preg_match($pattern, $url) === 0, "Rejected form URL: $url");
    }
    $native = ['label'=>'Existing YouTube', 'placeholder'=>'existing'];
    check($plugin->register_social_icons([$key=>$native])[$key] === $native, 'Existing YouTube definition is preserved');
}
