<?php
// Standalone integration of contact import, persistence and public visibility.
define('ABSPATH', __DIR__ . '/');
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function absint($v) { return abs((int)$v); }
function apply_filters($hook, $v) { return $v; }
function do_action(...$args) {}
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function sanitize_text_field($v) { return strip_tags($v); }
function esc_url_raw($url, $protocols) { return preg_match('#^https?://#', $url) ? $url : ''; }
function get_user_meta($id, $key, $single) { global $meta; return $meta[$key] ?? ''; }
function update_user_meta($id, $key, $value) { global $meta; $meta[$key] = $value; }
$meta = ['phone_number'=>'0555123456', '_tutor_profile_linkedin'=>'https://linkedin.com/in/test', '_tutor_profile_telegram'=>'https://t.me/test', '_tutor_social_profile'=>['youtube'=>'https://youtube.com/@test', 'github'=>'https://github.com/test', 'facebook'=>['bad']]];
require dirname(__DIR__) . '/tutor-lms-suite/modules/instructor-offline-payment/includes/class-contact.php';
use TutorInstructorOfflinePayment\Contact;
$imported = Contact::imported(7);
check($imported['linkedin'] === $meta['_tutor_profile_linkedin'], 'Import native LinkedIn');
check($imported['telegram'] === $meta['_tutor_profile_telegram'], 'Import Profile Extra Telegram');
check($imported['youtube'] === 'https://youtube.com/@test', 'Import bundled YouTube');
$contact = Contact::get(7);
check($contact['show_linkedin'] === 0 && $contact['show_telegram'] === 0, 'New networks default to private');
$contact['show_phone'] = $contact['show_whatsapp'] = 0;
$contact['show_linkedin'] = 1;
Contact::save(7, $contact);
$public = Contact::public_contact(7);
check($public['socials'] === ['linkedin'=>'https://linkedin.com/in/test'], 'Only opted-in profiles reach checkout');
$contact['show_linkedin'] = 0;
Contact::save(7, $contact);
check(Contact::public_contact(7)['socials'] === [], 'Visibility can be turned off again');
check(Contact::sanitize_url('javascript:alert(1)') === '', 'Unsafe protocols rejected');
check(Contact::sanitize_url(['bad']) === '', 'Malformed social URL rejected');
function check_ajax_referer(...$args) {}
function get_current_user_id() { return 7; }
function current_user_can($cap) { return true; }
function wp_unslash($v) { return $v; }
function is_wp_error($v) { return false; }
function __($v, $domain) { return $v; }
function wp_send_json_success($v) { throw new RuntimeException('json-success'); }
function wp_send_json_error($v, $code) { throw new RuntimeException('json-error'); }
require dirname(__DIR__) . '/tutor-lms-suite/modules/instructor-offline-payment/includes/class-ajax.php';
$_POST = ['linkedin'=>'https://linkedin.com/in/new', 'show_linkedin'=>'1'];
try { (new TutorInstructorOfflinePayment\Ajax())->save_contact(); } catch (RuntimeException $e) { check($e->getMessage() === 'json-success', 'AJAX contact save succeeds'); }
check(Contact::get(7, false)['show_linkedin'] === 1, 'AJAX persists checked visibility');
try { (new TutorInstructorOfflinePayment\Ajax())->import_contact(); } catch (RuntimeException $e) { check($e->getMessage() === 'json-success', 'AJAX profile re-import succeeds'); }
$record = Contact::get(7, false);
check($record['linkedin'] === $meta['_tutor_profile_linkedin'] && $record['show_linkedin'] === 1 && $record['show_telegram'] === 0, 'Re-import updates URLs and preserves visibility choices');
