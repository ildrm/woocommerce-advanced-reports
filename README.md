# WooCommerce Advanced Reports

A self-contained WooCommerce reporting plugin for WordPress with product, order, customer, inventory and financial analytics; Jalali/Gregorian calendars; CSV/XLSX export; print views; saved reports; scheduled email reports; background export jobs; report caching; privacy controls; and HPOS-compatible order access.

## Requirements

- WordPress 6.5+
- WooCommerce 8.2+
- PHP 8.1+
- No Composer runtime dependency
- No `ZipArchive` requirement: XLSX files are created by the bundled minimal ZIP writer
- WooCommerce Action Scheduler is used when available; WordPress Cron is used as a fallback where implemented

## Admin menu

- Reports → Overview
- Reports → Product Reports
- Reports → Order Reports
- Reports → Customer Reports
- Reports → Saved Reports
- Reports → Scheduled Reports
- Reports → Export History
- Reports → Settings

## Product reports

- Product Sales
- Variation Sales
- Best-selling Products
- Worst-selling Products
- Products With No Sales
- Inventory
- Low Stock
- Out of Stock
- Dead / Slow-moving Stock
- Category Sales
- Tag Sales
- Brand Sales (supports common registered brand taxonomies)
- Product Refunds and refund rate

Inventory reports include current stock, stock state, low-stock threshold, backorders, prices, retail inventory value, recognized cost value where available, units sold in the selected period, last sale in the selected period and estimated stock coverage days.

## Order reports

- Sales Summary
- Orders by Date: hourly, daily, weekly, monthly, quarterly or yearly
- Orders by Status
- Order Details
- Payment Methods
- Shipping Methods and Shipping Zones
- Coupons / Discounts
- Taxes by rate and geography
- Refunds based on refund date
- Failed & Cancelled Orders
- Geographic Sales

### Financial metric definitions

To keep results deterministic:

- **Gross Sales**: line-item subtotal before line discounts, plus shipping, excluding tax.
- **Discounts**: line-item subtotal minus discounted line totals.
- **Order Total**: WooCommerce order total.
- **Refunds**: WooCommerce recorded refund total.
- **Net Collected**: order total minus recorded refunds.
- **AOV**: Net Collected divided by qualifying order count.

Totals are never silently combined across different currencies. Select a currency to obtain single-currency KPIs; otherwise the dashboard/report returns a currency breakdown.

## Customer reports

- Customer List
- Top Customers
- New vs Returning Customers
- Customer Lifetime Value
- Purchase Frequency
- Inactive Customers
- RFM Segmentation
- Customer Cohorts

Registered customers are keyed by WooCommerce/WordPress customer ID. Guest customers are normalized by billing email when available. Privacy settings can display full, partially masked or hidden email/phone data.

## Calendars

The database and WooCommerce remain on canonical WordPress/WooCommerce datetimes. Jalali is strictly an input/presentation calendar:

1. Admin enters a Jalali date range.
2. The plugin converts the boundaries to Gregorian/site-timezone datetimes.
3. WooCommerce is queried using canonical datetimes.
4. Output dates are rendered in the selected calendar.

Supported Jalali format tokens include `Y`, `y`, `m`, `n`, `d`, `j`, `F`, `M`, `H`, `G`, `i`, and `s`. Week aggregation respects the plugin's configured first day of week.

## Filters

Shared filters include:

- Date range and quick presets
- Dynamic WooCommerce order statuses
- Product IDs
- Product categories
- Customer search
- Country
- Payment method
- Shipping method title
- Coupon
- Minimum/maximum order amount
- Guest/registered customer type
- Currency
- Date grouping
- Previous-period comparison for overview/sales summary
- Page size
- Inactive-customer threshold
- Dead-stock window and maximum sold units

Filters are represented in the URL, making report views bookmarkable. The table UI also includes per-report column visibility preferences stored in the browser.

## CSV / XLSX / Print

Every report page provides:

- CSV export, UTF-8 with optional BOM
- Genuine XLSX export
- XLSX generation without the optional PHP `ZipArchive` extension
- Background queued XLSX export for large reports
- Print view using dedicated `@media print` styling
- Store/report header and optional print logo

CSV output protects cells that begin with spreadsheet formula characters (`=`, `+`, `-`, `@`) against CSV formula injection.

## Background exports and export history

The **Queue XLSX** action creates a background job. WooCommerce Action Scheduler is used when available; `wp_schedule_single_event()` is the fallback. Generated exports are tracked in Export History and expire after 30 days. Expired files are cleaned by a daily task.

Files are stored under `wp-content/wcar-private` with randomized filenames, an `index.php` deny response and Apache `.htaccess` denial. Downloads are served through authenticated, nonce-protected admin endpoints rather than public file URLs.

## Saved reports

A report can be saved with its active filters. Saved report filters can be reopened from Reports → Saved Reports.

## Scheduled reports

Any report can be scheduled for email delivery as CSV or XLSX:

- Daily
- Weekly
- Monthly

Schedules may use fixed current dates or rolling periods. Rolling schedules use:

- Daily: yesterday
- Weekly: previous 7 days
- Monthly: previous calendar month

Action Scheduler is used for recurring jobs when available. WordPress Cron performs an hourly due-schedule check as a fallback.

## Performance

- Order access uses `wc_get_orders()` in batches with pagination.
- Report results use transient caching with configurable TTL.
- Expensive aggregate caches are invalidated when relevant order, refund, product or stock events occur.
- UI tables are paginated after aggregation.
- XLSX worksheet XML is written to a temporary file before packaging, avoiding construction of one giant worksheet XML string in PHP memory.
- Background queued exports are available for large report jobs.

## HPOS compatibility

The plugin declares compatibility with WooCommerce `custom_order_tables` and does not query `wp_posts` / `wp_postmeta` for orders. Order and refund retrieval is performed through WooCommerce order APIs, so the same report code works with HPOS and legacy order storage supported by WooCommerce.

## Permissions

Granular capabilities:

- `wcar_reports_view`
- `wcar_reports_products`
- `wcar_reports_orders`
- `wcar_reports_customers`
- `wcar_reports_export`
- `wcar_reports_print`
- `wcar_reports_settings`

Administrators receive all capabilities. Shop Managers receive reporting capabilities except settings on initial activation. Settings contains a role-permission matrix for assigning capabilities to other WordPress roles.

## Extensibility

Reports are registered in `WCAR\Reports\ReportRegistry`. Extensions can modify the registry and result structures via:

- `wcar_register_reports`
- `wcar_report_result`
- `wcar_report_columns`
- `wcar_jalali_month_names`

The browser, print and export surfaces are fed by the same report engine to avoid different calculations for different output formats.

## Installation

1. Upload `woocommerce-advanced-reports.zip` in **WordPress → Plugins → Add New → Upload Plugin**.
2. Activate **WooCommerce Advanced Reports**.
3. Open **Reports → Settings** and configure calendar, privacy, performance and role access.
4. Open **Reports** to begin using the reporting dashboard.

## Cost-of-goods note

WooCommerce stores do not have one universal historical cost field across all installations. Inventory Cost Value is only calculated when the product exposes a recognized cost meta value (`_cogs_total_value`, `_wc_cog_cost`, or `_alg_wc_cog_cost`). The plugin deliberately leaves cost/profit-derived values blank when no trustworthy cost value exists.

## Security

The plugin uses WordPress capabilities and nonces for report actions, sanitizes report inputs, escapes admin output, avoids direct order-table SQL, protects private export paths, validates export ownership, and mitigates CSV formula injection. Customer PII visibility is configurable.

## License

MIT
