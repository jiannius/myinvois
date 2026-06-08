# LHDN MyInvois — authoritative references

This file points to **LHDN's official sources** for the Malaysian e-invoicing rules this package implements. Do not mirror their content here — they version their SDK actively and a stale copy is worse than no copy. When a session needs domain knowledge beyond what this package encodes, fetch from these URLs.

**Last reviewed: 2026-05-17.** If the date is more than ~3 months old, treat specifics (mandate dates, code lists, validation rules) as potentially out of date and reconfirm from source before relying on them.

## Primary sources

| Topic | Where |
|---|---|
| Developer SDK home (API spec, code lists, validation rules) | https://sdk.myinvois.hasil.gov.my |
| Production API base | https://api.myinvois.hasil.gov.my |
| Preprod / sandbox API base | https://preprod-api.myinvois.hasil.gov.my |
| Production portal (validation links, taxpayer login) | https://myinvois.hasil.gov.my |
| Preprod portal | https://preprod.myinvois.hasil.gov.my |

For specific topics (classification codes, MSIC codes, payment modes, document types, validation rules per doc type, mandate phase / rollout schedule), navigate from the SDK home — paths shift between SDK versions, so always start there rather than caching deep links.

## Invariants this package depends on

Rules the codebase encodes today. If LHDN changes any of these, the package needs an update — these are the load-bearing assumptions, not a full spec.

- **72-hour cancellation window** — suppliers can cancel a `valid` document within 72 hours of issue. Enforced by `MyinvoisDocument::isCancellable()`.
- **Buyer rejection vs supplier cancellation** — distinct LHDN states. `cancelDocument()` is for the issuing supplier; `rejectDocument()` is for the buyer rejecting a doc issued *to* them. Both are time-bounded by LHDN.
- **Classification code `004` = consolidated e-invoice** — emitted automatically on every line when `is_consolidate => true`. Documented in LHDN's classification code list.
- **`CommodityClassification` has two parallel code systems** — `ItemClassificationCode` with `listID=CLASS` is the MyInvois classification list (codes 001–045; resolved via `Code::classifications()`), while `listID=PTC` is the Product Tariff Code = Harmonized System / customs tariff code (e.g. `9800.00.0010`) and must be emitted **verbatim** — never looked up in the CLASS list. An item may carry both at once. Encoded in `UBL::getDocumentLineItemsSchema()` (`tariffs` → PTC verbatim, `classifications` → CLASS lookup). Per the SDK invoice spec at https://sdk.myinvois.hasil.gov.my/documents/invoice-v1-1/.
- **General Public TIN** — `Enums\TinType::GENERAL_PUBLIC`. Allowed as buyer TIN **only** for consolidated documents (or non-Invoice/Credit/Debit/Refund doc types). Enforced in `Helpers/Validator.php`.
- **Consolidated MYR 10,000 per-line cap** — LHDN policy that grand totals over MYR 10,000 cannot be consolidated. Validator rule currently **commented out** in `Helpers/Validator.php:43`; the error message is wired up but the rule isn't enforced. Re-enable if/when LHDN clarifies.
- **UBL 2.1 + XAdES signing** — payload format and signature scheme. `Signature::toJson()`'s exact JSON serialization (no whitespace, unescaped unicode + slashes) is what LHDN canonicalizes against — never reformat.
- **Per-endpoint rate limits** — encoded as `perMinute` values on each `callApi()` call site. Mirror the published LHDN limits; update them here if LHDN publishes new caps.

## When to update this file

- LHDN publishes a new SDK version with breaking changes to code lists, validation rules, or endpoint shapes.
- Mandate phase rolls forward to a new taxpayer-turnover tier.
- One of the invariants above changes (e.g. cancellation window changes from 72h, or the consolidated cap moves off MYR 10,000).

Bump the "Last reviewed" date when you check and nothing changed; rewrite the affected invariant when something did.
