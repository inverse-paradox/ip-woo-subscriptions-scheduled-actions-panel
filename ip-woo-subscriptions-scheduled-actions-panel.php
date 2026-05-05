<?php
/**
 * Plugin Name: IP WooCommerce Subscriptions Scheduled Actions Panel
 * Plugin URI : https://www.inverseparadox.com
 * Description: Adds a panel to the WooCommerce Subscriptions editor admin to display information on scheduled actions for the subscription.
 * Version:     1.0.0
 * Author:      Inverse Paradox
 * Author URI:  https://inverseparadox.com
 * Text Domain: ip-woosubs-sched-actions-panel
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Displays Action Scheduler entries associated with a WooCommerce subscription
 * in a sidebar meta box on the subscription edit screen.
 */
class IP_WooSubs_Scheduled_Actions_Panel {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether the panel's inline styles have already been printed.
	 *
	 * @var bool
	 */
	private static $styles_printed = false;

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
	 * Private constructor. Registers WordPress hooks.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	// -------------------------------------------------------------------------
	// Meta box registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the Scheduled Actions meta box on the subscription edit screen.
	 * Supports both the classic CPT editor and WooCommerce HPOS.
	 */
	public function register_meta_box() {
		$screens = array( 'shop_subscription' );

		if ( $this->is_hpos_enabled() ) {
			$hpos_screen = $this->get_hpos_screen_id();
			if ( $hpos_screen ) {
				$screens[] = $hpos_screen;
			}
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'ip-woosubs-scheduled-actions',
				__( 'Scheduled Actions', 'ip-woosubs-sched-actions-panel' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'low' // Renders below the built-in "Schedule" meta box.
			);
		}
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Renders the meta box content.
	 *
	 * @param WP_Post|WC_Abstract_Order $post_or_order Post or order object passed by WordPress.
	 */
	public function render_meta_box( $post_or_order ) {
		if ( ! self::$styles_printed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<style>' . $this->get_inline_styles() . '</style>';
			self::$styles_printed = true;
		}

		$subscription_id = $this->resolve_subscription_id( $post_or_order );

		if ( ! $subscription_id ) {
			echo '<p class="description">' . esc_html__( 'Unable to determine subscription ID.', 'ip-woosubs-sched-actions-panel' ) . '</p>';
			return;
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			echo '<p class="description">' . esc_html__( 'Action Scheduler is not available.', 'ip-woosubs-sched-actions-panel' ) . '</p>';
			return;
		}

		echo '<div class="ip-wsap">';

		foreach ( $this->get_subscription_hooks() as $hook => $label ) {
			$this->render_hook_row( $hook, $label, $subscription_id );
		}

		$as_url = $this->get_action_scheduler_search_url( $subscription_id );
		if ( $as_url ) {
			printf(
				'<p class="ip-wsap__footer"><a href="%s">%s</a></p>',
				esc_url( $as_url ),
				esc_html__( 'View all in Action Scheduler', 'ip-woosubs-sched-actions-panel' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders a single row showing the current status for one subscription hook.
	 *
	 * Prioritises any active (pending/running) action; falls back to the most
	 * recently scheduled action so historical context is always visible.
	 *
	 * @param string $hook            Action Scheduler hook name.
	 * @param string $label           Human-readable label for the hook.
	 * @param int    $subscription_id Subscription post ID.
	 */
	private function render_hook_row( $hook, $label, $subscription_id ) {
		$actions     = $this->get_actions_for_hook( $hook, $subscription_id );
		$active      = null;
		$most_recent = null;

		foreach ( $actions as $item ) {
			if ( in_array( $item['status'], array( 'pending', 'in-progress' ), true ) ) {
				// Capture the first active action found.
				if ( null === $active ) {
					$active = $item;
				}
			} elseif ( null === $most_recent ) {
				// Capture the most recent non-active action as a fallback.
				$most_recent = $item;
			}
		}

		$display = null !== $active ? $active : $most_recent;

		echo '<div class="ip-wsap__row">';
		echo '<span class="ip-wsap__label">' . esc_html( $label ) . '</span>';

		if ( null !== $display ) {
			$this->render_action_badge( $display );
		} else {
			echo '<span class="ip-wsap__badge ip-wsap__badge--none">' . esc_html__( 'Not scheduled', 'ip-woosubs-sched-actions-panel' ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * Renders a status badge and formatted date for a single action item.
	 *
	 * @param array{action: ActionScheduler_Action, status: string} $item Action tuple from get_actions_for_hook().
	 */
	private function render_action_badge( $item ) {
		$status        = $item['status'];
		$status_labels = array(
			'pending'     => __( 'Pending', 'ip-woosubs-sched-actions-panel' ),
			'in-progress' => __( 'Running', 'ip-woosubs-sched-actions-panel' ),
			'complete'    => __( 'Complete', 'ip-woosubs-sched-actions-panel' ),
			'failed'      => __( 'Failed', 'ip-woosubs-sched-actions-panel' ),
			'canceled'    => __( 'Cancelled', 'ip-woosubs-sched-actions-panel' ),
		);

		$label       = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
		$date_string = $this->get_action_date_string( $item['action'] );

		printf(
			'<span class="ip-wsap__badge ip-wsap__badge--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);

		if ( $date_string ) {
			echo ' <span class="ip-wsap__date">' . esc_html( $date_string ) . '</span>';
		}
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the list of WooCommerce Subscriptions hooks to surface in the panel.
	 * Translations are resolved here so they run after the text domain is loaded.
	 *
	 * @return array<string, string> Hook name => human-readable label.
	 */
	private function get_subscription_hooks() {
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
	private function get_actions_for_hook( $hook, $subscription_id ) {
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
	private function get_action_date_string( $action ) {
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
	private function resolve_subscription_id( $post_or_order ) {
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
	private function get_action_scheduler_search_url( $subscription_id ) {
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

	// -------------------------------------------------------------------------
	// HPOS helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true if WooCommerce High-Performance Order Storage is active.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled() {
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
	private function get_hpos_screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$id = wc_get_page_screen_id( 'shop_subscription' );
			if ( $id ) {
				return $id;
			}
		}

		return 'woocommerce_page_wc-orders--shop_subscription';
	}

	// -------------------------------------------------------------------------
	// Styles
	// -------------------------------------------------------------------------

	/**
	 * Returns the inline CSS for the meta box panel.
	 *
	 * @return string
	 */
	private function get_inline_styles() {
		return '
			.ip-wsap { font-size: 12px; line-height: 1.5; }

			.ip-wsap__row {
				display: flex;
				align-items: center;
				flex-wrap: wrap;
				gap: 3px 6px;
				padding: 6px 0;
				border-bottom: 1px solid #f0f0f0;
			}
			.ip-wsap__row:last-of-type { border-bottom: none; }

			.ip-wsap__label {
				flex: 1 0 100%;
				font-weight: 600;
				font-size: 11px;
				color: #1d2327;
			}

			.ip-wsap__badge {
				display: inline-block;
				padding: 1px 6px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				line-height: 1.7;
				white-space: nowrap;
			}
			.ip-wsap__badge--pending,
			.ip-wsap__badge--in-progress { background: #dbe7f3; color: #0073aa; }
			.ip-wsap__badge--complete     { background: #d7f2e3; color: #1a7f37; }
			.ip-wsap__badge--failed       { background: #fde8e8; color: #cc1818; }
			.ip-wsap__badge--canceled     { background: #f0f0f0; color: #787c82; }
			.ip-wsap__badge--none {
				background: transparent;
				color: #a7aaad;
				font-style: italic;
				text-transform: none;
				font-weight: 400;
				letter-spacing: 0;
				font-size: 11px;
				padding: 0;
			}

			.ip-wsap__date { font-size: 11px; color: #646970; }

			.ip-wsap__footer {
				margin: 8px 0 0;
				padding-top: 6px;
				border-top: 1px solid #f0f0f0;
				font-size: 11px;
				text-align: right;
			}
		';
	}
}

add_action( 'plugins_loaded', array( 'IP_WooSubs_Scheduled_Actions_Panel', 'get_instance' ) );
