# IP WooCommerce Subscriptions Scheduled Actions Panel

A WordPress plugin by [Inverse Paradox](https://inverseparadox.com) that adds a **Scheduled Actions** meta box to the WooCommerce Subscriptions edit screen, giving admins and developers a quick view of all Action Scheduler entries associated with a subscription.

## Description

When debugging subscription issues it can be time-consuming to cross-reference the subscription edit screen with the Action Scheduler admin table. This plugin surfaces the most relevant scheduled actions directly in the subscription editor sidebar, below the built-in **Schedule** meta box.

For each tracked hook the panel shows:

- A colour-coded status badge (Pending, Running, Complete, Failed, Cancelled)
- The scheduled date/time in the site's local timezone
- A link to the full Action Scheduler table pre-filtered to the current subscription

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

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- WooCommerce Subscriptions 4.0+
- Action Scheduler 3.0+ (bundled with WooCommerce)

## Installation

1. Upload the `ip-woo-subscriptions-scheduled-actions-panel` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.

No configuration is required. The meta box appears automatically on the subscription edit screen.

## Compatibility

Supports both the classic WooCommerce Subscriptions CPT editor (`shop_subscription` post type) and the modern **High-Performance Order Storage (HPOS)** order edit screen.

## License

GPL-2.0-or-later
