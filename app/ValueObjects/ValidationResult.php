<?php

namespace App\ValueObjects;

readonly class ValidationResult
{
    public function __construct(
        public bool  $isValid,
        public array $errors = [] // Формат: ['field' => 'message']
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(array $errors): self
    {
        return new self(false, $errors);
    }
}
