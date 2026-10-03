# Security upgrade to 2.3.36

This release addresses the ten findings in the source review of commit `3d324265124220c741c4b3bfcf1ddd3adeaa353f`, checked against release 2.3.34. It does not register or replay payments during upgrade.

Version 2.3.36 also preserves the successful state, external reference and request IDs of an automatic invoice when a later local mapping repair fails. Repeating that repair cannot create another invoice. Clearing a completed operation's IDs is restricted to the manual receipt/document restoration path after external deletion has been verified. This follow-up is covered by real-database regressions; the 2.3.36 upgrade handler rechecks the existing schema and quarantines unfinished ID-less writes, including when upgrading from 2.3.35, without adding further fields.

## Required deployment changes

1. Back up the module and database, pause the scheduler and write-producing hooks during deployment, upload the release ZIP through Module Manager, and run the module upgrade action before resuming writes. The operation table gains persisted request-ID fields. Previously attempted unfinished financial operations without IDs become `needs_attention` and require external reconciliation; completed records remain completed.
2. Replace URL-token cron calls with `X-SSB-Hesabfa-Token` headers. The existing token remains valid as a header. Queue editors can retrieve it from the Request Queue page. Use HTTPS and a scheduler-owned curl config file with mode 600, as described in the README. Query tokens are deliberately rejected, without a compatibility bypass.
3. Run this module only in an installation containing exactly one shop, including inactive shops. Global mappings, queues, and account settings do not support separate accounting tenants within one multishop installation. Admin pages, exports, hooks, outbound API calls, MCP entry points, cron, and webhook processing reject unsupported installations.
4. Financial and queue locks require MySQL 5.7.5+ or MariaDB 10.0.2+, which support multiple named locks on one connection. Older or unknown database versions cannot claim writes. Use PHP 7.4+ for the legacy module; MCP code requires PHP 8.1+.
5. Review employee permissions for each module tab. Viewing a page never grants mutation privileges. Settings/payment configuration, synchronization, and queue execution require **edit**; manual payments require **add**; log clearing and marking jobs dead require **delete**. Issue status actions require Logs **edit**. Admin-order registration additionally requires AdminOrders **edit** and the order token. AJAX exports require Sync **edit**, POST, and the Sync controller token. Permission lookup failures deny access.

## Credentials and existing logs

Stored API keys, passwords, and login tokens are no longer prefilled into configuration forms; leaving them blank preserves the current value. A replacement value updates the secret. To remove a secret (for example, to switch from login-token authentication to email/password), enable its explicit **Clear stored** switch and leave its value blank. A replacement takes precedence over clearing. Cron credentials are shown only to Queue editors.

New debug logs contain bounded structural metadata such as status, error code, result number, duration, and HTTP code. They omit raw request/response bodies and personal details. Historical rows are redacted when displayed; this does not erase old database records, backups, proxy logs, or exported logs.

If debug logging was enabled on an earlier release, rotate credentials that may have been exposed: API/login credentials in Hesabfa, the webhook password/token (then re-register the webhook), and the cron token (then update the scheduler). Remove or sanitize historic logs and retained copies according to your retention policy. Credential rotation is an operator action; this release does not silently invalidate integrations.

## Financial retries and reconciliation

A direct invoice/payment/fee-income operation has one exclusive owner and persists its request IDs before the HTTP write. Retries reuse those IDs, including after a timeout. An inability to persist IDs prevents the request. Changed financial payloads and IDs older than the module's 24-hour retry window require reconciliation. An invoice payload change cannot bypass an earlier unfinished invoice create.

For held operations, inspect Hesabfa receipts/documents and the module's operation/issue records before deciding whether a new logical operation is appropriate. Resolving an issue only updates the issue status; it does not authorize replay of a held financial write. Do not use a new transaction reference to bypass a hold without checking the external result. A verified deleted manual receipt/document can still be restored through explicit form resubmission, preserving the 2.3.34 reconciliation behavior.

Queue workers claim only rows they actually updated, reload state under locks, and hold the same object lock while validating and executing. Enqueue merges only pending, never-attempted rows without UUIDs. Later changes wait behind unfinished predecessors. Stale recovery skips workers still holding a lock. Terminal-job requeue remains an explicit administrator decision to create a new logical attempt.

## Finding disposition and regression coverage

| Finding | Resolution | Checks |
| --- | --- | --- |
| 1. Sibling admin dispatch | Canonical controller section, target/action ACL, fail-closed lookup, CSRF/POST, blank secrets | ACL profiles, forged section/submit, missing tab/employee, lookup failure |
| 2. Cron token in URLs | Header only; URL contains no token; edit-only credential display | Real HTTP query rejection and header success |
| 3. Direct financial concurrency | Order/operation locks; claim checked; persisted UUID; conservative retry holds | Independent PHP processes, failed persistence, identical retry IDs, changed payload, expiry, migration |
| 4. Debug credentials/PII | Metadata allowlist; central redaction; historical view redaction | Mixed-case/nested secrets, raw JSON, credential URLs and ordinary messages |
| 5. Multishop provenance | Explicit single-shop contract with fail-closed rejection | Multiple-shop guards and real HTTP rejection; separate per-shop tenants are unsupported |
| 6. Webhook type juggling | Nonempty string plus `hash_equals` | Boolean, integer, null, array/object, empty and incorrect passwords |
| 7. Unauthenticated log growth | No persistent module log writes before authentication | 1,000 rejected HTTP requests with zero fixture DB writes |
| 8. Queue claim/merge races | Affected-row claim, fresh rows under locks, safe merge and recovery | Two processes, stale snapshots, running enqueue, predecessor hold, live stale worker |
| 9. Disabled cron execution | Lifecycle guard before workers | Valid-token disabled-module HTTP request starts no worker |
| 10. Unbounded webhook bodies | POST and 64 KiB declared/streamed limit; no raw logging | Exact limit, oversize stream/content length, malformed JSON, GET rejection |

The tests use mocked outbound writes and isolated databases. They do not send production payments. Existing payment, webhook recovery, log-level, payment-module, and repository regressions are also retained.

The permission model follows [PrestaShop's tab/action roles](https://devdocs.prestashop-project.org/8/modules/concepts/controllers/admin-controllers/tabs/) and was checked against the installed PrestaShop 8.1.7 core. These regression checks verify the reported paths; they are not a claim that the complete integration has no other vulnerabilities.
