<?php

namespace App\Service\AiReview;

class LlmRequest
{
    /**
     * @param array<int, array{role:string, content:string|array}> $messages
     * @param array|null $jsonSchema JSON-schema describing the reply (used when the provider supports it)
     * @param array<int, string> $images data: URLs for vision calls
     */
    public function __construct(
        public array $messages,
        public ?array $jsonSchema = null,
        public int $maxTokens = 2000,
        public array $images = [],
        public bool $vision = false,
    ) {
    }
}
