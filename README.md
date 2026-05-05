# IP WooCommerce Subscriptions Scheduled Actions Panel

A WordPress plugin by [Inverse Paradox](https://inverseparadox.com) that adds a **Scheduled Actions** meta box to the WooCommerce Subscriptions edit screen, a renewal status column to the subscriptions list table, and WP-CLI commands for finding and fixing missing renewal actions.

## Description

When debugging subscription issues it can be time-consuming to cross-reference the subscription edit screen with the Action Scheduler admin table. This plugin surfaces the most relevant scheduled actions directly in the subscription editor sidebar, below the built-in **Schedule** meta box.

For each tracked hook the panel shows:

- A colour-coded status badge (Pending, Running, Complete, Failed, Cancelled)
- The scheduled date/time in the site's local timezone
- A **Schedule** link to manually create a missing action directly from the edit screen
- A link to the full Action Scheduler table pre-filtered to the current subscription

The subscriptions list table also gains a **Has Action** column indicating at a glance whether a pending renewal payment action exists, along with a filter dropdown to quickly isolate subscriptions that are missing one.

## Tracked Hooks

| Label | Hook |
|---|---|
| Renewal Payment | `woocommerce_scheduled_subscription_payment` |
| Trial End | `woocommerce_scheduled_subscription_trial_end` |
| Expiration | `woocommerce_scheduled_subscription_expiration` |
| End of Prepaid Term | `woocommerce_scheduled_subscription_end_of_prepaid_term` |
| Payment Retry | `woocommerce_scheduled_subscription_payment_retry` |
| Customer Notification | `woocommerce_scheduled_subscription_customer_notification` |
| Trial Expiration Notification | `woocommerce_scheduled_subscription_customer_notification_trial_expiration` |

For each hook, an active (pending/running) action is always prioritised. If none exists, the most recently completed or failed action is shown as historical context.

## WP-CLI Commands

Commands are registered under the `wp wc subscription` namespace.

### `wp wc subscription list-missing-renewal`

Lists all active subscriptions that are expected to have a pending renewal payment action but do not.

```bash
# Output as a table (default)
wp wc subscription list-missing-renewal

# Output subscription IDs only, one per line
wp wc subscription list-missing-renewal --format=ids

# Output as JSON
wp wc subscription list-missing-renewal --format=json

# Show a count only
wp wc subscription list-missing-renewal --format=count

# Customise displayed fields
wp wc subscription list-missing-renewal --fields=id,next_payment
```

**Options**

| Option | Default | Description |
|---|---|---|
| `--format=<format>` | `table` | Output format: `table`, `csv`, `json`, `ids`, `count` |
| `--fields=<fields>` | `id,status,next_payment,customer` | Comma-separated list of fields to display |

---

### `wp wc subscription schedule-renewal`

Schedules a `woocommerce_scheduled_subscription_payment` Action Scheduler entry for one or more subscriptions. Subscriptions that already have a pending action, are in a terminal status, or have an unpaid order awaiting payment are skipped automatically.

```bash
# Schedule a renewal action for a single subscription
wp wc subscription schedule-renewal 123

# Schedule for multiple subscriptions
wp wc subscription schedule-renewal 123 456 789

# Schedule for all subscriptions currently missing a renewal action
wp wc subscription schedule-renewal --all

# Preview what --all would do without making any changes
wp wc subscription schedule-renewal --all --dry-run
```

**Options**

| Option | Description |
|---|---|
| `[<id>...]` | One or more subscription IDs to process |
| `--all` | Process every active subscription missing a renewal action |
| `--dry-run` | Report what would be scheduled without creating any actions |

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- WooCommerce Subscriptions 4.0+
- Action Scheduler 3.0+ (bundled with WooCommerce)
- WP-CLI 2.0+ (optional, required only for CLI commands)

## Installation

1. Upload the `ip-woo-subscriptions-scheduled-actions-panel` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.

No configuration is required. The meta box and list table column appear automatically on the subscription screens. WP-CLI commands are available immediately when WP-CLI is present.

## Compatibility

Supports both the classic WooCommerce Subscriptions CPT editor (`shop_subscription` post type) and the modern **High-Performance Order Storage (HPOS)** order edit screen, including the HPOS subscriptions list table.

## License

GPL-2.0-or-later
