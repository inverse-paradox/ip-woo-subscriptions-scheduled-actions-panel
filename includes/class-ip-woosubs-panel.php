<?php
/**
 * Editor panel (meta box) feature class.
 *
 * Registers and renders the Scheduled Actions sidebar meta box on the
 * WooCommerce subscription edit screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers and renders the Scheduled Actions meta box.
 */
class IP_WooSubs_Panel {

	/**
	 * Reference to the core plugin class instance.
	 *
	 * @var IP_WooSubs_Scheduled_Actions_Panel
	 */
	private $core;

	/**
	 * Whether the panel's inline styles have already been printed.
	 *
	 * @var bool
	 */
	private static $styles_printed = false;

	/**
	 * Constructor. Stores the core reference and registers the meta box hook.
	 *
	 * @param IP_WooSubs_Scheduled_Actions_Panel $core Core plugin instance.
	 */
	public function __construct( IP_WooSubs_Scheduled_Actions_Panel $core ) {
		$this->core = $core;
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

		if ( $this->core->is_hpos_enabled() ) {
			$hpos_screen = $this->core->get_hpos_screen_id();
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

		$subscription_id = $this->core->resolve_subscription_id( $post_or_order );

		if ( ! $subscription_id ) {
			echo '<p class="description">' . esc_html__( 'Unable to determine subscription ID.', 'ip-woosubs-sched-actions-panel' ) . '</p>';
			return;
		}

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			echo '<p class="description">' . esc_html__( 'Action Scheduler is not available.', 'ip-woosubs-sched-actions-panel' ) . '</p>';
			return;
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;

		echo '<div class="ip-wsap">';

		foreach ( $this->core->get_subscription_hooks() as $hook => $label ) {
			$this->render_hook_row( $hook, $label, $subscription_id, $subscription );
		}

		$as_url = $this->core->get_action_scheduler_search_url( $subscription_id );
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
	 * @param string               $hook            Action Scheduler hook name.
	 * @param string               $label           Human-readable label for the hook.
	 * @param int                  $subscription_id Subscription post ID.
	 * @param WC_Subscription|null $subscription    Subscription object, or null if unavailable.
	 */
	private function render_hook_row( $hook, $label, $subscription_id, $subscription = null ) {
		$actions     = $this->core->get_actions_for_hook( $hook, $subscription_id );
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
		echo '<span class="ip-wsap__label" title="' . esc_attr( $hook ) . '">' . esc_html( $label ) . '</span>';

		if ( null !== $display ) {
			$this->render_action_badge( $display );
		} else {
			echo '<span class="ip-wsap__badge ip-wsap__badge--none">' . esc_html__( 'Not scheduled', 'ip-woosubs-sched-actions-panel' ) . '</span>';
		}

		// Show a schedule link when no active (pending/running) action exists.
		if ( null === $active && null !== $subscription ) {
			$schedule = $this->core->get_schedule_link_url( $hook, $subscription_id, $subscription );
			if ( $schedule ) {
				$onclick = '';
				if ( $schedule['is_past'] ) {
					$confirm_msg = __( 'The scheduled date for this action is in the past. The action will be queued and run immediately on the next Action Scheduler pass. Continue?', 'ip-woosubs-sched-actions-panel' );
					$onclick     = ' onclick="return confirm(' . esc_attr( wp_json_encode( $confirm_msg ) ) . ')"';
				}
				printf(
					' <a href="%s" class="ip-wsap__schedule-link"%s>%s</a>',
					esc_url( $schedule['url'] ),
					$onclick, // Already escaped above.
					esc_html__( 'Schedule', 'ip-woosubs-sched-actions-panel' )
				);
			}
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
		$date_string = $this->core->get_action_date_string( $item['action'] );

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

			.ip-wsap__schedule-link {
				font-size: 11px;
				text-decoration: none;
				color: #0073aa;
				margin-left: 2px;
			}
			.ip-wsap__schedule-link:hover { text-decoration: underline; }

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
