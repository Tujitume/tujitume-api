<?php

namespace App\Service\Business\Milestone;

class FundReleaseResult
{
    public function __construct(public readonly bool $success, public readonly string $message)
    {

    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
