<?php
/**
 * Plugin Name: IP WooCommerce Subscriptions Scheduled Actions Panel
 * Plugin URI : https://www.inverseparadox.com
 * Description: Adds a panel to the WooCommerce Subscriptions editor admin to display information on scheduled actions for the subscription.
 * Version:     1.3.0
 * Author:      Inverse Paradox
 * Author URI:  https://inverseparadox.com
 * Text Domain: ip-woosubs-sched-actions-panel
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-ip-woosubs-scheduled-actions-panel.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ip-woosubs-panel.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ip-woosubs-admin-column.php';

add_action( 'plugins_loaded', array( 'IP_WooSubs_Scheduled_Actions_Panel', 'get_instance' ) );
