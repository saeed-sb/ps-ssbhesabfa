## 2.3.31

- Added `{customer_charge_percent}`, `{fee_percent}`, and `{income_percent}` placeholders to automatic and manual fee-income document descriptions.
- Exposed the configured customer-charge and gateway-fee rates plus the calculated net income rate in the payment fee breakdown.
- Updated default fee-income templates and safely migrates unchanged English or Persian templates to include all three rates.
- Expanded back-office help and internal documentation for the new accounting-description placeholders.
- Added a standalone configuration-only upgrade handler; mappings, queues, logs, invoices, and accounting records remain unchanged.

## 2.3.30

- Fixed `psy_paymenthelper` payment resolution so the currently active gateway method takes precedence over historical short-title configuration keys.
- Prevented stale customer-charge percentages from shadowing updated gateway settings and leaving a residual customer or invoice balance.
- Kept direct legacy configuration as a fallback only when no active Payment Helper method matches the order payment title.
- Added a standalone no-schema upgrade handler; existing settings, mappings, queues, logs, and accounting data remain unchanged.

## 2.3.29
- Declared the module as compatible with the official PrestaShop MCP Server.
- Added seven schema-validated MCP tools for secret-free status and mapping inspection, bounded queue and issue reads, controlled synchronization queueing, and explicit single-job execution.
- Reused the existing mapping, queue, retry, rate-limit, and request-UUID services instead of exposing an unrestricted Hesabfa API bridge.
- Added Composer PSR-4 metadata for MCP discovery while keeping the MCP server itself as an external runtime dependency.
- Added a no-schema upgrade handler; existing settings, mappings, queues, logs, and accounting records are unchanged.

## 2.3.28

- Replaced the removed `Tools::jsonEncode()` call with native `json_encode()` in the admin batch-export AJAX response for PrestaShop 8 compatibility.
- Preserved the existing JSON encoding behavior on PHP 7.4 and PHP 8.1.
- Added a standalone no-schema upgrade handler; existing Hesabfa settings, mappings, queues, logs, and accounting data remain unchanged.

## 2.3.27

- Fixed missing `returnOrder` mappings by persisting invoice results through the atomic mapping repository instead of updating a nonexistent ObjectModel row.
- Verified the stored invoice number before an invoice operation or queue job can report success.
- Made completed invoice operations repair a missing local mapping from their saved Hesabfa reference without issuing another financial API request.
- Added an idempotent upgrade repair for missing return-invoice mappings backed by successful historical operations.

## 2.3.26

- Added an optional catalog setting that stores mapped Hesabfa item codes in PrestaShop product and combination references.
- Added immediate reference updates after every successful product mapping write, including manual item-code repairs.
- Added a bulk reference repair when the catalog settings are saved with the option enabled, replacing the need for an hourly MySQL event.
- Kept the Hesabfa mapping as the source of truth when a reference write fails and added a structured repair log instead of returning a misleading mapping failure.
- Batched invoice-webhook item retrieval, skipped deleted remote items safely, and resolved completed payment-mapping issues.
- Added automatic customer-before-address queue recovery and continued retries for transient connection, rate-limit, and dependency failures.
- Preserved request UUID creation timestamps when legacy rows contain zero dates.
- Added a standalone upgrade handler that creates the new setting without overwriting existing references by default.

## 2.3.25

- Prevented empty product-form fields from silently deleting existing Hesabfa item mappings.
- Added cross-process product synchronization locks to serialize concurrent product and combination hooks.
- Added preflight mapping recovery from exact Hesabfa `ProductCode` and JSON `Tag` matches before any request can send `Code=null`.
- Made mapping writes atomic with `INSERT ... ON DUPLICATE KEY UPDATE` and stopped reporting success when local persistence fails.
- Added duplicate-candidate logging while deterministically keeping the oldest matching Hesabfa item as the canonical mapping.
- Added a standalone no-schema upgrade handler for version 2.3.25.

## 2.3.24

- Added cron processing for Hesabfa webhook changes already stored in the local journal, without calling `setting/getChanges` again.
- Added the optional `webhook_limit` cron parameter, defaulting to 20 and capped at 50; setting it to 0 disables webhook-backlog processing for that request.
- Added processed, pending, failed, checkpoint and last-error webhook fields to the cron JSON response.
- Reused the ordered webhook journal processor for both live webhook requests and cron backlog recovery.
- Added a standalone no-schema upgrade handler for version 2.3.24.

## 2.3.23

- Made `ssbpurchaseprocess` optional for invoice notes, returning an empty note when the module is unavailable, disabled, incomplete, or returns invalid feature data.
- Fixed duplicate confirmation dialogs on item-code mapping and other inline admin actions.
- Namespaced and de-duplicated the delegated confirmation handler so loading the admin asset again cannot register a second prompt.
- Removed the duplicate `class` attribute from the item-code mapping approval form.
- Added a standalone no-schema upgrade handler for version 2.3.23.

## 2.3.22

- Fixed automatic payment registration when `psy_paymenthelper` stores a short order payment title such as `تارا` but exposes a longer configured title such as `درگاه پرداخت تارا`.
- Preserved the existing payment-method configuration key so the Hesabfa bank code, fee type, fee payer and related fee settings remain attached to the selected gateway.
- Added lookup-only normalization for the Persian payment-gateway prefix, repeated whitespace and letter case.
- Added a standalone no-schema upgrade handler for version 2.3.22.

## 2.3.21

- Changed webhook synchronization to return structured API, received, processed, failed, error, checkpoint, and remaining-work details.
- Corrected the manual **Sync changes** action so API failures are errors, empty results are informational, partial failures are warnings, and confirmation appears only after all available changes complete.
- Preserved the failing change ID and error for operator diagnosis, and stopped silently accepting journal, item-fetch, completion-state, or checkpoint persistence failures.
- Changed inbound item synchronization to skip Hesabfa items whose tagged PrestaShop product, combination, or local mapping no longer exists, instead of failing the ordered change journal.
- Added Persian translations for the new synchronization outcomes.
- Added a standalone no-schema upgrade handler for version 2.3.21.

## 2.3.20
- Removed `dead` jobs from the global manual-attention alert count.
- Global queue warnings now treat only `needs_attention` and `duplicate_check` as manual-attention states.
- Kept `dead` records available in queue history without keeping the admin alert active.
- Updated README and internal API documentation to match the queue alert policy.

## 2.3.19

- Added a global back-office warning when main queue or internal API jobs are waiting for retry or require manual attention.
- Corrected log statistics so Errors counts only severity 3 and 4, while Warnings counts severity 2.
- Preserved the queue tabs, pagination, and RTL fixes introduced in 2.3.18.

## 2.3.18

- Split the Queue controller into separate tabs for the Hesabfa job queue and the Internal API request queue.
- Isolated each queue tab's filters, pages, and action URLs.
- Fixed compact pagination order in RTL back-office pages by rendering pagination in a stable left-to-right sequence.
- Kept compact page ranges for logs and both request queues.
- Added a standalone no-schema upgrade file for version 2.3.18.

## 2.3.17

- Added manual **Mark as dead** actions to the main Hesabfa job queue and the Internal API request queue.
- Manual dead actions are available only for pending, retry-wait, needs-attention and duplicate-check records.
- Running and completed records cannot be manually marked dead.
- Clarified that retryable failures are automatically retried by cron after their backoff delay, while needs-attention, duplicate-check and dead records require manual action.
- Added a standalone no-schema upgrade file for version 2.3.17.

## 2.3.16

- Added filters and database-backed pagination to the Hesabfa request queue.
- Added filters and database-backed pagination to the Internal API request queue.
- Added independent query parameters so filters and pages of both queue tables are preserved.
- Changed log pagination to a compact first/current/last page layout with ellipses.
- Added Persian translations for the new queue filter interface.
- Added a standalone no-schema upgrade file for version 2.3.16.

## 2.3.15

- Stopped price and stock synchronization when the Hesabfa item code from Tag does not match the existing local mapping.
- Kept the existing mapping unchanged and returned success after logging the mismatch, so the webhook checkpoint can continue without applying data from the wrong Hesabfa item.
- Added a standalone no-schema upgrade file for version 2.3.15.

## 2.3.13

## 2.3.14

- Added store-tag filtering to webhook processing for invoice items, products and contacts.
- Hesabfa objects without a valid PrestaShop tag are ignored instead of blocking the webhook checkpoint.
- Invoice item lookup failures are skipped so unrelated Hesabfa items do not stop later changes.
- Added a standalone no-schema upgrade file for version 2.3.14.
- Confirmed backup files are excluded from the release archive.

- Completed Persian translation coverage for every current translatable PHP and Smarty string.
- Added missing Persian translations for admin dashboards, queues, payment settings, product mapping, order registration and export progress messages.
- Corrected the English cURL configuration error text.
- No database migration is required.

## 2.3.12

- Invoice webhook processing now skips Hesabfa items that have no online-store Tag instead of blocking the entire ordered change queue.
- Direct product webhook changes still fail for unlinked items, preventing broken product relations from being silently ignored.
- Added explicit failures for linked items whose local mapping, product, or combination is missing.
- Hesabfa item fetch failures inside invoice changes are no longer silently ignored.
- No database or configuration migration is required for this code-only release.

## 2.3.11

- Added `{order_reference}` to the available accounting/payment description placeholders.
- Automatic order-payment fee documents now render `{order_reference}` from the PrestaShop order reference.
- Manual gateway payments now include an optional order-reference input used by manual payment and fee-income templates.
- Kept the existing separate-upgrade policy; no `upgrade-2.3.11.php` is needed because this release has no database or configuration migration.

## 2.3.10 - Separate upgrade policy

- Restored `upgrade-2.3.9.php` as the consolidated migration for all changes through version 2.3.9.
- Added a separate `upgrade-2.3.10.php` for version 2.3.10.
- Version 2.3.10 has no database changes, so its upgrade handler intentionally returns success without altering data.
- Future releases must keep their own version-specific upgrade file when a migration is required.

## 2.3.10
- Corrected the default Hesabfa bank account path to `دارایی ها : دارایی های جاری : موجودی نقد و بانک : بانک`.
- `setOrderPayment()` now returns failure when invoice payment or fee-income document registration fails instead of silently completing successfully.
- Preserved the actual Hesabfa/local error response for queue classification and retry/attention handling.
- Added explicit follow-up issues for missing payment mapping, payment-method configuration, bank code, and fee-income account path.
- Kept `upgrade-2.3.9.php` as the final consolidated 2.x migration and added a separate `upgrade-2.3.10.php`.

## 2.3.9
- Consolidated all historical 1.x migrations into `upgrade-1.9.9.php`.
- Consolidated all 2.x migrations through this release into the self-healing `upgrade-2.3.9.php`.
- Reduced the upgrade directory to two executable migration files plus `index.php`.
- The consolidated 2.x migration repairs tables, missing columns, mapping indexes, queue metadata, configuration defaults, hooks, and admin tabs without deleting valid fiscal-year order mappings.
- Preserved retry UUID metadata and backfilled payload hashes and stored API responses when earlier upgrades were skipped.

## 2.3.8
- Allowed repeated Hesabfa invoice numbers for `order` and `returnOrder` mappings across fiscal years while preserving unique PrestaShop object mappings.
- Replaced the fresh-install unique `(obj_type, id_hesabfa)` index with a normal lookup index.
- Added a self-healing upgrade that repairs missing queue columns and normalizes mapping indexes even when earlier migrations were skipped.
- Invoice save/update now fails visibly when the local mapping cannot be persisted and no longer writes a misleading success log.
- Confirmed 10-digit Iranian postal codes, UUID v4 for new operations, persisted UUID reuse for retries, and 5/20-second HTTP connection/total timeouts.


## 2.3.7
- Removed direct rerun actions for completed main-queue and internal-API records.
- Moved the live-connection check from asynchronous hook enqueue time to queue execution time.
- Added a master automatic-sync switch and API-configuration guard.
- Disconnected queued jobs now remain in `retry_wait` without consuming an attempt.
- Added validated product mapping management, duplicate-code detection, mapping removal by clearing the field, Persian/Arabic digit normalization, and visible product-page notices.
- Product and combination deletions now use retryable queue jobs and keep local mappings until Hesabfa confirms deletion.
- Fixed combination row numbering in the product edit panel.
## 2.3.6 - Internal API documentation
- Added a complete standalone internal API guide at `docs/internal-api-guide.html`.
- Added a direct documentation link in the Internal API admin section.
- Documented bridge inputs, synchronous and queued outputs, queue statuses, retry/requestUniqueId behavior, security guidance, and practical examples.
- Added a generated reference table for all allowed `HesabfaApi` methods and their argument signatures.
- Clarified the recommended `SSBHESABFA_LIVE_MODE` policy: queue creation should preserve events during temporary outages, while API execution must verify connectivity.

## 2.3.5
- Added error-aware queue states: `retry_wait`, `needs_attention`, and `duplicate_check`.
- Technical or ambiguous failures keep the same persisted request UUID during automatic retries.
- Explicit validation/business failures stop automatic retries and require a new operation.
- Hesabfa duplicate request error `120` and UUIDs older than 24 hours move to duplicate checking.
- Added payload hashes, request UUID creation timestamps, last API error codes, and queue response diagnostics.
- Running a completed or terminal row again now starts a new logical operation with fresh UUIDs.

# Changelog

## 2.3.4
- Persisted random UUID v4 request IDs per queue/Internal API operation and reused them across retries.
- Assigned independent stable request IDs to separate write calls executed by the same job.
- Reset persisted request IDs when a new event is merged into an existing synchronization job.
- Improved `hesabfa_code` logging by recognizing contact, service, invoice, payment, and receipt codes and falling back to saved object mappings.

## 2.3.3
- Increased exported customer postal-code length from 9 to 10 digits.
- Changed Hesabfa write-request IDs from deterministic UUID v5 values to fresh random UUID v4 values for every request.

## 2.3.2
- Fixed product-delete mapping lookup so only product mappings are considered, preventing order/customer mappings with the same PrestaShop ID from being removed by mistake.

## 2.3.1
- Fixed PrestaShop 8 SQL failures caused by combining `DbQuery::limit(1)` with `Db::getRow()` in the job merge lookup.
- Fixed product update hooks so product-object payloads correctly resolve the product ID before queueing sync jobs.
- Added a fallback language list for admin forms when the form renderer is executed without a populated controller context.

## 2.3.0
- Added resumable 100-record customer/product exports with persistent state; full API batch failures retry the same cursor.
- Added queue backoff, `next_run_at`, stale lock recovery, dead-letter status, and manual requeue.
- Added a shared 200 requests/minute Hesabfa rate limiter.
- Added webhook change journal and per-change checkpointing.
- Added unique mapping constraints with duplicate cleanup during upgrade.
- Moved customer and address hook sync to the queue.
- Extracted HTTP, export, queue, webhook, payment-fee, and queue-rendering responsibilities into services.

## 2.2.14

- Use official PrestaShop ObjectModel lifecycle for product and combination price updates.
- Use explicit shop resolution for product, combination and stock changes.
- Prevent inbound Hesabfa price updates from creating outbound synchronization loops.
- Validate product and combination relationships before stock writes.
- Improve error logging when official PrestaShop write operations fail.

## 2.2.13

- Add AJAX batch export for products and customers with progress display and stored cursors.

## 2.2.12

- Fix `HesabfaAPI.php` syntax and preserve requestUniqueId integration.

## 2.2.11

- Display the admin order card only in `displayAdminOrderSide`.
