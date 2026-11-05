<?php

namespace App\Exceptions;

use Exception;

final class BookingValidationException extends Exception
{
    public function __construct(protected array $errors)
    {
        parent::__construct("Ошибка валидации");
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
