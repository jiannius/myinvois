<?php

namespace Jiannius\Myinvois\Tests\Feature;

use Illuminate\Support\Carbon;
use Jiannius\Myinvois\Enums\Status;
use Jiannius\Myinvois\Models\MyinvoisDocument;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MyinvoisDocumentTest extends TestCase
{
    #[Test]
    public function it_casts_status_preprod_and_json_columns() : void
    {
        $doc = MyinvoisDocument::create([
            'status' => 'valid',
            'is_preprod' => 1,
            'request' => ['a' => 1],
            'response' => ['b' => 2],
        ]);

        $doc->refresh();

        $this->assertInstanceOf(Status::class, $doc->status);
        $this->assertSame(Status::VALID, $doc->status);
        $this->assertTrue($doc->is_preprod);
        $this->assertSame(['a' => 1], $doc->request);
        $this->assertSame(['b' => 2], $doc->response);
    }

    #[Test]
    public function validation_link_is_empty_without_a_long_id() : void
    {
        $doc = MyinvoisDocument::create(['document_uuid' => 'U1', 'response' => []]);

        $this->assertSame('', $doc->validation_link);
    }

    #[Test]
    public function validation_link_uses_the_production_host() : void
    {
        $doc = MyinvoisDocument::create([
            'document_uuid' => 'U1',
            'is_preprod' => false,
            'response' => ['longId' => 'LONG1'],
        ]);

        $this->assertSame('https://myinvois.hasil.gov.my/U1/share/LONG1', $doc->validation_link);
    }

    #[Test]
    public function validation_link_uses_the_preprod_host() : void
    {
        $doc = MyinvoisDocument::create([
            'document_uuid' => 'U1',
            'is_preprod' => true,
            'response' => ['longId' => 'LONG1'],
        ]);

        $this->assertSame('https://preprod.myinvois.hasil.gov.my/U1/share/LONG1', $doc->validation_link);
    }

    #[Test]
    public function preprod_and_status_scopes_filter_rows() : void
    {
        MyinvoisDocument::create(['status' => 'valid', 'is_preprod' => false]);
        MyinvoisDocument::create(['status' => 'invalid', 'is_preprod' => false]);
        MyinvoisDocument::create(['status' => 'valid', 'is_preprod' => true]);

        $this->assertSame(2, MyinvoisDocument::query()->preprod(false)->count());
        $this->assertSame(1, MyinvoisDocument::query()->preprod(true)->count());
        $this->assertSame(2, MyinvoisDocument::query()->status('valid')->count());
        $this->assertSame(3, MyinvoisDocument::query()->status(['valid', 'invalid'])->count());
        // null status is a no-op
        $this->assertSame(3, MyinvoisDocument::query()->status(null)->count());
    }

    #[Test]
    public function it_extracts_nested_validation_errors() : void
    {
        $doc = MyinvoisDocument::create(['response' => [
            'validationResults' => [
                'validationSteps' => [
                    ['error' => ['innerError' => [['error' => 'E1'], ['error' => 'E2']]]],
                    ['status' => 'Valid'],
                ],
            ],
        ]]);

        $this->assertSame(['E1', 'E2'], $doc->getErrors());
    }

    #[Test]
    public function a_valid_document_inside_72_hours_is_cancellable() : void
    {
        $doc = MyinvoisDocument::create(['status' => 'valid']);

        $this->assertTrue($doc->isCancellable());
    }

    #[Test]
    public function a_valid_document_past_72_hours_is_not_cancellable() : void
    {
        $doc = MyinvoisDocument::create(['status' => 'valid']);
        $doc->created_at = Carbon::now()->subDays(4);
        $doc->save();

        $this->assertFalse($doc->fresh()->isCancellable());
    }

    #[Test]
    public function a_non_valid_document_is_not_cancellable() : void
    {
        $doc = MyinvoisDocument::create(['status' => 'submitted']);

        $this->assertFalse($doc->isCancellable());
    }

    #[Test]
    public function use_model_defaults_to_the_package_model() : void
    {
        $this->assertSame(MyinvoisDocument::class, MyinvoisDocument::$useModel);
    }
}
