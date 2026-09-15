<?php

declare(strict_types=1);

namespace App\Core;

/** An error that maps directly onto an HTTP status code. */
final class HttpException extends \RuntimeException
{
    public function __construct(private readonly int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = 'The page or record you asked for does not exist.'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Your role does not allow this action.'): self
    {
        return new self(403, $message);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be understood.',
            401 => 'Please sign in to continue.',
            403 => 'Your role does not allow this action.',
            404 => 'The page or record you asked for does not exist.',
            405 => 'That action does not accept this request method.',
            419 => 'Your session expired. Please try again.',
            default => 'Something went wrong.',
        };
    }
}
