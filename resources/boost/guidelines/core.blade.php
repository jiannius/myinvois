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

### Testing your integration

The package already tests its own internals (UBL build, signing, validator, codes, models, the submission pipeline). **Don't re-test those.** What *your* app owns — and should test — is the mapping from your domain models into the flat document array, plus your submit/cancel flows.

Two patterns cover almost everything, both offline:

**1. Assert your mapping produces a valid, buildable document** (no network, no keys):

@verbatim
<code-snippet name="Test your mapping offline" lang="php">
use Jiannius\Myinvois\Helpers\UBL;

$document = $order->toMyinvoisDocument();        // your mapper

$this->assertTrue(app('myinvois')->validator($document)->passes());
$this->assertIsArray(UBL::build($document));     // throws if the shape is wrong
</code-snippet>
@endverbatim

**2. Test submit / cancel flows with `Myinvois::fake()`** — swaps the `myinvois` binding for an in-memory double that never signs, calls the network, or sleeps. It records calls and persists `MyinvoisDocument` rows exactly like the real pipeline (consolidated-aware), so observers and `myinvois_status` write-back still fire:

@verbatim
<code-snippet name="Test flows with the fake" lang="php">
use Jiannius\Myinvois\Myinvois;

$fake = Myinvois::fake();              // no client id/secret/key/cert needed

$order->submitToMyinvois();           // your code: app('myinvois')->submitDocuments([...])

$fake->assertSubmitted();
$fake->assertDocumentSubmitted($order->invoice_number);
$this->assertTrue($order->fresh()->isSubmittedToMyinvois());

// configure outcomes and canned read responses:
Myinvois::fake()->resolveStatus('invalid');                          // persisted status
Myinvois::fake()->respondToApi('taxpayer/search/*', ['tin' => 'C1']); // read endpoints
</code-snippet>
@endverbatim

Available assertions on the fake: `assertSubmitted(?callable)`, `assertNothingSubmitted()`, `assertSubmittedCount(int)`, `assertDocumentSubmitted($number)`, `assertCancelled(?$uid)`, `assertNotCancelled()`, `assertRejected(?$uid)`.

True end-to-end (a real signature accepted by LHDN) still requires the **preprod sandbox** — the fake proves your wiring, not that LHDN accepts the payload.

### LHDN constraints to respect

- **72-hour cancellation window** — enforced by `isCancellable()`; LHDN rejects late cancellations anyway.
- **Rate limits** — already handled by `callApi()` via Laravel `RateLimiter`. If you wrap submission in a queue, don't add a second throttle.
- **Document shape is the contract** — the flat array in `Helpers/Sample.php` is the documented input. Don't hand-roll UBL.
- **Preprod vs prod isolation** — preprod docs auto-route to `preprod.myinvois.hasil.gov.my`; status columns are kept separate. The package toggles preprod based on environment, but you can force it with `setPreprod(true|false)`.
- **Test offline with `Myinvois::fake()` and `validator()`** (see "Testing your integration") — but there is no local dry-run of a real LHDN submission. Validate end-to-end against the preprod sandbox before touching prod.

### LHDN policy beyond this package

This guideline covers using the package. For questions about LHDN's own rules — current classification / payment-mode / MSIC code lists, validation rules per document type, mandate rollout phases, sector exemptions — go to the source rather than guessing:

- SDK home: https://sdk.myinvois.hasil.gov.my (start here for code lists, validation specs, API reference)
- Production portal: https://myinvois.hasil.gov.my (preprod sandbox: https://preprod.myinvois.hasil.gov.my)

The package's repo also keeps a short `docs/lhdn-references.md` with the load-bearing invariants the code depends on (72h cancel window, classification `004` semantics, General Public TIN rules, etc.) — useful if you're touching the package itself.

### Preflight checklist when wiring this into a feature

- Configure client id, client secret, private key (PEM string), certificate (PEM string).
- Add `myinvois_status` and `myinvois_preprod_status` (nullable string) columns to the host table.
- Apply `HasMyinvoisDocument` to the host model.
- Build a flat document matching `Helpers/Sample.php`.
- Call `app('myinvois')->validator($document)` and surface errors before submitting.
- For consolidated docs: set `is_consolidate => true`, use the `GENERAL_PUBLIC` TIN, and skip line-item classifications.
- Track status via `$model->myinvois_status` / `latestMyinvoisDocument` rather than re-querying MyInvois.
- For cancellation, gate the UI on `isCancellable()` so users don't try after the 72-hour window.
- Test the mapping offline with `validator()` / `UBL::build()`, and the submit/cancel flow with `Myinvois::fake()`, before validating end-to-end on preprod.
