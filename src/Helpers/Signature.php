<?php

namespace Jiannius\Myinvois\Helpers;

class Signature
{
    public static function build($document, $privateKey, $certificate)
    {
        // 1. cert digest
        $certraw = self::getCertificateRawContent($certificate);
        $certdecode = base64_decode($certraw);
        $certhash = hash('sha256', $certdecode, true);
        $certdigest = base64_encode($certhash);

        // 2. doc digest
        $docjson = self::toJson($document);
        $dochash = hash('sha256', $docjson, true);
        $docdigest = base64_encode($dochash);

        // 4. sign the doc
        $signature = self::sign($docjson, $privateKey);
        $signtime = now()->toDateString().'T'.now()->format('H:i:sp');

        // 5. issuer and serial
        $certdata = openssl_x509_parse($certificate);
        $issuer = self::getIssuerName($certdata['issuer']);
        $serial = $certdata['serialNumber'];

        // 6. signed props
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionURI.0._', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.ID.0._', 'urn:oasis:names:specification:ubl:signature:1');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.ReferencedSignatureID.0._', 'urn:oasis:names:specification:ubl:signature:Invoice');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Id', 'signature');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.Target', 'signature');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.Id', 'id-xades-signed-props');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningTime.0._', $signtime);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.CertDigest.0.DigestMethod.0._', '');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.CertDigest.0.DigestMethod.0.Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.CertDigest.0.DigestValue.0._', $certdigest);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.IssuerSerial.0.X509IssuerName.0._', $issuer);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0.SignedProperties.0.SignedSignatureProperties.0.SigningCertificate.0.Cert.0.IssuerSerial.0.X509SerialNumber.0._', $serial);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.KeyInfo.0.X509Data.0.X509Certificate.0._', $certraw);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.KeyInfo.0.X509Data.0.X509SubjectName.0._', $issuer);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.KeyInfo.0.X509Data.0.X509IssuerSerial.0.X509IssuerName.0._', $issuer);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.KeyInfo.0.X509Data.0.X509IssuerSerial.0.X509SerialNumber.0._', $serial);

        // 7. signed props digest
        $prop = data_get($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.Object.0.QualifyingProperties.0');
        $propjson = self::toJson($prop);
        $prophash = hash('sha256', $propjson, true);
        $propdigest = base64_encode($prophash);

        // 8. populate ubl schema with remaining the data
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignatureValue.0._', $signature);
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.SignatureMethod.0._', '');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.SignatureMethod.0.Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.0.Id', 'id-doc-signed-data');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.0.URI', '');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.0.DigestMethod.0._', '');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.0.DigestMethod.0.Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.0.DigestValue.0._', $docdigest);    
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.Id', 'id-xades-signed-props');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.Type', 'http://uri.etsi.org/01903/v1.3.2#SignedProperties');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.URI', '#id-xades-signed-props');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.DigestMethod.0._', '');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.DigestMethod.0.Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        data_set($document, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignedInfo.0.Reference.1.DigestValue.0._', $propdigest);
        data_set($document, 'Invoice.0.Signature.0.ID.0._', 'urn:oasis:names:specification:ubl:signature:Invoice');
        data_set($document, 'Invoice.0.Signature.0.SignatureMethod.0._', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');

        return $document;
    }

    public static function getCertificateRawContent($certificate)
    {
        $content = str_replace("\r", '', $certificate);

        $lines = collect(explode("\n", $content))->filter();
        $lines->pull(0);
        $lines->pop();

        return $lines->join('');
    }

    public static function toJson($value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = str_replace(["\r", "\n"], '', $json);
        $json = mb_convert_encoding($json, 'UTF-8', 'ISO-8859-1');

        return $json;
    }

    public static function sign($docjson, $privateKey)
    {
        $privateKey = openssl_pkey_get_private($privateKey);

        openssl_sign($docjson, $sign, $privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($sign);
    }

    public static function getIssuerName($issuer)
    {
        return collect(['CN', 'E', 'OU', 'O', 'C'])
            ->map(fn ($key) => ($val = data_get($issuer, $key)) ? "$key=$val" : null)
            ->filter()
            ->join(', ');
    }
}
