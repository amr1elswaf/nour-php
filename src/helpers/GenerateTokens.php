<?php
namespace Nour\helpers;

class GenerateTokens
{
    /**
     * توليد Token آمن بطول عشوائي (زوجي أو فردي)
     */
    public static function generate(int $length): string
    {
        if ($length <= 0) {
            throw new InvalidArgumentException('Length must be greater than 0');
        }
        return substr(
            bin2hex(random_bytes((int) ceil($length / 2))),
            0,
            $length
        );
    }
}
