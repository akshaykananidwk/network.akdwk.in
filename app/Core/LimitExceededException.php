<?php

declare(strict_types=1);

namespace App\Core;

/** Plan or quota limit reached. Carries an upgrade hint for the UI. */
class LimitExceededException extends AppException
{
    protected int $statusCode = 402;

    public function __construct(string $message, private string $upgradeHint = '')
    {
        parent::__construct($message);
        $this->userMessage = $message;
    }

    public function upgradeHint(): string
    {
        return $this->upgradeHint;
    }
}
