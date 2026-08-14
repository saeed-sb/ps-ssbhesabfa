# SSB Hesabfa for PrestaShop

`ssbhesabfa` connects a PrestaShop store to Hesabfa Online Accounting. It synchronizes store data, registers invoices and payments, processes Hesabfa webhooks, and provides reliable queues for operations that should not block checkout or back-office requests.

- **Current version:** `2.3.29`
- **PrestaShop compatibility:** `1.7.0.0` and newer
- **Author:** Saeed Sattar Beglou

[Latest release](https://github.com/saeed-sb/ps-ssbhesabfa/releases/latest) · [Changelog](CHANGELOG.md) · [Internal API guide](docs/internal-api-guide.html)

## Table of contents

- [What the module does](#what-the-module-does)
- [Feature overview](#feature-overview)
- [Catalog and inventory](#catalog-and-inventory)
- [Customers and addresses](#customers-and-addresses)
- [Orders and invoices](#orders-and-invoices)
- [Payments and transaction fees](#payments-and-transaction-fees)
- [Webhook synchronization](#webhook-synchronization)
- [Reliable queues](#reliable-queues)
- [Logs and administration](#logs-and-administration)
- [Internal API for other modules](#internal-api-for-other-modules)
- [MCP tools for AI applications](#mcp-tools-for-ai-applications)
- [Requirements](#requirements)
- [Installation](#installation)
- [Initial configuration](#initial-configuration)
- [Cron setup](#cron-setup)
- [Upgrading](#upgrading)
- [Security notes](#security-notes)
- [Optional module compatibility](#optional-module-compatibility)
- [Troubleshooting](#troubleshooting)

## What the module does

The module acts as the accounting bridge between PrestaShop and Hesabfa. It can:

- export products, combinations, customers, addresses, orders, and opening quantities to Hesabfa;
- register sales invoices, return invoices, invoice payments, and related fee-income documents;
- receive Hesabfa changes through an authenticated webhook;
- update mapped product prices and stock quantities through PrestaShop APIs;
- run customer, product, order, payment, deletion, and external-module requests through persistent queues;
- keep mappings between PrestaShop objects and Hesabfa codes;
- expose a controlled Hesabfa API bridge to other PrestaShop modules;
- record structured logs, retry details, and follow-up issues in the back office.

## Feature overview

| Area | Capabilities |
| --- | --- |
| API connection | Hesabfa API key, login token or account credentials, connection test, fiscal-year validation, and automatic webhook registration |
| Catalog | Product and combination export, barcode source selection, opening quantity export, item-code mapping, and price/stock synchronization |
| Customers | Customer and address export, automatic hook synchronization, configurable contact group, and address update policy |
| Orders | Sales and return invoices, configurable invoice reference, salesman and project, historical order synchronization, and order-side registration card |
| Payments | Payment-method-to-bank mapping, automatic and manual payments, Shaparak/percentage/fixed fees, merchant/customer fee handling, and fee-income documents |
| Webhooks | Authenticated change endpoint, per-change journal and checkpoint, store-tag filtering, and safe inbound product/contact/invoice processing |
| Reliability | Persistent queues, delayed retries, request UUID reuse, duplicate-request review, rate limiting, stale-lock recovery, and dead-letter history |
| Administration | Dedicated dashboard sections, filtered queues, compact pagination, mapping repair tools, structured logs, issue workflow, and Persian translations |
| Extensibility | Synchronous and queued Internal API calls for other PrestaShop modules |

## Catalog and inventory

### Product and combination export

- Export products and combinations to Hesabfa.
- Process large exports in resumable batches of 100 records.
- Display export progress in the back office.
- Export opening quantities when preparing a new fiscal year.
- Select the PrestaShop value used as the Hesabfa barcode, or disable barcode export.
- Preserve a local mapping between each product or combination and its Hesabfa item code.
- Optionally use the mapped Hesabfa item code as the PrestaShop reference for products and combinations.

### Product edit mappings

The product edit panel includes a Hesabfa item-code field for the base product and every combination.

- Positive item codes are validated before saving.
- Persian and Arabic digits are normalized.
- Duplicate item codes assigned to another product or combination are rejected.
- Leaving a submitted field empty keeps the current mapping unchanged.
- Product and combination deletions keep their mapping until Hesabfa confirms the remote deletion.

When **Use Hesabfa item code as product reference** is enabled in the catalog settings, every successful product mapping write immediately updates the matching `product.reference` or `product_attribute.reference`. Saving the catalog settings also repairs references for all existing mappings, so an external MySQL event is not required. The option is disabled by default because enabling it overwrites existing product and combination references.

### Price and stock updates

Inbound Hesabfa changes can update price and quantity when the related settings are enabled.

- Base product prices are updated through `Product::update()`.
- Combination price impacts are updated through `Combination::update()`.
- Quantities are updated through `StockAvailable::setQuantity()` with shop context.
- An inbound-sync guard prevents a Hesabfa price update from immediately creating an outbound synchronization loop.
- A mismatch guard skips price and stock changes when the incoming Hesabfa item code conflicts with the saved mapping.
- The Sync / Repair page can scan, review, dismiss, or safely apply item-code mismatches.

## Customers and addresses

- Export existing customers to Hesabfa in resumable batches.
- Create or update contacts from customer and address hooks.
- Queue customer/address synchronization by default so account creation is not delayed by an API request.
- Configure the Hesabfa contact group used for online-store customers.
- Choose when customer addresses should be updated in Hesabfa.
- Keep PrestaShop customer IDs mapped to Hesabfa contact codes.
- Process linked contact changes received from the Hesabfa webhook.

## Orders and invoices

- Register a sales invoice when an order is created, immediately or through the queue.
- Register payment information when payment is confirmed.
- Create return invoices when an order enters the configured return status.
- Persist and verify sales and return-invoice mappings before a queue job can be completed.
- Select the invoice reference source used in Hesabfa.
- Assign the online-store salesman and Hesabfa project.
- Synchronize historical orders from a selected date.
- Register an order manually from the Hesabfa card on the PrestaShop order page.
- Preserve invoice mappings across fiscal years, including Hesabfa invoice numbers that restart in a new fiscal year.
- Include supported product notes and serial-number information in invoice descriptions when supplied by compatible modules.

## Payments and transaction fees

Each PrestaShop payment method can be mapped to a Hesabfa bank account. The module only lists bank accounts that use the configured Hesabfa currency.

Supported fee modes:

- no fee;
- Shaparak purchase fee;
- percentage of the payment amount;
- fixed fee amount.

Additional payment controls include:

- matching short order payment titles such as `تارا` to the full configured gateway title without changing the existing bank and fee configuration key;
- merchant-paid or customer-paid fee selection;
- customer extra-charge percentage;
- fee-income account path and optional contact code;
- automatic registration of invoice payments;
- fee-income accounting documents when the customer charge exceeds the transaction fee;
- a manual gateway-payment form with invoice number, order reference, paid amount, transaction number, and payment date;
- conversion from the PrestaShop default currency to the Hesabfa currency;
- idempotency records that prevent the same successful financial operation from being registered twice.

Payment and accounting-document descriptions support these placeholders:

| Placeholder | Value |
| --- | --- |
| `{order_id}` | PrestaShop numeric order ID |
| `{order_reference}` | PrestaShop order reference |
| `{invoice_number}` | Hesabfa invoice number |
| `{transaction_number}` | Payment or gateway transaction number |

## Webhook synchronization

When valid API settings are saved, the module registers its webhook URL and password with Hesabfa.

Webhook processing includes:

- a generated URL token plus a separate webhook password;
- an ordered change journal and per-change checkpoint;
- product, contact, and invoice change handling;
- store-tag filtering so accounting-only objects do not block store synchronization;
- skipping accounting-only invoice lines and tagged Hesabfa items whose local product, combination, or mapping no longer exists;
- mapping validation before inbound price or stock is applied;
- manual synchronization of pending Hesabfa changes from the Sync / Repair page;
- accurate manual-sync feedback for API errors, no-change results, partial failures, processed counts, and the last checkpoint;
- structured webhook logs and optional masked debug data.

The store must be reachable from the internet for Hesabfa to call the webhook. A localhost installation cannot receive remote changes.

## Reliable queues

The module maintains two persistent queues:

1. the main Hesabfa job queue for store synchronization;
2. the Internal API request queue for calls submitted by other modules.

Both queues support filters, separate pagination, manual actions, API error details, payload hashes, and stored request UUIDs.

### Queue statuses

| Status | Meaning |
| --- | --- |
| `pending` | Waiting for its first execution |
| `running` | Claimed by a worker |
| `retry_wait` | Temporary failure; automatically retried after `next_run_at` |
| `needs_attention` | Validation, configuration, or business error requiring review |
| `duplicate_check` | Hesabfa reported a duplicate request or the request UUID must be checked before starting a new operation |
| `done` | Completed successfully and kept as history |
| `dead` | Manually closed or no longer eligible for automatic execution |

### Reliability controls

- Delayed backoff for temporary network, server, and rate-limit errors.
- Configurable maximum attempt count.
- Stale running-job recovery.
- Guarded job claiming to reduce concurrent execution conflicts.
- Deduplication and merging of repeated product/customer events where appropriate.
- Stable UUID v4 request IDs reused during retries of the same logical operation.
- Fresh request IDs when an administrator explicitly starts a new operation.
- Special review state for Hesabfa duplicate request error `120`.
- A local safe API rate limit, configured to 200 requests per minute by default.
- Queue alerts for retrying jobs and records that require manual attention.
- `dead` records remain searchable in history but do not keep the global attention warning active.

Queued jobs may be accepted while Hesabfa is temporarily offline if API credentials are configured. The connection is checked immediately before execution; offline jobs remain in `retry_wait` without consuming an attempt.

## Logs and administration

The module adds dedicated PrestaShop administration sections for:

- API Connection;
- Payment Methods;
- Manual Gateway Payment;
- Sync / Repair;
- Request Queue;
- Internal API;
- Logs / Issues.

The Logs / Issues section provides:

- severity, subsystem, object context, PrestaShop code, and Hesabfa code;
- date, area, and free-text filters;
- compact database-backed pagination;
- error and warning summaries;
- follow-up issues that can be resolved or marked for retry;
- optional debug columns for endpoint, HTTP status, duration, payload, request, and response;
- masking and truncation of sensitive debug values before storage.

The administration interface supports RTL layouts and includes Persian translations for its current translatable PHP and Smarty strings.

## Internal API for other modules

Other PrestaShop modules can use `ssbhesabfa` as a central bridge instead of storing Hesabfa credentials or implementing their own transport and retry logic.

### Synchronous call

```php
$hesabfa = Module::getInstanceByName('ssbhesabfa');

$result = $hesabfa->callInternalApi(
    'invoiceGet',
    array(12345, 0),
    array(
        'queue' => false,
        'requester' => 'my_module',
        'object_type' => 'order',
        'object_id' => 1001,
    )
);
```

### Queued call

```php
$queued = $hesabfa->enqueueInternalApiRequest(
    'invoiceGet',
    array(12345, 0),
    'my_module',
    'order',
    1001
);

if (!empty($queued['success'])) {
    $request = $hesabfa->getInternalApiRequest((int) $queued['request_id']);
}
```

The bridge validates the requested public `HesabfaApi` method, normalizes responses, manages request UUIDs, and can persist execution details. See [the complete Internal API guide](docs/internal-api-guide.html) for method signatures, response contracts, queue behavior, and examples.

## MCP tools for AI applications

Version 2.3.29 declares the module as MCP-compatible and exposes seven focused tools through the official PrestaShop MCP Server:

| Tool | Behavior |
| --- | --- |
| `hesabfa_get_status` | Reads a secret-free connection, queue, mapping, log, and issue summary |
| `hesabfa_get_mapping` | Reads one product, customer, sales-invoice, or return-invoice mapping |
| `hesabfa_list_jobs` | Lists filtered synchronization jobs with bounded pagination |
| `hesabfa_get_job` | Reads one job and its sanitized payload and error details |
| `hesabfa_list_issues` | Lists open, retrying, or resolved follow-up issues |
| `hesabfa_queue_sync` | Validates a PrestaShop object and queues a product, customer, address, order, or payment synchronization |
| `hesabfa_process_job` | Executes one eligible pending/retry job and may create or update data in Hesabfa |

The tools never return Hesabfa API keys, login tokens, passwords, webhook secrets, or cron tokens. They reuse the module's existing validation, mapping, queue, retry, rate-limit, and request-UUID services instead of exposing an unrestricted Hesabfa API proxy.

MCP discovery requires PrestaShop MCP Server and its dependencies. MCP execution requires the PHP 8.1+/PrestaShop version supported by that server; the module's existing non-MCP features remain compatible with older supported PrestaShop installations. After installing or upgrading, clear the PrestaShop MCP Server discovery cache if the new tools are not listed.

## Requirements

- PrestaShop `1.7.0.0` or newer.
- A Hesabfa account with API access enabled.
- A Hesabfa API key and either a login token or account email/password.
- PHP cURL and JSON extensions.
- Database permission to create and update the module tables during installation or upgrade.
- A public HTTPS store URL for webhook synchronization.
- A cron service or scheduler when queued execution is enabled.

## Installation

### From a release package

1. Download the ZIP package from the [latest GitHub release](https://github.com/saeed-sb/ps-ssbhesabfa/releases/latest).
2. In PrestaShop, open **Modules > Module Manager**.
3. Select **Upload a module** and upload the ZIP file without extracting it.
4. Install the module.
5. Open **Shop Parameters > Hesabfa > API Connection**.

### Manual installation

1. Copy the `ssbhesabfa` directory to `modules/ssbhesabfa` in the PrestaShop installation.
2. Confirm that `modules/ssbhesabfa/ssbhesabfa.php` exists.
3. Install the module from **Modules > Module Manager**.

## Initial configuration

Recommended setup order:

1. Enter the Hesabfa API key and either the login token or account credentials.
2. Save the API Connection form. The module tests the connection and registers the webhook.
3. Review the automatic synchronization master switch.
4. Configure queued customer, product, and order/payment processing. Product and order queue modes are marked experimental in the current admin form and should be enabled only after cron is working.
5. Configure the API safe request budget and maximum queue attempts.
6. Select catalog barcode, inbound price, and inbound quantity behavior.
7. Configure the customer group and address update policy.
8. Configure invoice reference, return status, salesman, and project.
9. Map every active PrestaShop payment method to the correct Hesabfa bank and fee policy.
10. Schedule the queue cron URL shown on the Request Queue page.
11. Run the initial customer/product exports and opening quantity only when appropriate for the active fiscal year.

## Cron setup

The Request Queue page displays a signed cron URL containing the generated queue token. Use that exact URL and keep it private.

Example cron entry:

```cron
* * * * * curl -fsS 'COPY_THE_SIGNED_CRON_URL_FROM_THE_MODULE' >/dev/null
```

The endpoint processes the main queue, the Internal API queue when enabled, and pending Hesabfa webhook-journal changes when automatic synchronization is enabled. Its optional `limit` parameter controls the main and Internal API queues and is restricted to a value between 1 and 50; the default is 20.

Webhook backlog processing has a separate optional `webhook_limit` parameter. It defaults to 20, is capped at 50, and can be set to 0 to skip webhook processing for a specific cron request. The JSON response reports the processed and remaining webhook counts, failed total, latest checkpoint, and last error.

Choose a schedule suitable for the store's traffic. Running once per minute is typical for active queues.

## Upgrading

1. Back up the PrestaShop database and the current module directory.
2. Upload the new module package over the existing installation.
3. Use PrestaShop's module upgrade action so the bundled files in `upgrade/` are executed in version order.
4. Do not rename or remove historical upgrade files.
5. Review the module dashboard, queue, and logs after the upgrade.

Keep **Delete module data on uninstall** disabled when uninstalling only for troubleshooting or reinstallation. When that option is enabled, uninstall permanently removes module tables, logs, mappings, queues, issues, and configuration.

Release-by-release technical changes are maintained in [CHANGELOG.md](CHANGELOG.md).

## Security notes

- Never publish the signed webhook or cron URL.
- Use HTTPS for the store and cron requests.
- Do not copy Hesabfa credentials into other modules; use the Internal API bridge.
- Do not log raw API credentials, login tokens, or unmasked financial payloads.
- Treat queue acceptance as confirmation that a request was stored, not that Hesabfa completed it.
- Review `duplicate_check` records in Hesabfa before starting a new operation with a new UUID.
- Keep the uninstall data-deletion option disabled unless permanent removal is intended.

## Optional module compatibility

The current code includes compatibility paths for:

- `psy_paymenthelper` payment-method discovery;
- `Ssbpurchaseprocess` product invoice notes;
- serial-number data supplied in the `ssbserialorder`-compatible order format;
- selected `ssbprofitloyalty` webhook actions.

These modules are optional and are not required for the core Hesabfa integration.

## Project structure

```text
ssbhesabfa/
├── classes/
│   ├── services/     # Queue, export, webhook, payment-fee and mapping services
│   └── traits/       # Admin, sync, payment, job and compatibility behavior
├── controllers/admin # Standard PrestaShop admin controllers
├── docs/             # Internal API documentation
├── sql/              # Install and uninstall schema handlers
├── translations/     # Legacy PrestaShop translations, including Persian
├── upgrade/          # Version-specific upgrade handlers
├── views/            # Admin assets and Smarty templates
├── ssbhesabfa.php    # Main module entry point
├── ssbhesabfa-cron.php
└── ssbhesabfa-webhook.php
```

## Troubleshooting

### The module cannot connect to Hesabfa

- Confirm that PHP cURL is enabled.
- Recheck the API key, login token, email, and password.
- Confirm that the active Hesabfa fiscal year includes the current date.
- Check outbound internet access from the PrestaShop server.
- Review the Logs / Issues section for the Hesabfa error code.

### Webhook changes do not reach the store

- Confirm that the store uses a public URL and is not running on localhost.
- Save the API Connection form again to test the connection and register the webhook.
- Confirm that the webhook URL is reachable through HTTPS.
- Check Webhook-area logs for token, password, or payload errors.

### Queue records are not moving

- Open the signed cron URL manually and inspect its JSON response.
- Confirm that the scheduler calls the current URL and token.
- Check `next_run_at`, attempt count, and the record status.
- Review `needs_attention` and `duplicate_check` records manually; they are not automatic retries.
- Verify the configured maximum attempts and API rate limit.

### Price or stock is not updated

- Enable the related inbound catalog setting.
- Verify the product or combination mapping.
- Run the item-code mismatch scan from Sync / Repair.
- Confirm that the Hesabfa object contains the correct store tag.

### Payments are not registered

- Map the PrestaShop payment method to a Hesabfa bank.
- Verify the fee type, fee payer, income account path, and optional contact code.
- Confirm that the order has a Hesabfa invoice mapping.
- Review Payment-area logs and follow-up issues.

## Releases and support information

- Download stable packages from [GitHub Releases](https://github.com/saeed-sb/ps-ssbhesabfa/releases).
- Read version history in [CHANGELOG.md](CHANGELOG.md).
- Read integration details in [docs/internal-api-guide.html](docs/internal-api-guide.html).
- Report reproducible problems through the repository's [GitHub Issues](https://github.com/saeed-sb/ps-ssbhesabfa/issues).
