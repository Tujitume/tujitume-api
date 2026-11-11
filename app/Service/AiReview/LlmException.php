<?php

namespace App\Service\AiReview;

use RuntimeException;

class LlmException extends RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';
    public const QUOTA_EXHAUSTED = 'quota_exhausted';
    public const SERVER_ERROR = 'server_error';
    public const BAD_REQUEST = 'bad_request';
    public const AUTH_ERROR = 'auth_error';
    public const MODEL_NOT_FOUND = 'model_not_found';

    public function __construct(public string $kind, string $message = '', public ?int $retryAfter = null, public ?int $status = null)
    {
        parent::__construct($message !== '' ? $message : $kind);
    }

    /** Errors where another attempt (or another provider) may work. */
    public function retryable(): bool
    {
        return in_array($this->kind, [self::RATE_LIMITED, self::QUOTA_EXHAUSTED, self::SERVER_ERROR, self::MODEL_NOT_FOUND], true);
    }
}
