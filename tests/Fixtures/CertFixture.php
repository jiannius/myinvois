<?php

namespace Jiannius\Myinvois\Tests\Fixtures;

/**
 * Generates a throwaway RSA keypair + self-signed X.509 certificate for
 * signature tests. Generated once per process and memoised so every test
 * shares the same key — RSA PKCS#1 v1.5 signing is deterministic for a
 * fixed key + payload, which lets the signature tests assert exact values.
 */
class CertFixture
{
    protected static ?array $cache = null;

    public static function make() : array
    {
        if (static::$cache) return static::$cache;

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $pkey = openssl_pkey_new($config);

        if ($pkey === false) {
            throw new \RuntimeException('openssl_pkey_new failed: '.openssl_error_string());
        }

        $dn = [
            'countryName' => 'MY',
            'organizationName' => 'Jiannius Test',
            'organizationalUnitName' => 'Engineering',
            'commonName' => 'Jiannius Test CA',
            'emailAddress' => 'ca@jiannius.test',
        ];

        $csr = openssl_csr_new($dn, $pkey, $config);
        $x509 = openssl_csr_sign($csr, null, $pkey, 365, $config, 1234567890);

        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($pkey, $privateKey, null, $config);

        return static::$cache = [
            'private_key' => $privateKey,
            'certificate' => $certificate,
        ];
    }

    public static function privateKey() : string
    {
        return static::make()['private_key'];
    }

    public static function certificate() : string
    {
        return static::make()['certificate'];
    }
}
