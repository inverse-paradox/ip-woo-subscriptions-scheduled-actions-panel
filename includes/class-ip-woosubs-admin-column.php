<?php
/**
 * Admin list table column feature class.
 *
 * Adds a renewal action status column to the WooCommerce subscriptions list
 * table, along with a filter dropdown. Supports both the classic CPT list and
 * the WooCommerce HPOS orders list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the renewal status column and filter on the subscriptions list table.
 */
class IP_WooSubs_Admin_Column {

	/**
	 * Reference to the core plugin class instance.
	 *
	 * @var IP_WooSubs_Scheduled_Actions_Panel
	 */
	private $core;

	/**
	 * Active value of the renewal filter dropdown.
	 * Set in apply_renewal_filter_cpt() and read in apply_renewal_filter_where_cpt().
	 *
	 * @var string
	 */
	private $active_renewal_filter = '';

	/**
	 * Constructor. Stores the core reference and registers the screen hook.
	 *
	 * @param IP_WooSubs_Scheduled_Actions_Panel $core Core plugin instance.
	 */
	public function __construct( IP_WooSubs_Scheduled_Actions_Panel $core ) {
		$this->core = $core;
		add_action( 'current_screen', array( $this, 'register_list_table_column_hooks' ) );
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Registers column hooks once the current admin screen is known.
	 * Handles both the classic CPT list and the HPOS orders list.
	 *
	 * @param WP_Screen $screen Current screen object.
	 */
	public function register_list_table_column_hooks( $screen ) {
		$cpt_screen  = 'edit-shop_subscription';
		$hpos_screen = $this->core->get_hpos_screen_id();

		if ( $cpt_screen === $screen->id ) {
			add_filter( 'manage_' . $cpt_screen . '_columns', array( $this, 'add_renewal_status_column' ) );
			add_action( 'manage_shop_subscription_posts_custom_column', array( $this, 'render_renewal_status_column' ), 10, 2 );
			add_action( 'restrict_manage_posts', array( $this, 'render_renewal_filter_dropdown_cpt' ) );
			add_action( 'pre_get_posts', array( $this, 'apply_renewal_filter_cpt' ) );
		} elseif ( $hpos_screen === $screen->id ) {
			add_filter( 'manage_' . $hpos_screen . '_columns', array( $this, 'add_renewal_status_column' ) );
			add_action( 'manage_' . $hpos_screen . '_custom_column', array( $this, 'render_renewal_status_column' ), 10, 2 );
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_renewal_filter_dropdown_hpos' ) );
			add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'apply_renewal_filter_hpos' ), 10, 3 );
		}
	}

	// -------------------------------------------------------------------------
	// Filter dropdown
	// -------------------------------------------------------------------------

	/**
	 * Outputs the renewal filter dropdown for the classic CPT list.
	 * Fires on restrict_manage_posts; checks post type before rendering.
	 *
	 * @param string $post_type Current list table post type.
	 */
	public function render_renewal_filter_dropdown_cpt( $post_type ) {
		if ( 'shop_subscription' !== $post_type ) {
			return;
		}
		$this->render_renewal_filter_dropdown();
	}

	/**
	 * Outputs the renewal filter dropdown for the HPOS subscriptions list.
	 * Fires on woocommerce_order_list_table_restrict_manage_orders.
	 */
	public function render_renewal_filter_dropdown_hpos() {
		$this->render_renewal_filter_dropdown();
	}

	/**
	 * Renders the shared renewal action filter <select> element.
	 */
	private function render_renewal_filter_dropdown() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['ip_wsap_renewal_filter'] ) ? sanitize_key( $_GET['ip_wsap_renewal_filter'] ) : '';
		?>
		<select name="ip_wsap_renewal_filter" id="ip_wsap_renewal_filter">
			<option value=""><?php esc_html_e( 'Renewal Action Schedule', 'ip-woosubs-sched-actions-panel' ); ?></option>
			<option value="has_action" <?php selected( $current, 'has_action' ); ?>>
				<?php esc_html_e( 'Has renewal action', 'ip-woosubs-sched-actions-panel' ); ?>
			</option>
			<option value="missing_action" <?php selected( $current, 'missing_action' ); ?>>
				<?php esc_html_e( 'Missing renewal action', 'ip-woosubs-sched-actions-panel' ); ?>
			</option>
		</select>
		<?php
	}

	// -------------------------------------------------------------------------
	// Filter query modification
	// -------------------------------------------------------------------------

	/**
	 * Applies the renewal filter to the classic CPT list query.
	 * Stores the active filter value and registers the WHERE clause callback.
	 *
	 * @param WP_Query $query Current query object.
	 */
	public function apply_renewal_filter_cpt( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'shop_subscription' !== $query->get( 'post_type' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['ip_wsap_renewal_filter'] ) ? sanitize_key( $_GET['ip_wsap_renewal_filter'] ) : '';
		if ( ! in_array( $filter, array( 'has_action', 'missing_action' ), true ) ) {
			return;
		}

		$this->active_renewal_filter = $filter;
		add_filter( 'posts_where', array( $this, 'apply_renewal_filter_where_cpt' ) );
	}

	/**
	 * Injects a correlated EXISTS / NOT EXISTS subquery into the CPT WHERE clause.
	 *
	 * @param string $where Existing WHERE clause.
	 * @return string
	 */
	public function apply_renewal_filter_where_cpt( $where ) {
		global $wpdb;

		remove_filter( 'posts_where', array( $this, 'apply_renewal_filter_where_cpt' ) );

		$exists   = ( 'has_action' === $this->active_renewal_filter ) ? 'EXISTS' : 'NOT EXISTS';
		$as_table = $wpdb->prefix . 'actionscheduler_actions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subquery = $wpdb->prepare(
			"SELECT 1 FROM `{$as_table}`
			WHERE hook = %s
			AND status = %s
			AND args LIKE CONCAT('%%\"subscription_id\":', {$wpdb->posts}.ID, '%%')",
			'woocommerce_scheduled_subscription_payment',
			'pending'
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$where .= " AND {$exists} ({$subquery})";

		return $where;
	}

	/**
	 * Applies the renewal filter to the HPOS orders list query.
	 *
	 * @param array                 $clauses    SQL clause fragments.
	 * @param \WC_Order_Query|mixed $query_obj  Query object.
	 * @param array                 $query_vars Raw query variables.
	 * @return array
	 */
	public function apply_renewal_filter_hpos( $clauses, $query_obj, $query_vars ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['ip_wsap_renewal_filter'] ) ? sanitize_key( $_GET['ip_wsap_renewal_filter'] ) : '';
		if ( ! in_array( $filter, array( 'has_action', 'missing_action' ), true ) ) {
			return $clauses;
		}

		global $wpdb;

		$exists       = ( 'has_action' === $filter ) ? 'EXISTS' : 'NOT EXISTS';
		$as_table     = $wpdb->prefix . 'actionscheduler_actions';
		$orders_table = $wpdb->prefix . 'wc_orders';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subquery = $wpdb->prepare(
			"SELECT 1 FROM `{$as_table}`
			WHERE hook = %s
			AND status = %s
			AND args LIKE CONCAT('%%\"subscription_id\":', {$orders_table}.id, '%%')",
			'woocommerce_scheduled_subscription_payment',
			'pending'
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clauses['where'] .= " AND {$exists} ({$subquery})";

		return $clauses;
	}

	// -------------------------------------------------------------------------
	// Column definition and rendering
	// -------------------------------------------------------------------------

	/**
	 * Inserts the Renewal Status column after the Status column.
	 *
	 * @param array $columns Existing list table columns.
	 * @return array Modified columns.
	 */
	public function add_renewal_status_column( $columns ) {
		$column_label = '<span title="' . esc_attr__( 'Renewal Action is scheduled', 'ip-woosubs-sched-actions-panel' ) . '">' . esc_html__( 'Has Action', 'ip-woosubs-sched-actions-panel' ) . '</span>';
		$new_columns  = array();
		$inserted     = false;

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			// Preferred: insert after the Next Payment column.
			if ( 'next_payment_date' === $key ) {
				$new_columns['ip_wsap_renewal'] = $column_label;
				$inserted = true;
			}
		}

		// Fallback: insert after Status if next_payment_date wasn't present.
		if ( ! $inserted ) {
			$new_columns = array();
			foreach ( $columns as $key => $label ) {
				$new_columns[ $key ] = $label;
				if ( 'status' === $key ) {
					$new_columns['ip_wsap_renewal'] = $column_label;
					$inserted = true;
				}
			}
		}

		// Last resort: append at the end.
		if ( ! $inserted ) {
			$new_columns['ip_wsap_renewal'] = $column_label;
		}

		return $new_columns;
	}

	/**
	 * Renders the Renewal Status column cell for a single subscription row.
	 * Works for both CPT ($post_id) and HPOS ($order_id).
	 *
	 * Outputs:
	 *   ✅  A pending renewal payment action exists.
	 *   ❌  No pending action but one is expected.
	 *   (empty) Scheduling is not applicable for this subscription.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Post/order ID.
	 */
	public function render_renewal_status_column( $column, $id ) {
		if ( 'ip_wsap_renewal' !== $column ) {
			return;
		}

		if ( ! function_exists( 'wcs_get_subscription' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $id );
		if ( ! $subscription ) {
			return;
		}

		if ( ! $this->core->renewal_payment_is_schedulable( $subscription ) ) {
			return;
		}

		if ( $this->core->has_pending_action( 'woocommerce_scheduled_subscription_payment', $subscription->get_id() ) ) {
			echo '<span title="' . esc_attr__( 'Renewal payment action is scheduled', 'ip-woosubs-sched-actions-panel' ) . '">&#x2705;</span>';
		} else {
			echo '<span title="' . esc_attr__( 'Renewal payment action is missing', 'ip-woosubs-sched-actions-panel' ) . '">&#x274C;</span>';
		}
	}
}
