<?php

declare(strict_types=1);

namespace App\Core;

/** Rate limit tripped. retryAfter drives the Retry-After header. */
class RateLimitException extends AppException
{
    protected int $statusCode = 429;
    protected string $userMessage = 'Too many requests. Please slow down and try again shortly.';

    public function __construct(public readonly int $retryAfter, string $message = 'Rate limit exceeded')
    {
        parent::__construct($message);
    }
}
