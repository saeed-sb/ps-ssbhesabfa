
## Internal API documentation
A complete standalone guide is included at `docs/internal-api-guide.html`. The Internal API back-office section links directly to this file. It covers inputs, outputs, synchronous and queued examples, status handling, retry-safe request UUID behavior, and the signatures of all allowed wrapper methods.

Version: 2.3.5

# ssbhesabfa
 Connect "Hesabfa Online Accounting" to Prestashop


## Version 2.3.4 retry-safe request IDs and Hesabfa log codes
- Persist UUID v4 request IDs per queued operation and reuse them for automatic retries.
- Allocate separate stable IDs for multiple write calls inside the same job.
- Reset persisted IDs when a genuinely new product/customer change is merged into a job.
- Persist the same retry-safe behavior for queued Internal API requests.
- Populate the Hesabfa code log column from explicit response messages or existing object mappings when a code is available.

## Version 2.3.3 request ID and postal-code updates

- Increased exported customer postal-code length from 9 to 10 digits.
- Changed Hesabfa write request identifiers to fresh random UUID v4 values for each API request.

## Version 2.3.2 reliability fixes

- Fixed product-delete mapping lookup so deleting a product cannot remove order/customer mappings that happen to share the same PrestaShop ID.

## 1.0.9

- Rebuilt the module configuration page with a cleaner grouped admin workflow.
- Added a dedicated Logs / Issues tab for internal module logs.
- Reorganized API, catalog, customer, invoice, payment mapping, accounting text, manual gateway payment, export, and sync/repair workflows.
- Kept price/stock sync separate from item-code repair workflow in the admin interface.


## 2.0.24
- Changed the admin order Hesabfa box to use PrestaShop card-style markup while keeping legacy panel fallback classes.

## 2.0.23

- Added mapping table indexes.
- Added structured log levels and a follow-up issues table.
- Added idempotency tracking for invoice payment and payment fee income document operations.
- Improved financial API response handling.
- Removed Persian text from PHP code paths; Persian translations remain in translation files.


## Version 2.0.27

- Added repository classes for module mappings, operation records, follow-up issues, logs, and selected PrestaShop read operations.
- Moved several direct SQL reads out of the main module class and into repository classes that centralize casting and escaping.
- Kept financial behavior, hook behavior, API behavior, UI tabs, and database schema unchanged for a safer production refactor step.
- Added a no-op upgrade file for version 2.0.27.

## Version 2.0.26

- Phase 1 refactor: extracted shared date formatting, text/template rendering and module logging helpers.
- Kept existing business behavior unchanged to reduce production risk.
- Added a no-op upgrade file for a safe version transition.


## Version 2.1.4

- Moved the Hesabfa request queue out of the Sync / Repair page into its own Request Queue controller and vertical module tab.
- Kept manual single-job execution and pending-job execution in the dedicated queue page.
- Merged the separate Date and Time columns in module logs into one Date / Time column.
- Displayed request queue timestamps in one combined Date / Time column for a cleaner table layout.

## Version 2.1.3
- Added a dedicated Internal API controller/tab for other PrestaShop modules.
- Added an internal API request repository and database table for queued/synchronous API requests.
- Added admin documentation with PHP request examples, queued request examples, and sample responses.
- Added a manual runner for pending/failed internal API requests.
- Added a barcode option to use no barcode source.
- Bank account path for fee income documents is now a module constant again; the legacy configuration key is removed during upgrade.
- Kept the product-combinations initialization fix and the improved log normalization fix.
- Cleaned packaging output from macOS and temporary files.

## Version 2.1.2

- Cleaned generated macOS package files and removed the unused legacy GIF logo from the module package.
- Added a separate time column to module logs, so date and time are both visible.
- Added a manual item code mismatch review table with PrestaShop item codes, current Hesabfa codes and proposed Hesabfa codes.
- Added a safe manual apply action for item code mapping repairs after conflict checks.
- Added a Hesabfa request queue table with manual single-job execution and pending-job execution.


## Version 2.1.0

This release completes the staged refactor plan while keeping risky production changes behind disabled-by-default feature flags.

### Phase 3 - API response standardization
- Added `HesabfaApiResponse` to normalize API responses into a consistent legacy-compatible object shape.
- Added `HesabfaSafeApi` for new call sites that need centralized exception handling and structured logging.
- Updated the low-level Hesabfa API transport so cURL errors, empty responses, invalid JSON and HTTP errors always return a normalized object instead of an ambiguous raw failure.

### Phase 4 - Financial safety and idempotency hardening
- Preserved operation keys and operation repository behavior for invoice payments and payment fee income documents.
- Added better normalized error handling so financial paths can write actionable issues when a dependent document fails.

### Phase 5 - Follow-up issue workflow
- Added admin actions to mark issues as resolved or mark them for retry from the Logs / Issues page.
- Kept issue status changes simple and auditable without automatically repeating financial operations from the UI.

### Phase 6 - Optional async jobs behind feature flags
- Added `ssb_hesabfa_job` table and `HesabfaJobRepository`.
- Added disabled-by-default settings for queuing order/payment sync and product sync from hooks.
- Existing synchronous production behavior remains the default.

### Phase 7 - Stock-safe webhook layer
- Added `HesabfaStockService` and routed webhook stock writes through PrestaShop stock APIs.
- Direct stock database writes remain avoided.

## Version 2.1.5

- Moved the internal API request queue into the dedicated Request Queue tab, while keeping the Internal API tab focused on documentation and examples.
- Added a dismiss action for item code mismatches so admins can intentionally ignore a mismatch without changing the mapping.
- Added the Hesabfa item name to the item code mismatch review table.
- Forced log rows to display date and time together in a single column.
- Added PrestaShop code and Hesabfa code columns to the module logs table.




## Version 2.1.9

- Added idempotency tracking to invoice save operations, not only payment operations. Duplicate invoice-save payloads are skipped after a successful operation, while failed attempts create actionable issues for review.
- Financial operation success now automatically resolves unresolved issues tied to the same operation key.
- Hardened queue execution with maximum attempts, stale running-job recovery and guarded job claiming.
- Added support for queued execution through `callInternalApi()` using the `queue` option while keeping existing direct calls compatible.
- Added a centralized product price update service used by webhook item sync so price writes are routed through one module service instead of scattered raw updates.
- Added default `SSBHESABFA_JOB_MAX_ATTEMPTS` configuration during install/upgrade.

## Version 2.1.8

- Refactored the main module file into a lighter module shell by moving large method groups into dedicated compatibility traits under `classes/traits/`.
- Moved admin rendering/forms, internal API UI/helpers, queue UI/helpers, repair/mismatch UI, payment logic, sync/export logic, and job/admin-order helpers out of `ssbhesabfa.php`.
- Kept public wrappers and hook entry points in the main module class to avoid breaking production behavior.
- Preserved legacy direct usage of `classes/HesabfaAPI.php` and made `inquiryNationalIdentity()` signature backward-compatible with older calls.
- No database schema change in this release.

## Version 2.1.7
- Preserved backward-compatible Internal API access for external modules through `callInternalApi()` and `enqueueInternalApiRequest()`.
- Expanded the Internal API guide with method inputs and example calls for each allowed method.
- Added padding to the log filter form for a cleaner admin layout.

## Version 2.1.6

- Added pagination to the module log table.
- Added PrestaShop code and Hesabfa code filters to logs.
- Improved log context labels so object numbers are shown in dedicated code columns instead of the context column.
- Added the queue cron URL above the Hesabfa request queue list.
- Added idempotency/dedupe for `sync_product` jobs so repeated product hooks merge into an existing pending or near-current job.
- Added a setting to enable or disable the Internal API request queue. When disabled, queued helper calls execute immediately and the Internal API queue list is hidden.
- Added a visible list of allowed Internal API methods to the Internal API guide.
- Improved RTL support for the module admin interface.

## 2.2.0

- Reduced the main `ssbhesabfa.php` file to a lightweight module shell.
- Moved module lifecycle data/methods, admin routing/support wrappers, customer hooks, order hooks, and product hooks into dedicated trait files.
- Kept `HesabfaAPI.php` as the backward-compatible public API class for other modules.
- No financial, sync, webhook, queue, or Internal API behavior was intentionally changed in this refactor.

## 2.2.1
- Restored module lifecycle methods, configuration defaults, tab definitions, and hook entry points to `ssbhesabfa.php`.
- Kept heavy business logic in traits/services so the main file stays readable while exposing the important PrestaShop entry points directly.
- No financial, sync, webhook, queue, or Internal API behavior changes.

### Version 2.2.2
- Fixed trait-relative include paths for HesabfaWebhook.php after the main module refactor.
- No business logic changes.

### Version 2.2.3
- Standardized module-relative includes to use `_PS_MODULE_DIR_ . 'ssbhesabfa/...'` where PrestaShop is already bootstrapped.
- Kept front controller bootstrap includes unchanged where PrestaShop constants are not available yet.
- Added Webhook context to webhook configuration success/failure logs.
- Added a no-op upgrade file for version 2.2.3.


### Version 2.2.4
- Restored missing static compatibility wrappers after the shell refactor.
- Audited every `Ssbhesabfa::...()` call and added wrappers for mapping and currency conversion helpers used by webhook/API classes.
- Fixed webhook fatal errors caused by moved methods such as `getObjectId()`.

## Version 2.2.6

- Added confirmation prompts before running completed queue records again.
- Area filter in Logs is now a select list.
- Logs table uses horizontal scrolling when debug columns are visible.
- Internal API request queue also asks for confirmation before re-running completed requests.

## Version 2.2.5

- Debug mode remains a hidden database-only setting (`SSBHESABFA_DEBUG_MODE`). No `DEBUG_UNTIL` setting is used.
- Module logs now keep the existing context/object type and add a separate `area` field for the subsystem that produced the log (API, Webhook, Queue, Payment, Sync, Repair, etc.).
- The logs table stores dedicated PrestaShop and Hesabfa codes instead of extracting them only from message text.
- When debug mode is enabled from the database, the same Logs tab shows extra debug columns: endpoint, HTTP code, duration, and collapsible payload/request/response data.
- Debug data is masked and truncated before it is saved.


## Version 2.2.8
- Product and customer exports are processed by AJAX in batches of 100.
- A progress bar shows the transfer status and the next batch continues even when a batch reports an error.


## 2.2.8
- Masked sensitive Hesabfa API debug payloads.
- Raised minimum supported PrestaShop version to 1.7.0.0.
- Replaced legacy webhook token with stored random token.
- Renamed invoice return status configuration key from STATUE to STATUS with migration.
- Moved admin CSS and JavaScript to external asset files.
- Removed unused duplicate trait files.
- Improved product price refresh by clearing PrestaShop price/static cache after price updates.
- Debug mode remains database-controlled only.

## Version 2.2.14

- Replaced direct SQL price writes with PrestaShop ObjectModel updates.
- Base product prices now use `Product::update()`.
- Combination price impacts now use `Combination::update()`.
- Stock quantities continue to use `StockAvailable::setQuantity()` with explicit shop resolution.
- Added an inbound-sync guard to prevent price changes received from Hesabfa from being sent back to Hesabfa by product update hooks.
- Hardened combination hook parameter handling for legacy and modern product edit flows.


## Version 2.3.1 reliability fixes

- Fixed queue merge lookups on PrestaShop 8 by letting `Db::getRow()` apply its own row limit.
- Fixed product update hooks to queue sync jobs when PrestaShop passes a loaded product object.
- Added a fallback for admin form language metadata in non-standard render contexts.

## Version 2.3.0 reliability changes
Queue retries now use delayed backoff and dead-letter handling. Product/customer exports resume from a persistent cursor, and webhook checkpoints only advance after each individual change succeeds. Hesabfa API traffic is limited locally to a safe default of 200 requests per minute.

## Version 2.3.5 error-aware retries
- Retryable transport and temporary failures reuse the same request UUID.
- Validation and business errors move to `needs_attention` and are not retried automatically.
- Duplicate request error 120 and expired 24-hour request IDs move to `duplicate_check`.
- Starting a new operation clears persisted UUIDs and generates new UUID v4 values.
- Queue tables display the API error code, request UUID, and payload hash.


## Queue and connection behavior (2.3.7)
When asynchronous synchronization is enabled, hooks record jobs while API credentials are configured even if the current connection status is offline. The queue checks the connection immediately before execution. Offline jobs remain in `retry_wait` and do not consume an attempt. Synchronous operations still require an active connection. Completed jobs are historical records and cannot be run again from the queue.

## Product edit mappings
The product edit panel accepts a positive Hesabfa item code for the base product and each combination. Persian and Arabic digits are normalized. Clearing a field removes only the local mapping. Duplicate codes assigned to another product or combination are rejected and displayed in the product panel. Product and combination deletions are queued; mappings are deleted only after Hesabfa confirms the remote deletion.

Clearing a product mapping skips automatic product sync for that save request so the mapping is not immediately recreated. A later product synchronization can create a new Hesabfa item when no mapping exists.

## Fiscal-year invoice mapping policy (2.3.8)
Hesabfa invoice numbers can restart from 1 in each fiscal year. Therefore `order` and `returnOrder` mappings do not enforce global uniqueness on the Hesabfa invoice number. The unique local object mapping `(obj_type, id_ps, id_ps_attribute)` remains enforced, while `(obj_type, id_hesabfa)` is a non-unique lookup index. Customer and product Hesabfa codes continue to be checked for conflicts.

The 2.3.8 upgrade also repairs required queue metadata columns when prior upgrades were skipped and reports invoice mapping persistence failures instead of logging a false success.


## Upgrade path from version 2.3.10
Historical migrations remain consolidated only up to these points:

- `upgrade-1.9.9.php`: consolidated 1.x migration.
- `upgrade-2.3.9.php`: consolidated 2.x migration through version 2.3.9.
- `upgrade-2.3.10.php`: separate version-specific upgrade handler for 2.3.10.

An installation upgrading from 1.x runs `1.9.9`, then `2.3.9`, then `2.3.10`. An installation on a 2.x release before 2.3.9 runs `2.3.9` and then `2.3.10`. Future releases keep their own separate upgrade file whenever a database or configuration migration is required; the consolidated 2.3.9 migration must not be renamed or rewritten for later versions.

The consolidated 2.3.9 migration is idempotent and self-healing. It creates missing tables, adds missing final-schema columns through 2.3.9, repairs mapping and queue indexes, preserves repeated fiscal-year invoice numbers, backfills queue UUID metadata, restores required hooks and admin tabs, and adds missing configuration defaults without overwriting existing merchant settings.


## Payment and fee-document failure handling (2.3.10)
The default bank account path used in fee-income accounting documents is:

`دارایی ها : دارایی های جاری : موجودی نقد و بانک : بانک`

When the main invoice payment or its fee-income accounting document fails, `setOrderPayment()` now returns `false`. The queue receives the original Hesabfa response when available and classifies the job according to the retry policy. Missing local configuration is reported as a follow-up issue and is moved to attention instead of being silently treated as successful.

## Upgrade file policy

The consolidated migration ends at version `2.3.9`. Version `2.3.10` has its own upgrade handler. Future versions use separate upgrade files whenever a database or configuration migration is required; previous consolidated migrations must not be renamed or rewritten for a new release.
## Accounting template placeholders (2.3.11)

The payment-description and accounting-document templates support:

- `{order_id}`: PrestaShop numeric order ID.
- `{order_reference}`: PrestaShop order reference.
- `{invoice_number}`: Hesabfa invoice number.
- `{transaction_number}`: payment/gateway transaction number.

For automatic order payments, `{order_reference}` is read from `Order::reference`. For manual gateway payments, the Manual Payment form includes an optional order-reference field. If it is left empty, `{order_reference}` is rendered as an empty value.

Example:

```text
Payment for order {order_reference} - Hesabfa invoice {invoice_number} - transaction {transaction_number}
```



## Invoice webhook items (2.3.12)

When an invoice change contains a Hesabfa item without an online-store `Tag`, the item is treated as an accounting-only line and is skipped. The invoice change is then marked as done and the webhook checkpoint can advance.

Items that claim to be linked through `Tag` still fail processing when their PrestaShop mapping, product, or combination is missing. This distinction prevents unrelated accounting items from blocking the queue while preserving errors for genuinely broken store relations.


## Persian translations (2.3.14)
All current strings wrapped by the module translation system in PHP and Smarty templates have matching entries in `translations/fa.php`. This includes admin pages, queue actions, payment and fee settings, product mappings, order registration messages, logs and AJAX export progress messages.

## Webhook store-tag filtering (2.3.14)

Webhook changes for invoice items, products and contacts are applied only when the Hesabfa object contains a valid store tag (`id_product` or `id_customer`). Objects that are not connected to the store are ignored so they do not block the checkpoint.



## Item code mismatch guard (2.3.15)

When a Hesabfa item Tag points to a PrestaShop product or combination whose saved mapping contains a different Hesabfa item code, the module logs the mismatch and skips price/stock application for that item. The mapping is not changed automatically, and the webhook change is allowed to continue so later changes are not blocked. Use the manual mismatch review before changing the mapping.


## Queue filters and compact pagination (2.3.16)

- Added SQL-backed filters and pagination to the Hesabfa request queue.
- Added SQL-backed filters and pagination to the Internal API request queue.
- Queue filters cover status, operation type/method, object context, error code, date range, and free-text payload/error search.
- Each queue page contains up to 50 rows.
- Log pagination now uses a compact first/current/last page window instead of rendering every page number.


## Automatic retries and manual dead action (2.3.17)

Retryable failures are moved to `retry_wait` and are selected automatically by cron when `next_run_at` is reached and the maximum attempt count has not been reached. Records in `needs_attention`, `duplicate_check`, or `dead` are not retried automatically. Administrators can now mark unwanted pending or blocked records as `dead` from both queue tables. Running and done records are protected from this action.


### Queue tabs and pagination

The Queue controller displays the Hesabfa request queue and the Internal API request queue in separate tabs. Each tab keeps its own filters and page number. Pagination is compact and uses a stable left-to-right visual order, including in RTL admin pages.



## Queue alert excludes manually closed dead jobs (2.3.20)

The global queue alert now treats only `needs_attention` and `duplicate_check` as manual-attention statuses. Records marked as `dead` remain available in queue history and filters, but they no longer keep the global `Queue attention required` warning active. Jobs in `retry_wait` still produce the retry warning.

## Queue alerts and corrected log statistics (2.3.19)

The module displays a global back-office alert when either the main queue or the Internal API queue contains jobs waiting for retry or requiring manual attention. Manual-attention statuses include only `needs_attention` and `duplicate_check`. Records marked as `dead` remain visible in queue history but do not keep the global attention alert active. The log summary now counts only severity 3 and 4 as errors; severity 2 is counted only as a warning.
