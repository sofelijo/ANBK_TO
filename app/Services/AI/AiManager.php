<?php

namespace App\Services\AI;

use InvalidArgumentException;

class AiManager
{
    public function provider(): AiProvider
    {
        return match (config('ai.driver')) {
            'fake' => new FakeAiProvider,
            'gemini' => new GeminiAiProvider(
                apiKey: (string) config('ai.gemini.api_key'),
                modelName: (string) config('ai.gemini.model'),
                baseUrl: rtrim((string) config('ai.gemini.base_url'), '/'),
            ),
            default => throw new InvalidArgumentException('AI driver tidak didukung.'),
        };
    }

    public function costMicrousd(AiResponse $response): int
    {
        if (config('ai.driver') !== 'gemini') {
            return 0;
        }

        return (int) round(
            ($response->inputTokens * (float) config('ai.gemini.input_usd_per_million'))
            + ($response->outputTokens * (float) config('ai.gemini.output_usd_per_million')),
        );
    }
}
