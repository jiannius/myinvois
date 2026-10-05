# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel package (PSR-4 `Jiannius\Myinvois\`) that integrates with the Malaysian LHDN MyInvois e-invoicing API. It builds UBL 2.1 JSON invoice documents, signs them with XAdES, submits them to MyInvois, and tracks lifecycle state in an `myinvois_documents` table. There is a PHPUnit test suite (`composer test`, see "Testing" below) but no build step and no lint config — it's a library consumed by host Laravel apps. Auto-discovered via `MyinvoisServiceProvider`, which binds the singleton-style `myinvois` container key to a fresh `Myinvois` instance.

## Entry point and call flow

`src/Myinvois.php` is the only public surface. Typical usage from a host app:

```php
app('myinvois')
    ->setClientId(...)->setClientSecret(...)->setPrivateKey(...)->setCertificate(...)
    ->submitDocuments([$normalizedDocument]);
```

Settings cascade: explicit `set*()` → `config('services.myinvois.*')` → preprod toggle defaults to `!app()->environment('production')`. The MyInvois OAuth token is cached under `myinvois_<clientId>_<onBehalfOf>` for 50 minutes. `callApi()` enforces per-endpoint rate limits via Laravel `RateLimiter` (sleeps when capped) — preserve the `perMinute` values when adding new endpoints; they mirror LHDN's published limits.

Submission pipeline inside `submitDocuments()`:

1. `UBL::build($document)` — converts the flat normalized array (see `Helpers/Sample.php` for the canonical shape) into the deep nested UBL `Invoice.0.…` structure expected by MyInvois. Built up section-by-section via `getDocument*Schema()` methods.
2. `Signature::build($document, $privateKey, $certificate)` — computes XAdES enveloped signature: cert digest, doc digest (canonical minified JSON), `openssl_sign` with SHA256, signed-properties digest, then populates the `UBLExtensions` and `Signature` blocks. The signing JSON must use `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` with no whitespace — `Signature::toJson()` is the single source of truth.
3. JSON is base64-encoded with a SHA256 hash and POSTed to `documentsubmissions`.
4. `createMyinvoisDocuments()` persists one `MyinvoisDocument` row per accepted document — except for consolidated submissions, where one row is created per *line item* with the line description as `document_number` and the parent invoice number as `consolidate_number`. Detection runs per-document via `UBL::isConsolidated($document)`.
5. After submission, polls `getSubmission()` up to 3 times with 2s sleeps to flip `submitted` → `valid`/`invalid` immediately.

## API failure handling

`callApi()` / `getToken()` are the single place failures become exceptions (`src/Exceptions/`, all extend `MyinvoisException` -> `RuntimeException`): network `ConnectionException`, 5xx, 429 and a token response without `access_token` -> `MyinvoisUnavailableException` (network failures expose a sanitised `getReason()`; 429 keeps `Retry-After`; a token 408 is treated like 429); 4xx from `/connect/token` (except 408/429) and a 401 that survives one retry -> `MyinvoisAuthenticationException`; 403 -> `MyinvoisPermissionException` (thrown before `failedCallback`, as before). Other 4xx are deliberately **returned**, not thrown -- callers rely on LHDN's JSON rejection body (`submitDocuments`, `validateTaxpayerTIN` false on 404, `searchTaxpayerTIN` null on 404). On a 401 `callApi()` `cache()->forget()`s the token (`getTokenCacheKey()`) and retries once with a fresh token (no loop). `failedCallback` runs once, on the final response, and its return value replaces the response; the throw check happens on the post-callback response. The post-submit status poll in `createMyinvoisDocuments()` catches `MyinvoisException` and stops: the documents are already accepted and saved, so it must never throw. Configuration errors (missing client id/secret, key/certificate, preprod credentials) stay plain `\Exception` on purpose. Any method that writes local state from a response (`cancelDocument`, `getDocumentDetails`) must only do so when `$api->successful()` and the body decoded to an array. Exceptions keep only status/endpoint (query stripped)/raw body strings, never the Response object (it holds the bearer token) and never the original `ConnectionException` as `previous` (its Guzzle request carries the `Authorization` header / `client_secret` and the full URL). Known limitation: `MyinvoisFake` cannot simulate these failures. Keep the boost guideline and README in sync if this changes.

## Consolidated e-invoices

`is_consolidate` (boolean, on the top-level document) is the canonical signal that a document is a consolidated e-invoice. Callers setting it to `true` don't need to populate `line_items[].classifications` — `UBL::getDocumentLineItemsSchema()` automatically emits a single `004` classification with `listID=CLASS` on every line, and skips any tariffs. The legacy contract (every line tagged with classification `004`) still works and is treated identically — `UBL::isConsolidated($document)` recognizes both. The flag form is preferred for new callers.

Detection is **per-document** (not per-submission-batch): mixed batches where some documents are consolidated and others aren't are persisted correctly.

## Rounding amount

`payable_rounding` (optional flat key) maps to `LegalMonetaryTotal.0.PayableRoundingAmount.0._` (+ `currencyID`), written between `TaxInclusiveAmount` and `PayableAmount` (UBL 2.1 order) by `UBL::getDocumentTotalsSchema()`, rounded to 2dp by the builder, and only when that rounded value is non-zero — a 0/null/missing/sub-cent value emits no element, so existing callers' output is unchanged. Both `restoreJson()` and `restoreXml()` read it back as `payable_rounding`. The package never computes it: the caller keeps `grand_total` (before rounding), `payable_total` (after) and `payable_rounding` consistent. Whether LHDN enforces Payable = TaxInclusive + Rounding is unconfirmed pending a preprod submission (see `docs/lhdn-references.md`). Validator rule is `nullable|decimal:0,2`.

## UBL builder conventions

`UBL.php` uses `data_set($schema, 'Invoice.0.X.0._', $value)` heavily. The `.0.` indexes and trailing `._` value keys come from the `Noki\XmlConverter\Convert` JSON↔XML format — they're load-bearing, not arbitrary. When adding fields, follow the existing dotted paths exactly.

TIN handling: `getDocumentTINSubschema()` maps the flat `tin`/`brn`/`nric`/`passport`/`army` keys to UBL's `PartyIdentification` entries. `Enums/TinType.php` defines the special general-public / foreign / government TINs used when a buyer has no real TIN.

Codes (countries, states, currencies, MSIC, units, taxes, classifications, payment-modes, document-types/versions) live as static JSON in `json/codes/` and are accessed via `Helpers/Code.php`:

```php
Code::countries()->value('Malaysia');   // → 'MYS'
Code::states()->get('Kuala Lumpur');    // auto-prefixes 'Wilayah Persekutuan'
```

`Code` is a `__callStatic` magic class — the snake-slug of the method name selects the JSON file. `getValueKey()`/`getLabelKey()` map per-file column conventions (e.g. `document-versions` uses `Version` as the value key, others use `Code`).

## Persistence model

`database/migrations/myinvois_001_myinvois_documents.php` creates `myinvois_documents` with a ULID PK, polymorphic `parent_type`/`parent_id`, and JSON `request`/`response` columns. The migration is a no-op if the table already exists — host apps publish/run it once.

`Models/MyinvoisDocument.php` is morphTo'd to the host model. The `Status` enum (`submitted`/`valid`/`invalid`/`cancelled`) drives the `color()`, `label()`, and `is()` helpers used in UI.

`Models/Traits/HasMyinvoisDocument` is mixed into host-app models that own invoices. It resolves the document model class via `MyinvoisDocument::$useModel` (Sanctum/Cashier-style swap) — defaults to the package's own `Jiannius\Myinvois\Models\MyinvoisDocument`, but host apps can extend it and register their subclass from a service provider: `MyinvoisDocument::useModel(\App\Models\MyinvoisDocument::class)`. The trait adds `myinvois_status` / `myinvois_preprod_status` casts and the morph relations; the observer in `Observers/MyinvoisDocumentObserver` writes back to those columns on the parent whenever a child doc is saved (preprod vs prod kept separate).

`isCancellable()` enforces the LHDN 72-hour cancellation window — preserve this when touching cancel logic.

## Environment / endpoints

Two base URLs hardcoded in `Myinvois::$baseUrl`: `prod` (`api.myinvois.hasil.gov.my`) and `preprod`. The `preprod` flag also controls the validation share-link host in `MyinvoisDocument::getValidationLinkAttribute()`. `getEndpoint()` prepends `/api/v1.0/` unless the URI starts with `/` (only the OAuth `/connect/token` endpoint uses the bare form).

## Testing

PHPUnit (not Pest — Pest's latest caps at PHPUnit ^12, this package is on PHPUnit 13 via `testbench` 11 / Laravel 13). Run `composer test` or `vendor/bin/phpunit`. Suites: `tests/Unit` (pure helpers — `Code`, `UBL` build/restore/sanitize, `Signature`, `Validator`, enums, all no-network) and `tests/Feature` (testbench + in-memory sqlite — model casts/scopes, the `HasMyinvoisDocument` trait, observers, and the `Myinvois` API layer / `submitDocuments` pipeline under `Http::fake()`). `phpunit.xml` runs with `failOnWarning`/`failOnRisky`.

Fixtures in `tests/Fixtures/`: `DocumentFixture` (deterministic flat-array inputs — no `now()`/`time()`, unlike `Helpers/Sample.php`), `CertFixture` (throwaway RSA keypair + self-signed cert, memoised per process so signature digests are reproducible), and `Order` (a polymorphic parent model using the trait). Signature tests freeze time with `Carbon::setTestNow`. The suite covers the build/sign/persist logic locally, but true end-to-end acceptance still requires the preprod MyInvois sandbox via a host app — there is no local way to dry-run an actual submission.

## Things to know before editing

- When changing document shape or signing, run `composer test` first — the golden-path assertions in `tests/Unit/UblBuildTest.php` and `tests/Unit/SignatureTest.php` guard the load-bearing UBL paths and XAdES digests. End-to-end still needs the preprod sandbox.
- Document shape changes must stay backwards-compatible with the flat array contract in `Helpers/Sample.php` — that's the documented input format for callers.
- The signing flow is order-sensitive: `UBL::build` must run before `Signature::build`, and the JSON serialization inside `Signature::toJson` must not be reformatted.
- `Validator.php` runs Laravel validation against the flat input shape (not the built UBL). Call `app('myinvois')->validator($doc)` before submission to surface user-friendly errors.
- The Laravel Boost AI guideline at `resources/boost/guidelines/core.blade.php` is host-app-facing documentation, picked up by `boost:install` in any host project. Keep it in sync when changing the public API surface (`Myinvois` methods, the `HasMyinvoisDocument` trait, the document input contract). Validate with the Blade compiler before tagging — `<code-snippet>` blocks must be wrapped in `@verbatim` / `@endverbatim`.

## LHDN policy questions

For anything beyond what this package encodes — current code lists, validation rules per doc type, mandate phase / rollout dates, sector-specific exemptions — see `docs/lhdn-references.md` for authoritative URLs and the load-bearing invariants this codebase depends on. Don't infer LHDN policy from the code alone; fetch from their SDK when in doubt.
