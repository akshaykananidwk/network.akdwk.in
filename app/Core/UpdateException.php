<?php

declare(strict_types=1);

namespace App\Core;

/** Raised by any step of the update pipeline; carries the failing step name. */
class UpdateException extends AppException
{
    protected string $userMessage = 'The update could not be completed.';

    public function __construct(string $message, private string $step = '')
    {
        parent::__construct($message);
    }

    public function step(): string
    {
        return $this->step;
    }
}
