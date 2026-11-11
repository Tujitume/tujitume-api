<?php

namespace App\Service\AiReview;

use RuntimeException;

/** No provider can take the call now. wait = seconds until it may work (null = it will not by itself). */
class AiUnavailableException extends RuntimeException
{
    public function __construct(public ?int $wait, public string $reason, public bool $authError = false, public ?string $provider = null)
    {
        parent::__construct("AI unavailable: {$reason}");
    }
}
