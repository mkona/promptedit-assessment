<?php

namespace App\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    private const RETRYABLE_CODES = ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true) || $this->status >= 500;
    }
}
