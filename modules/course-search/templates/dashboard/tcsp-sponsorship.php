<?php
/** Tutor LMS dashboard sponsorship view. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( class_exists( 'TCSP_Plugin' ) && TCSP_Plugin::instance()->sponsorship_portal instanceof TCSP_Sponsorship_Portal ) {
    echo TCSP_Plugin::instance()->sponsorship_portal->shortcode();
}
