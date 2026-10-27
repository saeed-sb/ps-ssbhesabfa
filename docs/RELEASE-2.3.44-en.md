# 2.3.44: invoice edits refresh removed items

Invoice notifications can contain only the replacement line codes in Extra.
Previously, purchase invoice 482 replaced eleven products with twenty others;
the event was marked done while the removed products retained their old store
quantities. This release requests current Hesabfa item stock for the union of
historical item codes, the event codes and the live invoice lines.

The webhook journal now stores cumulative invoice_item_codes metadata before
item lookup or stock writes. Retries retain observed codes even if another edit
occurs before the retry. Existing invoices bootstrap from their retained journal
history. Codes keep leading zeroes. Metadata cannot reconstruct old lines that
were never observed or recorded; such historical discrepancies need a targeted
reconciliation.

API-originated invoice notifications refresh inventory and capture history
without financial callbacks or price updates. Sales, purchase and return
deletion events use history without trying to fetch a deleted invoice. All
quantities come from the current Hesabfa response; removed lines are never
unconditionally set to zero.

## Upgrade

Upgrade ssbhesabfa to 2.3.44. The repeatable upgrade adds one nullable journal
column and the invoice-history lookup index. It does not change settings,
rewind the checkpoint, replay completed changes or write accounting data.
Deploy the metadata migration before enabling the new webhook service.
Keep the journal history: it is used to discover previously removed lines.

## Validation

Run with PHP 8.1:

- php tests/invoice_stock_history.php
- php tests/invoice_stock_only.php
- php tests/invoice_item_pagination.php
- php tests/queue_webhook_recovery.php

The database integration test needs a PrestaShop bootstrap and host:

    SSBHESABFA_TEST_HOST=shop.example \
      SSBHESABFA_TEST_BOOTSTRAP=/path/to/config/config.inc.php \
      php tests/invoice_stock_history_mysql.php

It creates a connection-local temporary journal that shadows the real journal.
The upgrade and repository checks run against that temporary table, which is
dropped at the end; no real journal rows or Hesabfa records are changed.
