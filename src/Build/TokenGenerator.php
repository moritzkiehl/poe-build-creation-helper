<?php

declare(strict_types=1);

namespace App\Build;

/**
 * Share slugs and edit tokens.
 *
 * A build has no owner and no login, so these two strings are the whole access
 * model: the slug is the address of a build, the edit token is permission to
 * change it. Both come from random_bytes, and the token is only ever stored as
 * a hash.
 *
 * The hash is a plain SHA-256 rather than a password hash on purpose. These are
 * 256-bit random tokens, not user-chosen secrets: there is nothing to guess, so
 * the slow hashing that protects weak passwords would only cost request time.
 */
final class TokenGenerator
{
    private const int SLUG_LENGTH = 22;
    private const string SLUG_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function shareSlug(): string
    {
        $alphabet = self::SLUG_ALPHABET;
        $max = \strlen($alphabet) - 1;

        $slug = '';
        for ($i = 0; $i < self::SLUG_LENGTH; ++$i) {
            $slug .= $alphabet[random_int(0, $max)];
        }

        return $slug;
    }

    public function editToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verify(string $token, string $hash): bool
    {
        return hash_equals($hash, $this->hash($token));
    }
}
