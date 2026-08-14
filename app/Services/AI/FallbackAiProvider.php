<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Throwable;

class FallbackAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $primary,
        private readonly AiProvider $fallback,
    ) {}

    public function name(): string
    {
        return $this->primary->name();
    }

    public function model(): string
    {
        return $this->primary->model();
    }

    public function generateJson(string $prompt, array $context = []): AiResponse
    {
        try {
            return $this->primary->generateJson($prompt, $context);
        } catch (Throwable $exception) {
            Log::warning("AI Provider '{$this->primary->name()}' gagal ({$exception->getMessage()}). Pindah ke provider cadangan '{$this->fallback->name()}'.");

            return $this->fallback->generateJson($prompt, $context);
        }
    }
}
