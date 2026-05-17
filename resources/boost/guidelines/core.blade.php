## Jiannius MyInvois

`jiannius/myinvois` integrates Laravel apps with the Malaysian LHDN MyInvois e-invoicing API. It builds UBL 2.1 JSON, signs with XAdES, submits to MyInvois, and tracks lifecycle state on a `myinvois_documents` table polymorphically attached to host models. The package's only public surface is the `Myinvois` class, resolvable as `app('myinvois')`.

### Setup

Settings cascade: explicit `set*()` → `config('services.myinvois.*')` → preprod auto-enabled outside the `production` environment. The OAuth token is cached per `clientId + onBehalfOf` for 50 minutes, and `callApi()` already honors LHDN's per-endpoint rate limits — don't add your own throttling.

@verbatim
<code-snippet name="Configure the singleton" lang="php">
$myinvois = app('myinvois')
    ->setClientId(config('services.myinvois.client_id'))
    ->setClientSecret(config('services.myinvois.client_secret'))
    ->setPrivateKey(config('services.myinvois.private_key'))    // PEM string, not a file path
    ->setCertificate(config('services.myinvois.certificate')); // PEM string, not a file path

// Optional: act on behalf of another taxpayer (intermediary flow)
$myinvois->setOnBehalfOf(tin: 'C20880050010', brn: '202301234567');

// Optional: hook failed API responses (logging, alerts)
$myinvois->setFailedCallback(fn ($response) => report(new \Exception($response->body())));
</code-snippet>
@endverbatim

### Submission flow

The package expects a **flat normalized document array**. The canonical input shape is in `vendor/jiannius/myinvois/src/Helpers/Sample.php` — read it before assembling a document. The deep UBL structure underneath is an internal detail; build inputs to match `Sample.php`, not the wire format.

Always validate before submitting. The validator runs against the flat input (not the built UBL), so its errors are user-friendly.

@verbatim
<code-snippet name="Validate then submit" lang="php">
$validator = app('myinvois')->validator($document);

if ($validator->fails()) {
    return back()->withErrors($validator->errors());
}

$response = app('myinvois')
    ->setClientId(...)->setClientSecret(...)
    ->setPrivateKey(...)->setCertificate(...)
    ->submitDocuments([$document]);  // accepts a batch
</code-snippet>
@endverbatim

`submitDocuments()` runs the pipeline: `UBL::build` → `Signature::build` (XAdES, SHA256) → POST to `documentsubmissions` → persist one `MyinvoisDocument` per accepted doc → poll `getSubmission()` up to 3× with 2s sleeps to flip `submitted` → `valid`/`invalid`. The signing JSON serialization in `Signature::toJson()` is load-bearing — never reformat the document between `UBL::build` and `Signature::build`.

### Consolidated e-invoices

For consolidated submissions (e.g. summarizing daily POS receipts), set `is_consolidate => true` on the document. The package then auto-emits classification code `004` (the LHDN consolidated marker) on every line and skips per-line classifications/tariffs.

@verbatim
<code-snippet name="Consolidated document" lang="php">
use Jiannius\Myinvois\Enums\TinType;

$document = [
    'is_consolidate' => true,
    'document_type' => '01',  // Invoice
    'supplier' => [...],
    'buyer' => [
        'tin' => TinType::GENERAL_PUBLIC->value,
        'name' => 'General Public',
    ],
    'line_items' => [
        ['description' => 'POS Receipt #1001', 'qty' => 1, 'unit_price' => 25.00, 'subtotal' => 25.00, ...],
        ['description' => 'POS Receipt #1002', 'qty' => 1, 'unit_price' => 18.50, 'subtotal' => 18.50, ...],
    ],
    // ...totals, taxes
];
</code-snippet>
@endverbatim

Consolidated submissions persist **one `MyinvoisDocument` row per line item**, with the line `description` stored as `document_number` and the parent invoice number as `consolidate_number`. This lets each underlying sale (typically one host model per line, e.g. a POS receipt) link back to its own MyInvois status via the polymorphic `parent` relation. Available since `v1.1.0`; the legacy convention (every line tagged with classification `004` instead of the flag) still works.

### Document lifecycle

A `MyinvoisDocument` row moves through the `Status` enum: `submitted` → `valid` / `invalid` → optionally `cancelled`.

@verbatim
<code-snippet name="Cancel a document (within 72 hours)" lang="php">
$doc = $sale->latestMyinvoisDocument;

if ($doc->isCancellable()) {
    app('myinvois')->cancelDocument($doc->document_uuid, reason: 'Wrong buyer details');
}
</code-snippet>
@endverbatim

LHDN only accepts cancellations within 72 hours of issue — `MyinvoisDocument::isCancellable()` enforces this. Do not bypass it.

@verbatim
<code-snippet name="Read validation errors and share-link" lang="php">
$doc = $sale->latestMyinvoisDocument;

if ($doc->status->is('INVALID')) {
    foreach ($doc->getErrors() as $error) {
        // $error['code'], $error['message']
    }
}

$shareUrl = $doc->validation_link; // public LHDN URL once the doc is VALID
</code-snippet>
@endverbatim

Use `cancelDocument()` to retract a doc you issued; use `rejectDocument()` when **you are the buyer** rejecting a doc someone else issued to you.

### Host-model integration

Mix `HasMyinvoisDocument` into models that own invoices (a `Sale`, `Order`, `Invoice`, etc.). The trait wires the polymorphic relations, casts, observer, and helpers. Preprod and production are tracked in separate columns so they never collide.

@verbatim
<code-snippet name="Attach to a host model" lang="php">
use Jiannius\Myinvois\Models\Traits\HasMyinvoisDocument;

class Sale extends Model
{
    use HasMyinvoisDocument;
}

// Required columns on the host table:
// - myinvois_status (string, nullable)
// - myinvois_preprod_status (string, nullable)
</code-snippet>
@endverbatim

The trait exposes:

- `myinvoisDocuments()` / `preprodMyinvoisDocuments()` — `morphMany` to all docs.
- `latestMyinvoisDocument()` / `preprodLatestMyinvoisDocument()` — `morphOne` to the most recent.
- `isSubmittedToMyinvois(submitted: true, preprod: false)` — true when the latest doc is `submitted` or `valid`.
- `scopeWithSubmittedMyinvoisDocument(submitted: true, preprod: false)` — eloquent scope for queries.
- `getMyinvoisValidationLink(preprod: false)` — public LHDN share URL for the latest doc.
- `getMyinvoisQrCode(preprod: false)` — base64 PNG QR code wrapping the share URL.

The cast attributes `myinvois_status` and `myinvois_preprod_status` (both `Status` enums) are kept in sync by `MyinvoisDocumentObserver` every time a child document is saved or deleted — read them on the host model, don't recompute.

To extend the document model (custom relations, mutators, etc.), subclass and register from a service provider — Sanctum/Cashier-style swap:

@verbatim
<code-snippet name="Swap the document model" lang="php">
use Jiannius\Myinvois\Models\MyinvoisDocument;

// in App\Providers\AppServiceProvider::boot()
MyinvoisDocument::useModel(\App\Models\MyinvoisDocument::class);
</code-snippet>
@endverbatim

### Other API surfaces

`Myinvois` also wraps these endpoints — use them when you need to query MyInvois state outside the submission flow:

- `searchTaxpayerTIN($idType, $idValue, $taxpayerName)` / `validateTaxpayerTIN($tin, $brn, $nric)` — TIN lookup and verification.
- `getDocument($uid)` / `getDocumentDetails($uid)` — fetch a single document by UUID.
- `getSubmission($uid)` — fetch a submission and its accepted/rejected children (also called internally by the post-submit poll).
- `getRecentDocuments($data)` / `searchDocuments($data)` — list/search across the taxpayer's docs.

All of these go through `callApi()`, which respects per-endpoint rate limits and the auth token cache automatically.

### Codes and enums

Codes (countries, states, currencies, MSIC, units, taxes, classifications, payment-modes, document-types/versions) are accessed via `Jiannius\Myinvois\Helpers\Code` — pass either the human label or the LHDN code; both resolve to the LHDN value.

@verbatim
<code-snippet name="Resolve codes" lang="php">
use Jiannius\Myinvois\Helpers\Code;

Code::countries()->value('Malaysia');         // → 'MYS'
Code::states()->value('Kuala Lumpur');        // auto-prefixes 'Wilayah Persekutuan'
Code::classifications()->value('Others');     // → '022'
Code::documentTypes()->value('Invoice');      // → '01'
Code::documentVersions()->value('1.0');       // version-keyed
Code::msic()->value('XXXXX');                 // Malaysia Standard Industrial Classification
</code-snippet>
@endverbatim

Special TINs (general public, foreign buyer, government) live in `Jiannius\Myinvois\Enums\TinType`. Use these when the buyer has no real TIN — but note: without an `is_consolidate => true` flag, the validator rejects standard submissions to `GENERAL_PUBLIC` for Invoice / Credit Note / Debit Note / Refund Note types.

### LHDN constraints to respect

- **72-hour cancellation window** — enforced by `isCancellable()`; LHDN rejects late cancellations anyway.
- **Rate limits** — already handled by `callApi()` via Laravel `RateLimiter`. If you wrap submission in a queue, don't add a second throttle.
- **Document shape is the contract** — the flat array in `Helpers/Sample.php` is the documented input. Don't hand-roll UBL.
- **Preprod vs prod isolation** — preprod docs auto-route to `preprod.myinvois.hasil.gov.my`; status columns are kept separate. The package toggles preprod based on environment, but you can force it with `setPreprod(true|false)`.
- **No automated test suite** — validate end-to-end against the preprod sandbox before touching prod. There is no local dry-run mode.

### Preflight checklist when wiring this into a feature

- Configure client id, client secret, private key (PEM string), certificate (PEM string).
- Add `myinvois_status` and `myinvois_preprod_status` (nullable string) columns to the host table.
- Apply `HasMyinvoisDocument` to the host model.
- Build a flat document matching `Helpers/Sample.php`.
- Call `app('myinvois')->validator($document)` and surface errors before submitting.
- For consolidated docs: set `is_consolidate => true`, use the `GENERAL_PUBLIC` TIN, and skip line-item classifications.
- Track status via `$model->myinvois_status` / `latestMyinvoisDocument` rather than re-querying MyInvois.
- For cancellation, gate the UI on `isCancellable()` so users don't try after the 72-hour window.
