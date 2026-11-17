# ssbhesabfa 2.3.48

Use the new accounting method to record outstanding gateway settlements for
installment orders placed through Qestyar (Vendor Pay).

## Configuration

- Install Qestyar to make **Qestyar installment accounting** available in the
  Hesabfa module's **Payment methods** settings. The default is **Existing
  accounting method**.
- For each gateway that handles Qestyar orders, enter the Hesabfa receivable
  account path and use its configured gateway fee and expense account path.
  Choose **Gateway receivables and fee expense** to apply this method to new
  payments.

## Accounting

The full verified payment amount is applied to the sales invoice and debited to
gateway receivables. The separate fee document debits the full gateway fee to
the expense account and credits gateway receivables. The installment markup is
already reflected in the sales invoice.

Registration stores the amounts and accounts used by the payment. A retry after
a successful receipt continues with the fee document. Selecting the existing
accounting method applies that method to subsequent payments.

## Upgrade

Upgrade the module in place. The upgrade adds a column to the operations
journal so new installment payments can retain their accounting details for
retries. It preserves the existing financial records and settings.

## Validation

The workflow checks the PHP source, version manifests and standalone accounting
tests on PHP 8.1 and 8.2. The release assets include the installable ZIP and
its SHA-256 checksum.
