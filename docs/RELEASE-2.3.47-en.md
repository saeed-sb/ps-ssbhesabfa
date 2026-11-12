# ssbhesabfa 2.3.47

Editing an invoice from a previous fiscal year can generate a webhook today.
Hesabfa's default invoice lookup searches the latest fiscal year, so an empty
response previously kept the event in retry and blocked later synchronization.

## Changes

- Identify old-year invoice creation and edit notifications through explicit,
  read-only yearId lookups when no later deletion proves the invoice is gone.
- Validate the invoice ID, number, type and fiscal date before acknowledging it
  as outside synchronization scope.
- Log confirmed historical events as skipped and let the ordered checkpoint
  advance. Do not fetch their items, save item snapshots, or update inventory,
  prices, orders or loyalty.
- Keep current-year synchronization and existing deletion recovery intact.
  Missing records, malformed responses and API failures are not silently skipped.

## Upgrade

Upgrade in place without uninstalling. The 2.3.47 upgrade handler changes code
only and preserves settings, mappings, webhook journal rows and the checkpoint.
It does not reset the cursor, replay completed events or write accounting data.
Keep the existing module-owned once-per-minute CLI schedule.

## Validation

Release validation lints every tracked PHP file and runs 13 standalone test
commands on PHP 8.1 and PHP 8.2. Invoice history coverage includes 1,042 assertions
covering all invoice create/edit families, API and UI events, invalid fiscal
identities, unavailable old item data and current-year processing after a skip.
The installable ZIP, version manifests and SHA-256 assets are checked against
the exact release commit.
