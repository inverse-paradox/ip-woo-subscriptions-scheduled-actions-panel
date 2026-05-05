<?php
/**
 * Core plugin class and instantiation.
 *
 * Bootstraps the plugin, registers global hooks, and provides shared helper
 * methods used by the panel and admin-column feature classes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Core class for IP WooCommerce Subscriptions Scheduled Actions Panel.
 *
 * Manages the singleton lifecycle, bootstraps the feature sub-classes, and
 * exposes shared Action Scheduler / WooCommerce helper methods.
 */
class IP_WooSubs_Scheduled_Actions_Panel {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance, creating it on first call.
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Bootstraps feature sub-classes and registers hooks.
	 */
	private function __construct() {
		new IP_WooSubs_Panel( $this );
		new IP_WooSubs_Admin_Column( $this );

		add_action( 'admin_post_ip_wsap_schedule_action', array( $this, 'handle_schedule_action' ) );
		add_action( 'admin_notices', array( $this, 'maybe_display_notice' ) );
	}

	// -------------------------------------------------------------------------
	// Action scheduling handler
	// -------------------------------------------------------------------------

	/**
	 * Handles the admin-post request to manually schedule a single subscription action.
	 *
	 * Validates the nonce, capability, hook allowlist, and that the subscription
	 * has a future date before scheduling via Action Scheduler.
	 */
	public function handle_schedule_action() {
		$hook            = isset( $_GET['hook'] ) ? sanitize_text_field( wp_unslash( $_GET['hook'] ) ) : '';
		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ip-woosubs-sched-actions-panel' ) );
		}

		check_admin_referer( 'ip_wsap_schedule_' . $hook . '_' . $subscription_id );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url();

		// Validate the hook is one we explicitly support for manual scheduling.
		$schedulable = $this->get_schedulable_hook_date_keys();
		if ( ! isset( $schedulable[ $hook ] ) ) {
			wp_die( esc_html__( 'This hook cannot be manually scheduled.', 'ip-woosubs-sched-actions-panel' ) );
		}

		if ( ! $subscription_id ) {
			wp_die( esc_html__( 'Invalid subscription ID.', 'ip-woosubs-sched-actions-panel' ) );
		}

		// Bail if a pending action already exists (double-click guard).
		$existing_ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => array( 'subscription_id' => $subscription_id ),
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		if ( ! empty( $existing_ids ) ) {
			wp_safe_redirect( add_query_arg( 'ip_wsap_notice', 'already_scheduled', $redirect ) );
			exit;
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
		if ( ! $subscription ) {
			wp_die( esc_html__( 'Subscription not found.', 'ip-woosubs-sched-actions-panel' ) );
		}

		$timestamp = $subscription->get_time( $schedulable[ $hook ] );
		if ( ! $timestamp ) {
			wp_safe_redirect( add_query_arg( 'ip_wsap_notice', 'no_date', $redirect ) );
			exit;
		}

		as_schedule_single_action(
			$timestamp,
			$hook,
			array( 'subscription_id' => $subscription_id ),
			'woocommerce-subscriptions'
		);

		wp_safe_redirect( add_query_arg( 'ip_wsap_notice', 'scheduled', $redirect ) );
		exit;
	}

	/**
	 * Displays an admin notice after a schedule action redirect.
	 */
	public function maybe_display_notice() {
		if ( empty( $_GET['ip_wsap_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( $_GET['ip_wsap_notice'] );
		$map    = array(
			'scheduled'         => array(
				'type' => 'success',
				'msg'  => __( 'Scheduled action created successfully.', 'ip-woosubs-sched-actions-panel' ),
			),
			'already_scheduled' => array(
				'type' => 'info',
				'msg'  => __( 'A pending action already exists for this hook.', 'ip-woosubs-sched-actions-panel' ),
			),
			'no_date'           => array(
				'type' => 'warning',
				'msg'  => __( 'No date is set on the subscription for this action; nothing was scheduled.', 'ip-woosubs-sched-actions-panel' ),
			),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		$data = $map[ $notice ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $data['type'] ),
			esc_html( $data['msg'] )
		);
	}

	// -------------------------------------------------------------------------
	// Shared data helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the list of WooCommerce Subscriptions hooks to surface in the panel.
	 * Translations are resolved here so they run after the text domain is loaded.
	 *
	 * @return array<string, string> Hook name => human-readable label.
	 */
	public function get_subscription_hooks() {
		return array(
			'woocommerce_scheduled_subscription_payment'                                => __( 'Renewal Payment', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_trial_end'                              => __( 'Trial End', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_expiration'                             => __( 'Expiration', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_end_of_prepaid_term'                    => __( 'End of Prepaid Term', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_payment_retry'                          => __( 'Payment Retry', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_customer_notification'                  => __( 'Customer Notification', 'ip-woosubs-sched-actions-panel' ),
			'woocommerce_scheduled_subscription_customer_notification_trial_expiration' => __( 'Trial Expiration Notification', 'ip-woosubs-sched-actions-panel' ),
		);
	}

	/**
	 * Fetches up to five actions for a given hook and subscription.
	 *
	 * Returns an array of associative arrays, each with:
	 *   'action' => ActionScheduler_Action
	 *   'status' => string
	 *
	 * ActionScheduler_Action has no get_status() method — status is owned by
	 * the store — so we resolve it via ActionScheduler::store().
	 *
	 * @param string $hook            Action Scheduler hook name.
	 * @param int    $subscription_id Subscription post ID.
	 * @return array<int, array{action: ActionScheduler_Action, status: string}>
	 */
	public function get_actions_for_hook( $hook, $subscription_id ) {
		// Pass 'ids' explicitly so we get a plain array of integer action IDs.
		// The default return format (OBJECT) yields action objects keyed by ID,
		// which would cause the store->get_status() call below to receive an object.
		$action_ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => array( 'subscription_id' => (int) $subscription_id ),
				'per_page' => 5,
				'orderby'  => 'scheduled_date_gmt',
				'order'    => 'DESC',
			),
			'ids'
		);

		if ( empty( $action_ids ) ) {
			return array();
		}

		$store   = ActionScheduler::store();
		$results = array();

		foreach ( $action_ids as $id ) {
			$results[] = array(
				'action' => $store->fetch_action( $id ),
				'status' => $store->get_status( $id ),
			);
		}

		return $results;
	}

	/**
	 * Formats the scheduled date of an action in the site's local timezone.
	 *
	 * @param ActionScheduler_Action $action The action.
	 * @return string Formatted date string, or empty string when unavailable.
	 */
	public function get_action_date_string( $action ) {
		$schedule = $action->get_schedule();

		if ( ! $schedule || ! method_exists( $schedule, 'get_date' ) ) {
			return '';
		}

		$date = $schedule->get_date();

		if ( ! $date instanceof DateTime ) {
			return '';
		}

		return get_date_from_gmt(
			gmdate( 'Y-m-d H:i:s', $date->getTimestamp() ),
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
		);
	}

	/**
	 * Resolves the subscription ID from either a WP_Post (classic editor) or a
	 * WC order object (HPOS).
	 *
	 * @param WP_Post|object $post_or_order The object passed to the meta box callback.
	 * @return int|null Subscription ID, or null on failure.
	 */
	public function resolve_subscription_id( $post_or_order ) {
		if ( $post_or_order instanceof WP_Post ) {
			return (int) $post_or_order->ID;
		}

		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
			return (int) $post_or_order->get_id();
		}

		return null;
	}

	/**
	 * Returns an Action Scheduler admin URL pre-filtered by subscription ID.
	 *
	 * @param int $subscription_id Subscription post ID.
	 * @return string|false URL string, or false if the AS admin page is unavailable.
	 */
	public function get_action_scheduler_search_url( $subscription_id ) {
		if ( ! class_exists( 'ActionScheduler_AdminView' ) ) {
			return false;
		}

		return add_query_arg(
			array(
				'page' => 'action-scheduler',
				's'    => rawurlencode( '"subscription_id":' . absint( $subscription_id ) ),
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Returns an array describing the schedule link for a given hook, or null if
	 * the hook is not schedulable or has no date set on the subscription.
	 *
	 * Return shape:
	 *   'url'     => string  Nonce-protected admin-post URL.
	 *   'is_past' => bool    True when the subscription date is already in the past.
	 *
	 * @param string          $hook            Action Scheduler hook name.
	 * @param int             $subscription_id Subscription post ID.
	 * @param WC_Subscription $subscription    Subscription object.
	 * @return array{url: string, is_past: bool}|null
	 */
	public function get_schedule_link_url( $hook, $subscription_id, $subscription ) {
		// For the renewal payment hook, use the shared schedulability check which
		// covers terminal statuses, unpaid orders, and missing next-payment date.
		// For other hooks, only block on terminal statuses.
		if ( 'woocommerce_scheduled_subscription_payment' === $hook ) {
			if ( ! $this->renewal_payment_is_schedulable( $subscription ) ) {
				return null;
			}
		} else {
			$terminal_statuses = array( 'cancelled', 'expired', 'switched', 'trash' );
			if ( in_array( $subscription->get_status(), $terminal_statuses, true ) ) {
				return null;
			}
		}

		$schedulable = $this->get_schedulable_hook_date_keys();

		if ( ! isset( $schedulable[ $hook ] ) ) {
			return null;
		}

		$timestamp = $subscription->get_time( $schedulable[ $hook ] );
		if ( ! $timestamp ) {
			return null;
		}

		return array(
			'url'     => wp_nonce_url(
				add_query_arg(
					array(
						'action'          => 'ip_wsap_schedule_action',
						'subscription_id' => absint( $subscription_id ),
						'hook'            => $hook,
					),
					admin_url( 'admin-post.php' )
				),
				'ip_wsap_schedule_' . $hook . '_' . $subscription_id
			),
			'is_past' => $timestamp < time(),
		);
	}

	/**
	 * Returns true when it is appropriate to expect a pending renewal payment
	 * action for the given subscription. Used by both the schedule link and
	 * the list table column indicator.
	 *
	 * Returns false when:
	 *   - The subscription has a terminal status (cancelled, expired, switched, trash).
	 *   - An unpaid order (parent or renewal) is already awaiting payment.
	 *   - The subscription has no next-payment date set.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return bool
	 */
	public function renewal_payment_is_schedulable( $subscription ) {
		$terminal_statuses = array( 'cancelled', 'expired', 'switched', 'trash' );
		if ( in_array( $subscription->get_status(), $terminal_statuses, true ) ) {
			return false;
		}

		$parent_order = $subscription->get_parent();
		if ( $parent_order && $parent_order->needs_payment() ) {
			return false;
		}

		$renewal_order_ids = $subscription->get_related_orders( 'ids', 'renewal' );
		foreach ( $renewal_order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && $order->needs_payment() ) {
				return false;
			}
		}

		if ( ! $subscription->get_time( 'next_payment' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns true if at least one pending Action Scheduler action exists for
	 * the given hook and subscription ID.
	 *
	 * @param string $hook            Action Scheduler hook name.
	 * @param int    $subscription_id Subscription post ID.
	 * @return bool
	 */
	public function has_pending_action( $hook, $subscription_id ) {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => array( 'subscription_id' => (int) $subscription_id ),
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $ids );
	}

	// -------------------------------------------------------------------------
	// HPOS helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true if WooCommerce High-Performance Order Storage is active.
	 *
	 * @return bool
	 */
	public function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Returns the screen ID used by the subscription edit page under HPOS.
	 *
	 * Tries the WooCommerce helper function first and falls back to the known
	 * default used by WooCommerce Subscriptions.
	 *
	 * @return string
	 */
	public function get_hpos_screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$id = wc_get_page_screen_id( 'shop_subscription' );
			if ( $id ) {
				return $id;
			}
		}

		return 'woocommerce_page_wc-orders--shop_subscription';
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the hooks that support manual scheduling, mapped to the
	 * WC_Subscription date key used to determine the timestamp.
	 *
	 * Notification and retry hooks are excluded because their schedule
	 * offsets are managed internally by WooCommerce Subscriptions.
	 *
	 * @return array<string, string> Hook => subscription date key.
	 */
	private function get_schedulable_hook_date_keys() {
		return array(
			'woocommerce_scheduled_subscription_payment'             => 'next_payment',
			'woocommerce_scheduled_subscription_trial_end'           => 'trial_end',
			'woocommerce_scheduled_subscription_expiration'          => 'end',
			'woocommerce_scheduled_subscription_end_of_prepaid_term' => 'end',
		);
	}
}
