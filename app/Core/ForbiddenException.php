<?php

declare(strict_types=1);

namespace App\Core;

/** Authenticated but not permitted — includes cross-tenant access attempts. */
class ForbiddenException extends AppException
{
    protected int $statusCode = 403;
    protected string $userMessage = 'You do not have permission to do that.';
}
