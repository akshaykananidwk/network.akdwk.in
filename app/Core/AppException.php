<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base for every exception the application raises deliberately.
 *
 * The distinction that matters: getMessage() may contain sensitive detail and
 * belongs in the log only; userMessage() is always safe to render.
 */
class AppException extends \RuntimeException
{
    protected int $statusCode = 500;
    protected string $userMessage = 'Something went wrong. Please try again.';

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }
}
