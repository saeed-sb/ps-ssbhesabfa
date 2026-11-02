# ssbhesabfa 2.3.46

Invoice notifications can stop ordered price and inventory synchronization when
Hesabfa returns a null ItemCode with a valid code under the same line's Item.Code,
or when a queued invoice creation/update is processed after that invoice was deleted.

## Changes

- Resolve missing, null or blank direct item codes from the same invoice line's
  embedded Item.Code. Preserve explicit codes and leading zeroes.
- Accept an empty successful invoice lookup only when the journal proves a later
  deletion for the same internal invoice ID and exact invoice action family.
- Refresh current stock for cumulative historical item codes and current event
  codes. Retain durable snapshots and advance the checkpoint in event order.
- Omit invoice and loyalty callbacks for an invoice proved deleted before replay.
  Process its later deletion separately. Unproven, malformed and mismatched
  responses continue to block the affected change.
- Retain the module-owned CLI scheduler and shared bounded queue runner from main.
- Include the stock-history and stock-only regression suites in release validation.

## Upgrade

Upgrade in place without uninstalling. The 2.3.45 and 2.3.46 upgrade handlers
change code only; they preserve settings, mappings, the webhook journal and its
checkpoint. Normal pending retries resume through the existing scheduler.
These upgrades do not reset the checkpoint, replay completed financial work,
or register accounting documents.

Keep the existing once-per-minute CLI schedule pointing to
modules/ssbhesabfa/bin/cron.php. Preserve the private runtime directory under ops.
No production installation is performed by this release publication.

## Validation

The release workflow lints all tracked PHP files and runs 13 approved standalone
test commands on PHP 8.1 and PHP 8.2, including the 549 stock-history checks,
ordered webhook recovery, stock-only updates and complete item pagination.
The installable ZIP, source inventory, version manifests, SHA-256 companion and
published assets are verified against the exact release commit.
