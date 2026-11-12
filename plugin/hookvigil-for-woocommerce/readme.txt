=== Hookvigil for WooCommerce ===
Contributors: uniano
Tags: woocommerce, webhooks, monitoring, alerts, integrations
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Get an email, Telegram or Slack alert when WooCommerce disables a webhook, and see which orders never reached your ERP or CRM.

== Description ==

WooCommerce sends orders to other systems — ERPs, CRMs, accounting, fulfilment, Zapier, Make, n8n — through webhooks. When the receiving system fails several times in a row (a timeout, a deploy, an expired certificate), **WooCommerce silently disables the webhook**. It sends no notice and never retries. Orders keep coming in, but they stop reaching the other system until someone notices days later.

Hookvigil tells you within minutes and shows you what to fix.

= What it does =

* **Alerts when a webhook is disabled** — a clear email, Telegram or Slack message with the webhook, receiving host, readable last error, time in your time zone and the number of orders missed so far. One alert per problem, a reminder every 24 hours while it stays broken, and a notice when it recovers.
* **Lists the orders that were missed** — for order webhooks, every order created or updated while the webhook was disabled, with links and a CSV download, so you can push them again.
* **Re-enable and reset in one click** — WooCommerce keeps the old failure counter when you re-activate a webhook, so a single new error disables it again. "Re-enable and reset" clears it.
* **Webhook health at a glance** — status, last successful delivery, failure streak, 24-hour volume and last error for every webhook, on desktop and phone.
* **Stuck queue warning** — if the background queue stops (for example when WP-Cron is not running), deliveries wait silently. You get an alert when they are overdue.

It works with the webhooks you already have, including the ones created by connectors. Nothing to reconfigure, no delivery URLs change, no third-party monitoring service.

= What it does not do =

Hookvigil detects problems, alerts you and lists missed orders. It does not re-send deliveries automatically and cannot guarantee that the receiving system processes them.

= Compatibility =

WordPress 6.5+, WooCommerce 9.0+, PHP 7.4+. High-Performance Order Storage (HPOS) and the Cart and Checkout blocks are supported.

= Privacy =

The plugin stores delivery metadata only (webhook ID, topic, resource ID, HTTP status or error code, duration and time) for 7 days by default. Request and response bodies are never stored. The plugin makes no outgoing requests except the alerts you configure: email through your site's mail setup, the Telegram Bot API (api.telegram.org) and your Slack incoming webhook. No tracking, no telemetry.

== External services ==

Hookvigil sends alerts only through the channels you turn on in **WooCommerce → Hookvigil → Alerts & settings**. Nothing is sent until you enable a channel and enter its details.

**Telegram Bot API** (api.telegram.org, provided by Telegram Messenger Inc.)
Used to deliver alerts to your Telegram chat through your own bot. When an alert is sent (a webhook is disabled, still disabled after the reminder interval, active again, webhook deliveries are overdue, or you click "Send test alert"), the plugin sends your bot token (in the request URL), your chat ID and the alert text. The alert text contains your store name, the webhook name and topic, the receiving host name, the last error, the time, the number of missed orders and a link to this plugin's admin page on your site. No customer or order details are sent.
Terms: https://telegram.org/tos/bot-developers — Privacy policy: https://telegram.org/privacy

**Slack incoming webhooks** (hooks.slack.com or the webhook URL you enter, provided by Slack Technologies, LLC)
Used to deliver alerts to a Slack channel. On the same events as above, the plugin sends the same alert text to the incoming webhook URL you entered.
Terms: https://slack.com/terms-of-service/api — Privacy policy: https://slack.com/trust/privacy/privacy-policy

Email alerts are sent with WordPress's own mail function through your site's mail setup; the plugin itself does not contact an email service.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to **WooCommerce → Hookvigil → Alerts & settings**. Email alerts go to the site admin address by default.
3. Optionally add a Telegram bot token and chat ID, or a Slack incoming webhook URL. The settings screen tells you straight away if an address, token or URL is not valid.
4. Click **Send test alert** to confirm the alerts reach you.

== Frequently Asked Questions ==

= Why does WooCommerce disable webhooks? =

After more than five consecutive failed deliveries (in our tests with WooCommerce 11.2, on the 7th failure), WooCommerce sets the webhook status to "Disabled". Any response outside HTTP 200–302 counts as a failure, as do timeouts and DNS errors.

= Does it work with webhooks created by other plugins and connectors? =

Yes. It watches every WooCommerce webhook on the site, whoever created it.

= Will it slow down my store? =

No work is added to checkout or order creation. The plugin writes one small database row per webhook delivery; alerts and checks run in the background.

= Does it re-send the missed orders? =

Not automatically. It lists the affected orders and lets you export them, so you can push them again from the receiving system or by updating the orders.

= Is it compatible with HPOS and the block checkout? =

Yes. Orders are read only through WooCommerce APIs, and compatibility with High-Performance Order Storage and the Cart and Checkout blocks is declared.

= What happens if WP-Cron does not run on my site? =

Background checks then run when an administrator opens the WordPress admin, and the plugin warns you that scheduled tasks are not running — which also delays webhook deliveries.

== Screenshots ==

1. Every webhook's health at a glance.
2. Orders missed while a webhook was disabled.
3. Email, Telegram and Slack alert settings.
4. The email alert for a disabled webhook.

== Upgrade Notice ==

= 0.3.0 =
New name and a quieter admin notice.

== Changelog ==

= 0.3.0 =
* Renamed to Hookvigil for WooCommerce.
* The disabled-webhook notice now appears only on WooCommerce screens and can be hidden for 24 hours.
* Readme: documented the Telegram and Slack services the alerts use.

= 0.2.0 =
* New: formatted alerts. HTML email with a plain-text version, Telegram and Slack messages with bold titles and a link to the webhook page.
* New: alerts show a readable last error (for example "HTTP 503 Service Unavailable") and how many orders were missed so far.
* New: times on screen, in alerts and in the CSV use the site's date format and time zone.
* New: the Health screen has a summary, status labels and a layout that works on phones.
* Fix: invalid email addresses, Telegram tokens, chat IDs and Slack URLs are reported on save instead of being dropped silently; a working value is never replaced by an invalid one.

= 0.1.0 =
* First release: disable alerts (email, Telegram, Slack), missed orders list with CSV, re-enable and reset, webhook health screen, overdue delivery queue alerts.
