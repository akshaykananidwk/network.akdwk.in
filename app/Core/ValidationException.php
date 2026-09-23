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

    /**
     * What to show the person who filled the form in.
     *
     * "Please correct the highlighted fields" is only useful when something is
     * highlighted. A super administrator creating a network got exactly that,
     * with nothing highlighted anywhere, because the failure was on
     * `tenant_id` — a field the form does not have and never had.
     *
     * An error the form cannot display has to be displayed somewhere, so the
     * messages themselves go in the flash. Where a field *is* on the form the
     * message appears twice, beside the field and at the top, which is a small
     * price for never again showing somebody a form with no way to find out
     * what is wrong with it.
     */
    public function userMessage(): string
    {
        $messages = array_values(array_unique(array_filter(
            array_map(static fn ($m): string => trim((string) $m), $this->errors)
        )));

        if ($messages === []) {
            return $this->userMessage;
        }

        if (count($messages) === 1) {
            return $messages[0];
        }

        return $this->userMessage . ' ' . implode(' ', $messages);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
