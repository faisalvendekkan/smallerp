<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Raised when user-supplied data fails a business rule.
 *
 * Carries the field name so the web layer can highlight the right input, and
 * a list for the cases -- payroll above all -- where reporting every problem
 * at once saves the user a dozen round trips.
 */
class ValidationException extends \RuntimeException
{
    /** @param string[] $errors */
    public function __construct(
        string $message,
        public readonly string $field = '',
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    /** @param string[] $errors */
    public static function withErrors(array $errors, string $message = 'Please correct the errors below'): self
    {
        return new self($message, '', $errors);
    }

    /** @return string[] */
    public function allMessages(): array
    {
        return $this->errors !== [] ? $this->errors : [$this->getMessage()];
    }
}
