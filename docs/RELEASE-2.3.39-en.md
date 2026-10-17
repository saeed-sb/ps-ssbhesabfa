# Hesabfa 2.3.39

- Capture live carrier-person mapping and durable freight-margin accounting with shop-scoped settings, recovery jobs and audits.
- Use verified Payment Hub receipt fee snapshots and preserve completed invoice idempotency when local mappings change.
- Create only the two owned freight-margin tables and register the shipping-cost hook during upgrade; do not replay accounting work.

The release captures the current site code. Invoice freight keeps its billed amount and uses the configured carrier person code. Payment Hub receipt snapshots preserve the verified principal and customer fee; currency or total mismatches require review. The upgrade only creates owned storage and registers the event: it does not enable accounting, replay invoices, process jobs, change settings or credentials, send messages or contact Hesabfa. Existing repository-only code and upgrade history remain. Shipment accounting still requires Delivery Manager source records and reviewed shop mappings.

Validation: changed PHP parses on PHP 8.1 and 8.2; isolated fixtures check repeated installation, exact table definitions, hook registration and failure handling without external financial writes.
