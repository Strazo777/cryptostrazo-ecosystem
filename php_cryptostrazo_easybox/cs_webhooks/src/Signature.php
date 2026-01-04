<?php
declare(strict_types=1);

namespace CryptoStrazo\Client;

final class Signature
{
    public static function verify(
        string $secret,
        int $timestamp,
        string $rawBody,
        string $receivedSignature,
        string $baseFormat = '{timestamp}.{body}'
    ): bool {
        $base = str_replace(['{timestamp}', '{body}'], [(string)$timestamp, $rawBody], $baseFormat);
        $expected = hash_hmac('sha256', $base, $secret);
        $sig = self::normalize($receivedSignature);
        return self::timingSafeEquals($expected, $sig);
    }

    public static function normalize(string $sig): string
    {
        $sig = trim($sig);
        if (str_starts_with($sig, 'v1=')) {
            $sig = substr($sig, 3);
        }
        return strtolower(trim($sig));
    }

    public static function timingSafeEquals(string $a, string $b): bool
    {
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        if (strlen($a) !== strlen($b)) return false;

        $res = 0;
        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($a[$i]) ^ ord($b[$i]);
        }
        return $res === 0;
    }
}
