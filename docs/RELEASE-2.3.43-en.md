# Hesabfa 2.3.43

Delivery Manager now prepares, corrects and tracks shipping expense documents and submits the complete document through `callInternalApi('documentSave', array($document))`. Hesabfa provides generic accounting transport, a caller-persisted UUID for one synchronous write, and an opaque business/year fingerprint. The accounting connector no longer scans shipment outboxes or sends invoice-saved notifications to Delivery Manager.

Sales invoice freight for managed carriers stays unassigned to a carrier contact. Shipping contact and expense-account settings are managed in Delivery Manager. Compatibility entry points delegate shipping operations to that module. Old Hesabfa shipping upgrade steps are inert; Delivery Manager owns creation and migration of its ledger tables.

This release also includes the previously merged configurable gateway-fee expense support from 2.3.42, preserving gateway receipt reconciliation and duplicate-request safeguards.

## Upgrade

Use this release together with Delivery Manager 1.8.0; upgrade Delivery Manager first. The Hesabfa 2.3.43 upgrade changes runtime code only. It does not enable accounting, change credentials/settings, replay payments or invoices, send notifications or post historical expenses. Delivery Manager's worker adopts its existing shipment queue after both modules support the generic API. Existing document mappings and persisted request UUIDs remain intact.

The standalone release tests cover legacy upgrade isolation, queue guards, pagination, payment reconciliation, gateway fees, security and request IDs on PHP 8.1 and 8.2. Shipping business tests have moved to Delivery Manager and are validated as paired-module tests; they are no longer run as Hesabfa-only fixtures. Production accounting changes were checked with existing financial state and queue history unchanged.
