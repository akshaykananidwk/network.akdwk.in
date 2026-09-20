<?php

declare(strict_types=1);

namespace App\Core;

/** No authenticated identity. */
class AuthException extends AppException
{
    protected int $statusCode = 401;
    protected string $userMessage = 'Authentication required.';
}
