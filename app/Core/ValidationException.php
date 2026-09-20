<?php

declare(strict_types=1);

namespace App\Core;

/** Input failed validation. Carries per-field errors for form redisplay. */
class ValidationException extends AppException
{
    protected int $statusCode = 422;
    protected string $userMessage = 'Please correct the highlighted fields.';

    /** @param array<string,string> $errors */
    public function __construct(private array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
