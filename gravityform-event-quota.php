<?php
/**
 * Plugin Name: Gravity Forms Event Quota
 * Description: Limits Gravity Forms choices by field-combination quotas. Full options are disabled and labelled on the form.
 * Version: 1.0.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: gf-event-quota
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFEQ_VERSION', '1.0.2' );
define( 'GFEQ_FILE', __FILE__ );
define( 'GFEQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'GFEQ_URL', plugin_dir_url( __FILE__ ) );

add_action( 'gform_loaded', 'gfeq_load', 5 );
add_action( 'admin_notices', 'gfeq_missing_gravity_forms_notice' );

/**
 * Boots the add-on after Gravity Forms has loaded.
 */
function gfeq_load() {
	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	GFForms::include_addon_framework();
	require_once GFEQ_PATH . 'includes/class-gfeq-calculator.php';
	require_once GFEQ_PATH . 'includes/class-gfeq-usage.php';
	require_once GFEQ_PATH . 'includes/class-gf-event-quota.php';

	GFAddOn::register( 'GF_Event_Quota' );
}

/**
 * Tells the site admin when the add-on cannot run.
 */
function gfeq_missing_gravity_forms_notice() {
	if ( class_exists( 'GFForms' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>Gravity Forms Event Quota requires Gravity Forms to be installed and activated.</p></div>';
}
