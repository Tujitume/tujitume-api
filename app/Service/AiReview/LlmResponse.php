<?php

namespace App\Service\AiReview;

class LlmResponse
{
    public function __construct(
        public string $text,
        public ?array $json,
        public int $tokensIn,
        public int $tokensOut,
        public ?string $finishReason,
        public string $provider,
        public string $model,
    ) {
    }
}
