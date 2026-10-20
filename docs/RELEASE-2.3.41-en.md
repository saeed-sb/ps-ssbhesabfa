# SSB Hesabfa 2.3.41

Reassign the existing sales invoice freight person after a Delivery Manager carrier change and recompute the owned freight expense or income document. Keep invoice identity, goods, quantities, serials, customer shipping charges, payments and customer balance intact, with read-back verification.

Carrier operations are queued within the delivery transaction, serialized and retried using durable request UUIDs. Stale requests, unmapped carriers, edited documents and fiscal-year mismatches stop for review.

Upgrade adds the nullable invoice recovery field and registers the carrier-change hook. It does not enable accounting, replay orders or contact Hesabfa. Configure carrier-person mappings before using the integration. Install this version before Delivery Manager 1.6.0 when carrier-change accounting is used.

The release validates every tracked PHP file and twelve standalone offline test commands on PHP 8.1 and 8.2. No live shop installation or provider transaction is performed. The attached validation report records the source commit, package checksums and exact test scope.
