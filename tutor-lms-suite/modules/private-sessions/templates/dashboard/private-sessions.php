<?php
defined('ABSPATH') || exit;
if (class_exists('TLMS_Private_Sessions') && is_user_logged_in()) {
    echo TLMS_Private_Sessions::instance()->render_dashboard(get_current_user_id());
}
