<?php
/**
 * WP-CLI commands for IP WooCommerce Subscriptions Scheduled Actions Panel.
 *
 * Registers subcommands under the `wp wc subscription` namespace for finding
 * and scheduling missing renewal payment actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Manage renewal payment actions for WooCommerce Subscriptions.
 */
class IP_WooSubs_CLI {

	/**
	 * Action Scheduler hook for renewal payments.
	 */
	const RENEWAL_HOOK = 'woocommerce_scheduled_subscription_payment';

	/**
	 * Reference to the core plugin instance.
	 *
	 * @var IP_WooSubs_Scheduled_Actions_Panel
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param IP_WooSubs_Scheduled_Actions_Panel $core Core plugin instance.
	 */
	public function __construct( IP_WooSubs_Scheduled_Actions_Panel $core ) {
		$this->core = $core;
	}

	/**
	 * Registers WP-CLI subcommands under `wp wc subscription`.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'wc subscription list-missing-renewal', array( $this, 'list_missing_renewal' ) );
		WP_CLI::add_command( 'wc subscription schedule-renewal', array( $this, 'schedule_renewal' ) );
	}

	// -------------------------------------------------------------------------
	// Commands
	// -------------------------------------------------------------------------

	/**
	 * List active subscriptions that are missing a scheduled renewal payment action.
	 *
	 * Checks every active subscription to determine whether a pending renewal
	 * payment action exists in Action Scheduler. Only subscriptions that are
	 * genuinely expected to have one (i.e. not on-hold due to an unpaid order,
	 * and with a next payment date set) are included.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepted values: table, csv, json, ids, count.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to include in table/csv/json output.
	 * Available fields: id, status, next_payment, customer.
	 * ---
	 * default: id,status,next_payment,customer
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # List as a table (default)
	 *   wp wc subscription list-missing-renewal
	 *
	 *   # Output IDs only, one per line
	 *   wp wc subscription list-missing-renewal --format=ids
	 *
	 *   # Output as JSON
	 *   wp wc subscription list-missing-renewal --format=json
	 *
	 *   # Show only the count
	 *   wp wc subscription list-missing-renewal --format=count
	 *
	 * @subcommand list-missing-renewal
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_missing_renewal( $args, $assoc_args ) {
		$this->require_dependencies();

		$format       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$fields_input = \WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'id,status,next_payment,customer' );
		$fields       = array_map( 'trim', explode( ',', $fields_input ) );

		WP_CLI::log( 'Querying active subscriptions...' );

		$missing = $this->find_missing_renewal_subscriptions();

		if ( empty( $missing ) ) {
			WP_CLI::success( 'No active subscriptions are missing a renewal action.' );
			return;
		}

		if ( 'ids' === $format ) {
			echo implode( "\n", wp_list_pluck( $missing, 'id' ) ) . "\n";
			return;
		}

		if ( 'count' === $format ) {
			WP_CLI::log( (string) count( $missing ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $missing, $fields );
	}

	/**
	 * Schedule a renewal payment action for one or more subscriptions.
	 *
	 * Schedules an Action Scheduler entry for
	 * `woocommerce_scheduled_subscription_payment` using the subscription's
	 * next payment date as the timestamp. Skips subscriptions that already have
	 * a pending action or are not in a schedulable state.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : One or more subscription IDs to schedule a renewal action for.
	 *
	 * [--all]
	 * : Schedule renewal actions for all active subscriptions that are missing one.
	 *
	 * [--dry-run]
	 * : Preview what would be scheduled without making any changes.
	 *
	 * ## EXAMPLES
	 *
	 *   # Schedule a renewal action for a single subscription
	 *   wp wc subscription schedule-renewal 123
	 *
	 *   # Schedule for multiple subscriptions at once
	 *   wp wc subscription schedule-renewal 123 456 789
	 *
	 *   # Schedule for all subscriptions currently missing a renewal action
	 *   wp wc subscription schedule-renewal --all
	 *
	 *   # Preview what --all would do without changing anything
	 *   wp wc subscription schedule-renewal --all --dry-run
	 *
	 * @subcommand schedule-renewal
	 *
	 * @param array $args       Positional arguments (subscription IDs).
	 * @param array $assoc_args Associative arguments.
	 */
	public function schedule_renewal( $args, $assoc_args ) {
		$this->require_dependencies();

		$all     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( empty( $args ) && ! $all ) {
			WP_CLI::error( 'Provide one or more subscription IDs, or pass --all to process every subscription missing a renewal action.' );
		}

		if ( ! empty( $args ) && $all ) {
			WP_CLI::error( 'Cannot combine specific subscription IDs with --all.' );
		}

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run enabled — no actions will be scheduled.' );
		}

		if ( $all ) {
			$this->schedule_all_missing( $dry_run );
		} else {
			$this->schedule_by_ids( $args, $dry_run );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Asserts that required WooCommerce Subscriptions and Action Scheduler
	 * functions are available; halts with an error if not.
	 */
	private function require_dependencies() {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
		}

		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			WP_CLI::error( 'WooCommerce Subscriptions is not active.' );
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			WP_CLI::error( 'Action Scheduler is not available.' );
		}
	}

	/**
	 * Returns all active subscriptions that are schedulable but have no pending
	 * renewal payment action. Results are paginated internally to avoid memory
	 * exhaustion on large datasets.
	 *
	 * @return array[] Each item: ['id' => int, 'status' => string, 'next_payment' => string, 'customer' => string]
	 */
	private function find_missing_renewal_subscriptions() {
		$results    = array();
		$per_page   = 50;
		$page       = 1;
		$page_count = 0;

		do {
			$subscriptions = wcs_get_subscriptions(
				array(
					'subscription_status'    => array( 'active' ),
					'subscriptions_per_page' => $per_page,
					'offset'                 => ( $page - 1 ) * $per_page,
					'order'                  => 'ASC',
					'orderby'                => 'ID',
				)
			);

			if ( empty( $subscriptions ) ) {
				break;
			}

			$page_count = count( $subscriptions );

			foreach ( $subscriptions as $subscription ) {
				if ( ! $this->core->renewal_payment_is_schedulable( $subscription ) ) {
					continue;
				}

				if ( $this->core->has_pending_action( self::RENEWAL_HOOK, $subscription->get_id() ) ) {
					continue;
				}

				$results[] = $this->format_subscription_row( $subscription );
			}

			$page++;
		} while ( $page_count === $per_page );

		return $results;
	}

	/**
	 * Schedules renewal actions for all active subscriptions currently missing one.
	 *
	 * @param bool $dry_run If true, reports what would happen without scheduling.
	 */
	private function schedule_all_missing( $dry_run ) {
		WP_CLI::log( 'Searching for active subscriptions missing a renewal action...' );

		$missing = $this->find_missing_renewal_subscriptions();

		if ( empty( $missing ) ) {
			WP_CLI::success( 'No active subscriptions are missing a renewal action.' );
			return;
		}

		$total    = count( $missing );
		$verb     = $dry_run ? 'Would schedule' : 'Scheduling';
		WP_CLI::log( "{$verb} renewal actions for {$total} subscription(s)." );

		$progress  = \WP_CLI\Utils\make_progress_bar( 'Processing', $total );
		$scheduled = 0;
		$skipped   = 0;

		foreach ( $missing as $row ) {
			$id     = (int) $row['id'];
			$result = $this->schedule_single( $id, $dry_run );

			if ( $result ) {
				$scheduled++;
			} else {
				WP_CLI::warning( "Subscription {$id}: no next payment date found. Skipping." );
				$skipped++;
			}

			$progress->tick();
		}

		$progress->finish();

		if ( $dry_run ) {
			WP_CLI::success( "Dry run complete. Would schedule: {$scheduled}. Skipped: {$skipped}." );
		} else {
			WP_CLI::success( "Done. Scheduled: {$scheduled}. Skipped: {$skipped}." );
		}
	}

	/**
	 * Schedules renewal actions for an explicit list of subscription IDs,
	 * validating each one before scheduling.
	 *
	 * @param string[] $ids     Raw subscription ID arguments from the CLI.
	 * @param bool     $dry_run If true, reports what would happen without scheduling.
	 */
	private function schedule_by_ids( $ids, $dry_run ) {
		$scheduled = 0;
		$skipped   = 0;
		$errors    = 0;

		foreach ( $ids as $raw_id ) {
			$id = absint( $raw_id );

			if ( ! $id ) {
				WP_CLI::warning( "Skipping invalid ID: {$raw_id}" );
				$errors++;
				continue;
			}

			$subscription = wcs_get_subscription( $id );

			if ( ! $subscription ) {
				WP_CLI::warning( "Subscription {$id}: not found." );
				$errors++;
				continue;
			}

			if ( ! $this->core->renewal_payment_is_schedulable( $subscription ) ) {
				WP_CLI::log( "Subscription {$id}: renewal is not schedulable (terminal status or pending payment). Skipping." );
				$skipped++;
				continue;
			}

			if ( $this->core->has_pending_action( self::RENEWAL_HOOK, $id ) ) {
				WP_CLI::log( "Subscription {$id}: already has a pending renewal action. Skipping." );
				$skipped++;
				continue;
			}

			$result = $this->schedule_single( $id, $dry_run );

			if ( $result ) {
				$verb = $dry_run ? 'Would schedule' : 'Scheduled';
				WP_CLI::log( "{$verb}: renewal action for subscription {$id}." );
				$scheduled++;
			} else {
				WP_CLI::warning( "Subscription {$id}: no next payment date set. Cannot schedule." );
				$skipped++;
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( "Dry run complete. Would schedule: {$scheduled}. Skipped: {$skipped}. Errors: {$errors}." );
		} else {
			WP_CLI::success( "Done. Scheduled: {$scheduled}. Skipped: {$skipped}. Errors: {$errors}." );
		}
	}

	/**
	 * Schedules a single renewal payment action for the given subscription ID.
	 *
	 * Does NOT check for duplicate actions — callers are responsible for
	 * verifying via has_pending_action() before calling this method.
	 *
	 * @param int  $subscription_id Subscription ID.
	 * @param bool $dry_run         If true, validate only — do not actually schedule.
	 * @return bool True if scheduled (or would be), false if no next payment date exists.
	 */
	private function schedule_single( $subscription_id, $dry_run = false ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return false;
		}

		$timestamp = $subscription->get_time( 'next_payment' );

		if ( ! $timestamp ) {
			return false;
		}

		if ( ! $dry_run ) {
			as_schedule_single_action(
				$timestamp,
				self::RENEWAL_HOOK,
				array( 'subscription_id' => (int) $subscription_id ),
				'woocommerce-subscriptions'
			);
		}

		return true;
	}

	/**
	 * Formats a subscription object into a display row for CLI output.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array{id: int, status: string, next_payment: string, customer: string}
	 */
	private function format_subscription_row( $subscription ) {
		$next_payment = $subscription->get_time( 'next_payment' );
		$customer_id  = $subscription->get_customer_id();
		$customer     = $customer_id ? get_userdata( $customer_id ) : null;

		return array(
			'id'           => $subscription->get_id(),
			'status'       => $subscription->get_status(),
			'next_payment' => $next_payment ? gmdate( 'Y-m-d H:i:s', $next_payment ) : '—',
			'customer'     => $customer ? "{$customer->user_email} (#{$customer_id})" : "(#{$customer_id})",
		);
	}
}
