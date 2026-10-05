# Jiannius MyInvois SDK

A Laravel SDK for the Malaysian LHDN MyInvois e-invoicing API. Handles authentication, UBL 2.1 document building, XAdES digital signing, submission, and lifecycle tracking against both the production and preprod (sandbox) MyInvois portals.

## Requirements

- PHP ^8.3
- Laravel ^13.0
- A MyInvois client ID, client secret, private key, and X.509 certificate registered against your company TIN on the [MyInvois portal](https://myinvois.hasil.gov.my) (or the [preprod portal](https://preprod.myinvois.hasil.gov.my))

Host apps still on Laravel 12 should pin the `^0.1` release line — the `^1.0` line is L13-only.

## Installation

```bash
composer require jiannius/myinvois
```

The package's service provider is auto-discovered via Laravel's package discovery — it also auto-loads the `myinvois_documents` migration. Just run:

```bash
php artisan migrate
```

The migration is idempotent (it short-circuits if `myinvois_documents` already exists), so it's safe even if you've already created the table by other means.

## Configuration

Add a `myinvois` section to `config/services.php`:

```php
'myinvois' => [
    // Production credentials
    'client_id' => env('MYINVOIS_CLIENT_ID'),
    'client_secret' => env('MYINVOIS_CLIENT_SECRET'),

    // Preprod (sandbox) credentials — falls back to client_id / client_secret if not set
    'preprod_client_id' => env('MYINVOIS_PREPROD_CLIENT_ID'),
    'preprod_client_secret' => env('MYINVOIS_PREPROD_CLIENT_SECRET'),

    // Force preprod on/off. If not a boolean, the SDK auto-selects preprod
    // whenever app()->environment() != 'production'.
    'preprod' => env('MYINVOIS_PREPROD'),

    // PEM-encoded private key and X.509 certificate used for XAdES signing
    'private_key' => env('MYINVOIS_PRIVATE_KEY'),
    'certificate' => env('MYINVOIS_CERTIFICATE'),

    // Your own company TIN. When setOnBehalfOf() is called with this TIN,
    // the SDK clears the on-behalf-of header (since you ARE this taxpayer).
    'client_tin' => env('MYINVOIS_CLIENT_TIN'),

    // Optional HTTP timeouts in seconds (token request and every API call).
    // Defaults: connect_timeout 10, timeout 60. Zero / non-numeric values are ignored.
    'connect_timeout' => env('MYINVOIS_CONNECT_TIMEOUT', 10),
    'timeout' => env('MYINVOIS_TIMEOUT', 60),
],
```

## Quick start

Submit the bundled sample document against preprod:

```php
$response = app('myinvois')->submitDocuments('sample');
```

That builds a fully populated test invoice from `Helpers/Sample::build()`, signs it with your configured cert, and POSTs to MyInvois. The response includes the submission UID and per-document acceptance/rejection.

## Resolving the SDK

The package binds a singleton-ish `myinvois` key to a fresh `Myinvois` instance:

```php
$myinvois = app('myinvois');
```

You can override any of the configured settings per-instance via fluent setters:

```php
$response = app('myinvois')
    ->setClientId('...')
    ->setClientSecret('...')
    ->setPrivateKey($pem)
    ->setCertificate($pem)
    ->setPreprod(true)
    ->submitDocuments($documents);
```

| Setter | Purpose |
|---|---|
| `setClientId($value)` | OAuth2 client ID |
| `setClientSecret($value)` | OAuth2 client secret |
| `setPreprod($bool)` | Force preprod (true) or production (false). Omit to auto-detect via `app()->environment()`. |
| `setOnBehalfOf($tin, $brn = null)` | For intermediaries — submit as if logged in as another taxpayer. `$brn` is only used when `$tin` matches the IG-prefix intermediary format. |
| `setPrivateKey($pem)` | PEM-encoded RSA private key (only needed for `submitDocuments`) |
| `setCertificate($pem)` | PEM-encoded X.509 certificate (only needed for `submitDocuments`) |
| `setConnectTimeout($seconds)` | Seconds to wait to establish the connection (default 10). Overrides `services.myinvois.connect_timeout`. |
| `setTimeout($seconds)` | Seconds to wait for the whole request, token request included (default 60). Overrides `services.myinvois.timeout`. |
| `setFailedCallback(fn)` | Closure invoked when an API call returns a non-2xx response. Receives the `Illuminate\Http\Client\Response`; whatever it returns replaces the original response. |

## Submitting documents

`submitDocuments($documents)` accepts an array of document arrays (see [Document shape](#document-shape) below) and runs the full pipeline:

1. UBL 2.1 schema build (`Helpers\UBL::build`)
2. XAdES enveloped signature (`Helpers\Signature::build`)
3. SHA-256 hash + base64 encode
4. POST to `/api/v1.0/documentsubmissions`
5. Create one `myinvois_documents` row per accepted document (or one per *line item* for consolidated submissions, see below)
6. Poll the submission up to 3 times (2s intervals) to flip `submitted` → `valid` / `invalid` immediately

```php
$result = app('myinvois')->submitDocuments([
    [
        'number' => 'INV-2026-0001',
        'issued_at' => now(),
        'document_type' => \Jiannius\Myinvois\Helpers\Code::documentTypes()->value('Invoice'),
        'document_version' => \Jiannius\Myinvois\Helpers\Code::documentVersions()->value('Invoice'),
        'currency' => 'MYR',
        // ... supplier, buyer, line_items, totals, etc.
    ],
]);

// $result['myinvois_documents'] is a Collection<MyinvoisDocument>
// $result['response']           is the raw MyInvois API response
```

### Consolidated submissions

When **every** line item across all submitted documents has only classification code `004`, the SDK treats it as a consolidated submission and creates one `myinvois_documents` row per **line item** (using `description` as `document_number` and the parent invoice number as `consolidate_number`), rather than one row per document.

### Failure handling

A non-2xx response (other than 403) from MyInvois passes through `failedCallback` if set:

```php
app('myinvois')->setFailedCallback(function ($response) {
    Log::error('MyInvois call failed', [
        'status' => $response->status(),
        'body' => $response->body(),
    ]);
    return $response;
});
```

Failures surface as typed exceptions under `Jiannius\Myinvois\Exceptions`. All extend `MyinvoisException` (a `RuntimeException`), so `catch (\RuntimeException)` / `catch (\Exception)` keep working.

| Exception | Raised for |
|---|---|
| `MyinvoisUnavailableException` | Network failure or timeout (`getReason()` holds the connection error text with URL query strings stripped; the original exception is not chained), HTTP 5xx, HTTP 429 (`getRetryAfter()`), a 408 or 429 from the token endpoint, or a 200 from the token endpoint with no token. Transient: show "try again later". |
| `MyinvoisAuthenticationException` | Any 4xx from the OAuth token endpoint except 408/429 (e.g. `invalid_client` becomes *"MyInvois rejected the API credentials…"*, see `getTokenErrorMessage`), or a 401 from an API call that is still a 401 after one retry with a fresh token. |
| `MyinvoisPermissionException` | HTTP 403 (`Permissions denied from MyInvois Portal`). Does not run `failedCallback`. |

Each carries `getStatus()` (HTTP status, `null` for a network failure), `getEndpoint()` (never includes a query string), `getResponseBody()` (raw, may be HTML) and `getResponseData()` (decoded JSON or `null`). The message never contains the raw body. The original Laravel/Guzzle connection exception is deliberately **not** chained (`getPrevious()` is `null`): it carries the request, including the `Authorization` header.

On a 401 from an API call the cached access token is discarded and the call is retried **once** with a freshly requested token. Only a second 401 throws `MyinvoisAuthenticationException`. A 403 is never retried.

Any other 4xx (400, 404, 422…) is an ordinary LHDN answer and is **returned**, not thrown: for example a validation rejection from `submitDocuments()` comes back as the JSON body, and `validateTaxpayerTIN()` returns `false` on a 404. `cancelDocument()` / `getDocumentDetails()` do not write a non-2xx response onto the local `myinvois_documents` row.

`failedCallback` runs once per call, on the final response (after the 401 retry, if any), for every non-403 failure; if it returns a successful response that response is used and nothing is thrown. If the response is still a 401 / 429 / 5xx afterwards, the exception is thrown. It is not used for the token request.

Every call to MyInvois (the token request and each API call) has a connect timeout (default 10s) and a total timeout (default 60s), so a hung connection fails instead of blocking your request or queue worker. A timeout throws `MyinvoisUnavailableException`. The 60s total is generous on purpose so a slow but valid `submitDocuments()` of a large batch is not cut off; raise it if your batches are bigger, via `setTimeout()` or `services.myinvois.timeout`.

**After an `Unavailable` error from `submitDocuments()` the submission may already have reached LHDN** (a timeout can happen after LHDN accepted the request). Check with `getSubmission()` or `getRecentDocuments()` before resubmitting. Once LHDN has accepted a submission, the follow-up status polling never throws: if it fails, the local rows stay `submitted` and `submitDocuments()` returns normally; call `getSubmission()` later to refresh them.

Configuration errors (missing client id / secret, missing private key / certificate, missing sandbox credentials) are **not** converted: they are still thrown as a plain `\Exception`.

**Known limitation:** `MyinvoisFake` (the test double) cannot simulate these failures yet; test your error handling with `Http::fake()` against the real class.

Preprod and prod credentials never fall back to one another: requesting preprod without sandbox credentials throws `Missing MyInvois sandbox (preprod) Client ID / Client Secret` rather than silently using prod creds against the preprod endpoint.

## Document shape

The SDK accepts a flat array shape (translated internally to UBL). Below is the canonical structure with required fields marked. See `Helpers/Sample.php` for a full populated example.

```php
[
    'number' => 'INV-2026-0001',                                  // required
    'issued_at' => now(),                                          // required, date|Carbon|string
    'document_type' => Code::documentTypes()->value('Invoice'),    // required
    'document_version' => Code::documentVersions()->value('Invoice'), // required
    'currency' => 'MYR',                                           // required
    'currency_rate' => null,                                       // required if currency != MYR

    // Required for Credit Note / Debit Note / Self-billed variants
    'original_number' => null,
    'original_document_uuid' => null,

    'payment_mode' => Code::paymentModes()->value('Bank Transfer'),
    'payment_term' => 'Payment method is cash',

    'billing' => [
        'start_at' => now(),
        'end_at' => now()->addDays(30),
        'frequency' => 'Monthly',
        'reference' => 'E12345678912',
    ],

    'references' => [
        ['type' => 'CUSTOMS',  'value' => 'E12345678912'],
        ['type' => 'FTA',      'value' => 'AANZFTA'],
        ['type' => 'INCOTERMS','value' => 'CIF'],
    ],

    'supplier' => [
        'name' => '...',                  // required
        'tin' => 'C26561325060',          // required
        'brn' => '202101001341',          // one of brn / nric / passport / army required
        'phone' => '+60-123456789',       // required (unless TIN is a TinType special)
        'email' => 'hello@example.com',
        'msic_code' => '46510',           // required
        'msic_description' => '...',      // required
        'address_line_1' => '...',        // required
        'address_line_2' => '...',
        'address_line_3' => '...',
        'postcode' => '50480',
        'city' => 'Kuala Lumpur',         // required
        'state' => Code::states()->value('Wilayah Persekutuan Kuala Lumpur'),  // required
        'country' => Code::countries()->value('Malaysia'),                     // required
        'bank_account_number' => '...',
        'certex' => 'CPT-CCN-W-...',      // sustainability certificate, optional
    ],

    'buyer' => [
        // Same shape as supplier (without msic_*, certex).
        // If TIN is a TinType special (EI0000000001x), only name + tin are required.
    ],

    'shipping' => [
        'name' => '...', 'tin' => '...', 'address_line_1' => '...',
        'amount' => 25.00, 'description' => 'Lalamove', 'reference' => 'L121321',
        // ... same address fields as supplier
    ],

    'prepaid' => [
        'amount' => 50,
        'paid_at' => now()->subDays(10),
        'reference' => 'P92342394',
    ],

    'charges' => [
        ['amount' => 20, 'description' => 'Service Charge'],
    ],
    'discounts' => [
        ['amount' => 100, 'description' => 'Festival Discount'],
    ],

    'taxes' => [
        ['code' => Code::taxes()->value('Sales Tax'), 'name' => 'Sales Tax', 'amount' => 30],
    ],

    'subtotal' => 500,         // required
    'grand_total' => 530,      // required
    'payable_total' => 530,    // required
    'payable_rounding' => 0,   // optional, already rounded to 2dp, e.g. round($payable - $grand, 2); omitted from the UBL when it rounds to 0/null

    'line_items' => [          // required, min 1
        [
            'qty' => 1,
            'uom' => Code::units('outfit'),
            'description' => '...',            // required
            'unit_price' => 500.00,            // required
            'country' => null,                 // country of origin, optional
            'classifications' => [             // required, min 1
                ['code' => Code::classifications()->value('Others')],
            ],
            'tariffs' => [
                ['code' => '22223334444'],
            ],
            'taxes' => [
                ['code' => Code::taxes()->value('Sales Tax'), 'name' => 'Sales Tax',
                 'amount' => 30, 'taxable_amount' => 470],
            ],
            'subtotal' => 500.00,              // required, numeric
            'discount' => ['amount' => 0, 'description' => null, 'rate' => null],
        ],
    ],
]
```

**Rounding adjustment.** `payable_rounding` is optional. Pass it already rounded to 2 decimal places, e.g. `round($payable - $grand, 2)`: the validator rejects more than 2 decimals, scientific notation and padded strings, and the builder rounds to 2dp as a safety net. A value that rounds to `0` (or is `null`/missing) sends nothing. `payable_total` must be supplied when `payable_rounding` is non-zero; otherwise `PayableAmount` falls back to `grand_total` and won't reflect the rounding.

### Validating before submission

`validator()` returns a Laravel `Validator` instance against the document shape — handy for surfacing user-friendly errors before paying for a rejected MyInvois call:

```php
$validator = app('myinvois')->validator($document);

if ($validator->fails()) {
    return back()->withErrors($validator);
}

$response = app('myinvois')->submitDocuments([$document]);
```

Pass `'sample'` to validate (or submit) the bundled test fixture:

```php
app('myinvois')->validator('sample');
app('myinvois')->submitDocuments('sample');
```

## Retrieving documents

```php
$myinvois = app('myinvois');

// Most recent documents (12/min rate-limited)
$myinvois->getRecentDocuments(['pageNo' => 1, 'pageSize' => 100]);

// Search (12/min)
$myinvois->searchDocuments([
    'submissionDateFrom' => '2026-01-01T00:00:00Z',
    'submissionDateTo' => '2026-01-31T23:59:59Z',
    'status' => 'Valid',
]);

// Single submission with all its documents (60/min)
// Also writes back to your local myinvois_documents rows.
$myinvois->getSubmission($submissionUid);

// Raw document JSON (60/min)
$myinvois->getDocument($uid);

// Document details — includes validation results and updates local row
$myinvois->getDocumentDetails($uid);
```

All retrieval methods return the raw decoded JSON response from MyInvois. `getSubmission` and `getDocumentDetails` additionally call `updateMyinvoisDocuments` to sync the local `myinvois_documents` row's `status` and `response` columns.

## Cancelling and rejecting

```php
// Cancel a document you've issued (within 72 hours of submission per LHDN rules)
app('myinvois')->cancelDocument($uid, reason: 'Wrong amount');

// Reject a document issued to you as the buyer
app('myinvois')->rejectDocument($uid, reason: 'Goods not received');
```

`cancelDocument` updates the local row's status to `cancelled` and stores the reason in `response`. The 72-hour window is enforced client-side via `MyinvoisDocument::isCancellable()`.

`rejectDocument` sends a `PUT` (like `cancelDocument`) and returns LHDN's JSON response. It does not touch the local `myinvois_documents` table: a rejected document is one issued to you, so there may be no local row (or only an inbound one), and the supplier still has to act on the rejection.

## Taxpayer TIN lookup

```php
// Search for a TIN by other identifier (60/min)
$tin = app('myinvois')->searchTaxpayerTIN(
    idType: 'BRN',                   // 'BRN' | 'NRIC' | 'PASSPORT' | 'ARMY'
    idValue: '202101001341',
    taxpayerName: 'JIANNIUS TECHNOLOGIES SDN. BHD.',
);

// Validate that a TIN + identifier pair is a real taxpayer
$ok = app('myinvois')->validateTaxpayerTIN('C26561325060', brn: '202101001341');
$ok = app('myinvois')->validateTaxpayerTIN('C26561325060', nric: '900101011234');
```

## Code helper

LHDN publishes static code tables (countries, states, currencies, MSIC industry codes, units of measure, taxes, classifications, payment modes, document types/versions). The SDK ships these as JSON in `json/codes/` and exposes them via `Jiannius\Myinvois\Helpers\Code`:

```php
use Jiannius\Myinvois\Helpers\Code;

Code::countries()->value('Malaysia');             // 'MYS'
Code::countries()->label('MYS');                  // 'MALAYSIA'
Code::states()->value('Wilayah Persekutuan KL');  // '14'
Code::currencies()->value('MYR');                 // 'MYR'
Code::classifications()->value('Others');         // '022'
Code::taxes()->value('Sales Tax');                // '01'
Code::msic()->get('46510');                       // ['Code' => '46510', 'Description' => '...']

Code::countries()->all();                         // full collection
```

`get($needle)` matches by either the code value or the human-readable label. `value($needle)` returns just the code, `label($needle)` returns just the description. The countries helper auto-uppercases input; the states helper auto-prefixes `Wilayah Persekutuan ` for KL/Labuan/Putrajaya.

## Tracking documents in your host app

The package's `MyinvoisDocument` Eloquent model is morph-related to whatever host model "owns" each invoice. Attach the `HasMyinvoisDocument` trait to that model:

```php
use Jiannius\Myinvois\Models\Traits\HasMyinvoisDocument;

class Invoice extends Model
{
    use HasMyinvoisDocument;
}
```

The trait adds:

- `myinvoisDocuments()` — `MorphMany` for non-preprod docs
- `preprodMyinvoisDocuments()` — `MorphMany` for preprod docs
- `latestMyinvoisDocument()` / `preprodLatestMyinvoisDocument()` — `MorphOne` (via `latestOfMany`)
- `isSubmittedToMyinvois($submitted = true, $preprod = false)` — boolean check
- `getMyinvoisValidationLink($preprod = false)` — share URL on the MyInvois portal
- `getMyinvoisQrCode($preprod = false)` — data-URI PNG of the QR for the validation link
- `scopeWithSubmittedMyinvoisDocument($submitted, $preprod)` — query scope
- Casts: `myinvois_status` and `myinvois_preprod_status` (both `Status` enum)

The trait registers an observer that auto-syncs `myinvois_status` / `myinvois_preprod_status` columns on the host model whenever a child `MyinvoisDocument` is saved (separated so preprod testing never overwrites prod state).

To wire up the parent relationship when submitting, just set `parent_type` / `parent_id` after submission (or extend `submitDocuments` in your own code to set them inline).

### Swapping the document model

By default, the trait and `Myinvois` itself use the package's own `Jiannius\Myinvois\Models\MyinvoisDocument`. Host apps that want to add custom scopes/methods can extend it and register their subclass from a service provider:

```php
// app/Models/MyinvoisDocument.php
namespace App\Models;

class MyinvoisDocument extends \Jiannius\Myinvois\Models\MyinvoisDocument
{
    public function invoice() { return $this->parent(); }   // typed accessor, scopes, whatever
}

// app/Providers/AppServiceProvider.php
public function boot(): void
{
    \Jiannius\Myinvois\Models\MyinvoisDocument::useModel(\App\Models\MyinvoisDocument::class);
}
```

Both the trait's morph relations and the SDK's internal `create()`/`query()` calls resolve through `MyinvoisDocument::$useModel`, so both stay consistent.

## Enums

### `Jiannius\Myinvois\Enums\Status`

```php
Status::SUBMITTED   // 'submitted'
Status::VALID       // 'valid'
Status::INVALID     // 'invalid'
Status::CANCELLED   // 'cancelled'

$status->color();   // blue | green | red | gray
$status->label();   // Submitted | Valid | Invalid | Cancelled
$status->code();    // 1 | 2 | 3 | 4
$status->is('valid', 'VALID', Status::VALID);  // multi-arg match
```

### `Jiannius\Myinvois\Enums\TinType`

Special TINs LHDN reserves for non-traditional buyers:

```php
TinType::GENERAL_PUBLIC      // EI00000000010
TinType::FOREIGN_BUYER       // EI00000000020
TinType::FOREIGN_SUPPLIER    // EI00000000030
TinType::GOVERNMENT          // EI00000000040
```

When a buyer's TIN matches one of these, the validator skips the BRN/NRIC/phone/address requirements. (`GENERAL_PUBLIC` cannot be used on standard non-consolidated invoices — only credit/debit/refund notes or consolidated invoices.)

## On-behalf-of (intermediary submissions)

If you're a tax service provider submitting on behalf of clients, set the target TIN before each call:

```php
app('myinvois')
    ->setOnBehalfOf($clientTin, $clientBrn)
    ->submitDocuments($documents);
```

The TIN goes into the OAuth `onbehalfof` header. If `$clientTin` matches `config('services.myinvois.client_tin')`, the SDK clears the header (you're submitting as yourself).

The intermediary `IG`-prefixed format requires both TIN and BRN, joined as `IG...:BRN` — handled automatically by `setOnBehalfOf`.

## Rate limiting

The SDK enforces the per-endpoint limits LHDN publishes, using Laravel's `RateLimiter`. When the local counter hits the cap, the call sleeps for `60 / perMinute` seconds and clears the counter rather than rejecting outright:

| Endpoint family | Limit (per minute) |
|---|---|
| `documentsubmissions` (submit), `documents/*/raw`, `documents/*/details`, taxpayer search, `documentsubmissions/{uid}` | 60 |
| `documents/recent`, `documents/search`, `documents/state/*/state` | 12 |

## Token caching

OAuth tokens are cached under `myinvois_<clientId>_<onBehalfOf>` for 50 minutes (MyInvois issues 60-minute tokens; the 10-minute buffer prevents edge-of-window failures). The cache is automatically invalidated on expiry.

## License

MIT — see [LICENSE.md](LICENSE.md).
