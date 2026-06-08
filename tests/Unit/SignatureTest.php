<?php

namespace Jiannius\Myinvois\Tests\Unit;

use Illuminate\Support\Carbon;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Tests\Fixtures\CertFixture;
use Jiannius\Myinvois\Tests\Fixtures\DocumentFixture;
use Jiannius\Myinvois\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SignatureTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-01 12:00:00');
    }

    protected function tearDown() : void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helper methods ------------------------------------------------

    #[Test]
    public function it_strips_the_pem_header_footer_and_newlines_from_the_cert() : void
    {
        $cert = CertFixture::certificate();
        $raw = Signature::getCertificateRawContent($cert);

        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $raw);
        $this->assertStringNotContainsString('END CERTIFICATE', $raw);
        $this->assertStringNotContainsString("\n", $raw);
        // round-trips to the DER bytes that openssl can parse
        $this->assertNotFalse(base64_decode($raw, true));
    }

    #[Test]
    public function to_json_leaves_slashes_unescaped_and_strips_newlines() : void
    {
        $json = Signature::toJson(['uri' => "a/b\nc", 'name' => 'plain']);

        $this->assertStringContainsString('a/b', $json);    // slash not escaped to \/
        $this->assertStringNotContainsString('\u', $json);  // JSON_UNESCAPED_UNICODE
        $this->assertStringNotContainsString("\n", $json);  // newlines stripped
    }

    #[Test]
    public function get_issuer_name_orders_and_skips_missing_components() : void
    {
        $this->assertSame(
            'CN=A, E=e@x.com, OU=U, O=Org, C=MY',
            Signature::getIssuerName(['CN' => 'A', 'E' => 'e@x.com', 'OU' => 'U', 'O' => 'Org', 'C' => 'MY']),
        );

        $this->assertSame('CN=A, C=MY', Signature::getIssuerName(['CN' => 'A', 'C' => 'MY']));
    }

    #[Test]
    public function sign_produces_a_verifiable_rsa_sha256_signature() : void
    {
        $payload = '{"hello":"world"}';
        $signature = Signature::sign($payload, CertFixture::privateKey());

        $public = openssl_pkey_get_public(CertFixture::certificate());
        $verified = openssl_verify($payload, base64_decode($signature), $public, OPENSSL_ALGO_SHA256);

        $this->assertSame(1, $verified);
    }

    // ---- full build ----------------------------------------------------

    #[Test]
    public function build_populates_a_consistent_xades_signature() : void
    {
        $doc = UBL::build(DocumentFixture::invoice());

        // the document digest is taken over the pre-signature JSON
        $docjson = Signature::toJson($doc);
        $expectedDocDigest = base64_encode(hash('sha256', $docjson, true));

        $signed = Signature::build($doc, CertFixture::privateKey(), CertFixture::certificate());

        $sig = 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.';

        // document digest reference
        $this->assertSame(
            $expectedDocDigest,
            data_get($signed, $sig.'SignedInfo.0.Reference.0.DigestValue.0._'),
        );

        // signature value verifies against the document JSON
        $signatureValue = data_get($signed, $sig.'SignatureValue.0._');
        $public = openssl_pkey_get_public(CertFixture::certificate());
        $this->assertSame(1, openssl_verify($docjson, base64_decode($signatureValue), $public, OPENSSL_ALGO_SHA256));

        // cert digest = sha256 of the decoded raw cert
        $raw = Signature::getCertificateRawContent(CertFixture::certificate());
        $expectedCertDigest = base64_encode(hash('sha256', base64_decode($raw), true));
        $this->assertSame(
            $expectedCertDigest,
            data_get($signed, $sig.'Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.CertDigest.0.DigestValue.0._'),
        );

        // signed-properties digest matches a fresh hash of QualifyingProperties.0
        $prop = data_get($signed, $sig.'Object.0.QualifyingProperties.0');
        $expectedPropDigest = base64_encode(hash('sha256', Signature::toJson($prop), true));
        $this->assertSame(
            $expectedPropDigest,
            data_get($signed, $sig.'SignedInfo.0.Reference.1.DigestValue.0._'),
        );
    }

    #[Test]
    public function build_sets_the_issuer_serial_and_top_level_signature_block() : void
    {
        $doc = UBL::build(DocumentFixture::invoice());
        $signed = Signature::build($doc, CertFixture::privateKey(), CertFixture::certificate());

        $certdata = openssl_x509_parse(CertFixture::certificate());
        $expectedIssuer = Signature::getIssuerName($certdata['issuer']);

        $sig = 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.';

        $this->assertSame(
            $expectedIssuer,
            data_get($signed, $sig.'KeyInfo.0.X509Data.0.X509IssuerSerial.0.X509IssuerName.0._'),
        );
        $this->assertSame(
            (string) $certdata['serialNumber'],
            (string) data_get($signed, $sig.'KeyInfo.0.X509Data.0.X509IssuerSerial.0.X509SerialNumber.0._'),
        );

        // top-level Signature pointer block
        $this->assertSame(
            'urn:oasis:names:specification:ubl:signature:Invoice',
            data_get($signed, 'Invoice.0.Signature.0.ID.0._'),
        );
        $this->assertSame(
            'urn:oasis:names:specification:ubl:dsig:enveloped:xades',
            data_get($signed, 'Invoice.0.Signature.0.SignatureMethod.0._'),
        );
    }
}
