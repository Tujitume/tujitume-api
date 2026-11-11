<?php

namespace Tests\Feature\Programs\AiReview;

use App\Service\AiReview\LlmClient;
use App\Service\AiReview\LlmException;
use App\Service\AiReview\LlmRequest;
use App\Service\AiReview\LlmResponse;

/** Scripted stand-in for a provider. Each queued item is a JSON array, an LlmException, or a closure(LlmRequest). */
class FakeLlmClient implements LlmClient
{
    public array $queue = [];
    public array $requests = [];
    public $default = null;

    public function __construct(private string $name = 'groq', private string $modelName = 'fake-model')
    {
    }

    public function provider(): string { return $this->name; }

    public function model(): string { return $this->modelName; }

    public function push(mixed ...$items): static
    {
        foreach ($items as $item) $this->queue[] = $item;

        return $this;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;
        $item = $this->queue ? array_shift($this->queue) : $this->default;
        if ($item instanceof \Closure) $item = $item($request);
        if ($item instanceof LlmException) throw $item;
        if (is_string($item)) return new LlmResponse($item, null, 100, 50, 'stop', $this->name, $this->modelName);
        if ($item === null) throw new LlmException(LlmException::SERVER_ERROR, 'no scripted reply');

        return new LlmResponse(json_encode($item), $item, 100, 50, 'stop', $this->name, $this->modelName);
    }
}
