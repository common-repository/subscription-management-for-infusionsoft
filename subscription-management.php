<?php
/**
 * Plugin Name: Subscription Management for Infusionsoft
 * Description: Manage email subscription lists for InfusionSoft contacts
 * Version: 0.5.5
 * Author: Pirate & Fox
 * Author URI: http://pirateandfox.com
 * Text Domain: issubscriptionmanagement
 * Network: false
 * License: GPL2
 */

if (!function_exists('add_action'))
	exit;


define('SUBSCRIPTIONMANAGEMENT__PLUGIN_URL', plugin_dir_url( __FILE__ ));
define('SUBSCRIPTIONMANAGEMENT__PLUGIN_DIR', plugin_dir_path( __FILE__ ));
define('SUBSCRIPTIONMANAGEMENT__VERSION', '0.5.0');

register_activation_hook( __FILE__, [ 'subscriptionManagement', 'pluginActivation' ] );
register_deactivation_hook( __FILE__, [ 'subscriptionManagement', 'pluginDeactivation' ] );

require_once( SUBSCRIPTIONMANAGEMENT__PLUGIN_DIR . 'subscriptionManagement.php' );

if(!class_exists('iSDK'))
    require_once( SUBSCRIPTIONMANAGEMENT__PLUGIN_DIR . 'plugins/iSDK/isdk.php' );

add_action( 'init', array( 'subscriptionManagement', 'init' ) );

if (is_admin()) {
	require_once( SUBSCRIPTIONMANAGEMENT__PLUGIN_DIR . 'subscriptionManagementAdmin.php' );
	add_action( 'init', array('SubscriptionManagementAdmin', 'init') );
}
