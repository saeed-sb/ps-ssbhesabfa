# ssbhesabfa 2.3.53

When a fully paid installment order uses gateway receivables, the configured cancellation status creates a full sales return, a refund against that return using the original receivable account and contact, and a reversing journal for the original gateway fee.

The return copies the original sales invoice, including installment prices, discounts, tax and freight. The cancellation date and accounting details are saved for retries. Completed entries are verified before the workflow continues.

New installment sales invoices use the final recorded order line prices. Gateway refund processing remains in Payment Hub.
