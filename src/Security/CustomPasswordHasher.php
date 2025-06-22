<?php

namespace App\Security;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class CustomPasswordHasher implements PasswordHasherInterface
{
    public function hash(string $password): string 
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $hash_password, string $plain_password): bool
    {
        return password_verify($plain_password, $hash_password);
    }

    public function needsRehash(string $password): bool
    {
        return false;
    }
}
