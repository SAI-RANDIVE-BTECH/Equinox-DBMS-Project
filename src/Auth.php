<?php

declare(strict_types=1);

namespace Equinox;

final class Auth
{
    public static function user(): ?array
    {
        Env::load(dirname(__DIR__) . '/.env');
        $secret = Env::get('JWT_SECRET', 'secret');
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return null;
        }

        return Jwt::decode($matches[1], $secret);
    }
}
