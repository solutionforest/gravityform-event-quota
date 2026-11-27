<?php
/**
 * Plugin Name: Event Quota for Gravity Forms
 * Plugin URI: https://github.com/solutionforest/gravityform-event-quota
 * Description: Limits Gravity Forms choices by field-combination quotas. Full options are disabled and labelled on the form.
 * Version: 1.0.3
 * Author: James
 * Author URI: http://www.solutionforest.net
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: gf-event-quota
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFEQ_VERSION', '1.0.3' );
define( 'GFEQ_FILE', __FILE__ );
define( 'GFEQ_PATH', plugin_dir_path( __FILE__ ) );
define( 'GFEQ_URL', plugin_dir_url( __FILE__ ) );

gfeq_register_update_checker();

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

	echo '<div class="notice notice-error"><p>Event Quota for Gravity Forms requires Gravity Forms to be installed and activated.</p></div>';
}

/**
 * Checks GitHub releases and offers updates on the Plugins screen.
 */
function gfeq_register_update_checker() {
	$library = GFEQ_PATH . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $library ) ) {
		return;
	}

	require $library;

	$updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/solutionforest/gravityform-event-quota/',
		GFEQ_FILE,
		'gravityform-event-quota'
	);
	$updates->getVcsApi()->enableReleaseAssets( '/^event-quota-for-gravity-forms-.+\.zip$/' );
}
