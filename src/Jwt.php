<?php

declare(strict_types=1);

namespace Equinox;

final class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($payload));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        return $header . '.' . $body . '.' . $signature;
    }

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $header . '.' . $body, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::b64Decode($body), true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && time() > (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function b64Decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
