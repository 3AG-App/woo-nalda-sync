=== WooCommerce Nalda Marketplace Sync ===
Contributors: 3ag
Tags: woocommerce, nalda, marketplace, sync, orders, products, switzerland
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 8.5
Stable tag: 1.0.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products and orders with Nalda.com marketplace. Export products, import orders, and update order statuses.

== Description ==

WooCommerce Nalda Marketplace Sync is a premium WordPress plugin that integrates your WooCommerce store with [Nalda.com](https://nalda.com), Switzerland's leading marketplace for local products.

**Key Features:**

* **Product Export** - Export your WooCommerce products to Nalda marketplace via CSV/SFTP
* **Order Import** - Automatically import orders from Nalda into WooCommerce
* **Order Status Export** - Keep Nalda updated with your order fulfillment status
* **Payout Tracking** - Track Nalda payout status for each imported order
* **Delivery Notes** - Generate PDF delivery notes for Nalda orders
* **Scheduled Sync** - Automate imports and exports on configurable schedules
* **Upload History** - View complete history of CSV uploads to Nalda SFTP
* **Detailed Logs** - Complete history of all sync operations with statistics
* **Modern UI** - Clean, intuitive admin interface with real-time feedback
* **Multi-language** - Supports English and German
* **HPOS Compatible** - Works with WooCommerce High-Performance Order Storage

**How It Works:**

1. **Products → Nalda**: Export your product catalog as CSV to Nalda's SFTP server
2. **Orders ← Nalda**: Import customer orders from Nalda API into WooCommerce
3. **Status → Nalda**: Send order delivery status updates back to Nalda

**Requirements:**

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* Valid license key from 3AG
* Nalda Seller API key
* Nalda SFTP credentials

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/woo-nalda-sync`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Nalda Sync → License to activate your license key
4. Configure your Nalda API key and SFTP credentials in Settings
5. Map your product fields and enable scheduled sync from the Dashboard

== Frequently Asked Questions ==

= What is Nalda? =

Nalda is a Swiss marketplace that connects local sellers with customers looking for regional products. Learn more at https://nalda.com

= How do I get a license key? =

Purchase a license at https://3ag.app/products/woo-nalda-sync

= How do I get Nalda API credentials? =

Sign up as a seller on Nalda.com and request API access from your seller dashboard.

= Why are orders billed to "Nalda AG" instead of the customer? =

Nalda is your legal customer for tax/invoicing purposes. The end customer's details are stored in the shipping address and order metadata. Nalda handles all customer communication.

= Can I customize product field mappings? =

Yes, you can map WooCommerce product fields to Nalda CSV columns in the Settings page.

= What order statuses are synced to Nalda? =

The plugin exports: IN_PREPARATION, IN_DELIVERY, READY_TO_COLLECT, DELIVERED, CANCELLED, and RETURNED statuses.

== Screenshots ==

1. Dashboard with sync controls and statistics
2. Settings page for API and SFTP configuration
3. Product field mapping configuration
4. Order import with Nalda metadata displayed
5. CSV upload history
6. Detailed sync logs

== Changelog ==

= 1.0.6 =
* Fixed: Nalda billing address corrected (Grabenstrasse 15a, 6340 Baar)
* Fixed: Division by zero when item quantity is 0
* Fixed: Duplicate _nalda_state meta assignment removed
* Fixed: Email notification only triggered for processing orders
* Added: VAT number stored in order billing meta (_billing_vat_number)
* Added: Order currency set from API response
* Added: _nalda_customer_price and _nalda_net_price stored per item

= 1.0.5 =
* Added: Orders are marked as paid only when Nalda payout status is "paid_out"
* Added: Payment method set to "Nalda Marketplace" when payout received
* Added: Payment status updates when payout status changes during sync
* Fixed: Orders now correctly show as unpaid until Nalda sends payout

= 1.0.4 =
* Fixed: "New Order" admin email now shows correct order total instead of 0
* Fixed: Emails are disabled during order creation, then triggered after totals are calculated
* Improved: Order status is now set after items and totals are saved

= 1.0.3 =
* Added: Payout status updates for existing orders during import
* Added: "Updated" counter in import statistics
* Added: Order notes when payout status changes
* Improved: Import now tracks imported/updated/skipped separately

= 1.0.2 =
* Added: CSV Upload History page with filtering and pagination
* Added: Delivery note PDF generation for Nalda orders
* Added: End customer email display in order metabox
* Fixed: Delivery note logo saving
* Improved: History page follows logs page design pattern

= 1.0.2 =
* Added: CSV Upload History page with filtering and pagination
* Added: Delivery note PDF generation for Nalda orders
* Added: End customer email display in order metabox
* Fixed: Delivery note logo saving
* Improved: History page follows logs page design pattern

= 1.0.1 =
* Added: Order status export to Nalda via SFTP
* Added: Scheduled order import and status export
* Added: SFTP credential validation
* Fixed: Customer email suppression for Nalda orders
* Improved: Order metadata display in admin

= 1.0.0 =
* Initial release
* Product export to Nalda SFTP
* Order import from Nalda API
* Custom product field mapping
* License management via 3AG API
* Comprehensive logging system
* Modern admin interface
* HPOS compatibility
* German translation included

== Upgrade Notice ==

= 1.0.5 =
Orders are now correctly marked as paid only when Nalda payout status is "paid_out".

= 1.0.4 =
Fixed "New Order" admin email showing 0 amount. Emails are now sent after order totals are calculated.

= 1.0.3 =
Orders already imported will now have their payout status updated when syncing.

= 1.0.0 =
Initial release of WooCommerce Nalda Marketplace Sync.
