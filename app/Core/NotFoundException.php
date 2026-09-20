<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resource missing, or hidden from this tenant.
 *
 * A row that exists but belongs to someone else is reported as 404 rather than
 * 403: a 403 would confirm the id exists, which is itself a cross-tenant leak.
 */
class NotFoundException extends AppException
{
    protected int $statusCode = 404;
    protected string $userMessage = 'The requested resource was not found.';
}
