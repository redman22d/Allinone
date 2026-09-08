<?php
/**
 * Module: Course Search Pro
 *
 * Full-text Tutor LMS course search, hierarchical taxonomy counts, advanced
 * filters, an organic normal-results list plus a dedicated sponsored
 * carousel, sponsorship tiers, sponsored card stickers, and smarter
 * autocomplete.
 *
 * Formerly the standalone "Tutor Course Search Pro" plugin (v3.8.3), now
 * merged as a module of Tutor LMS Suite.
 */

defined( 'ABSPATH' ) || exit;

require_once TCSP_DIR . 'includes/class-tcsp-settings.php';
require_once TCSP_DIR . 'includes/class-tcsp-sponsorship-manager.php';
require_once TCSP_DIR . 'includes/class-tcsp-multilingual.php';
require_once TCSP_DIR . 'includes/class-tcsp-query.php';
require_once TCSP_DIR . 'includes/class-tcsp-filters-ui.php';
require_once TCSP_DIR . 'includes/class-tcsp-suggest.php';
require_once TCSP_DIR . 'includes/class-tcsp-plugin.php';

/*
 * Matches this module's original standalone timing exactly: it never had a
 * Tutor-LMS-active gate of its own, so none is added here.
 */
TCSP_Plugin::instance();
