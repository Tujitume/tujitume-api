<?php

namespace App\Service\AiReview;

interface LlmClient
{
    /** @throws LlmException */
    public function complete(LlmRequest $request): LlmResponse;

    public function provider(): string;

    public function model(): string;
}
