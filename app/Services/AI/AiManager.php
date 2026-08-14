<?php

namespace App\Services\AI;

use InvalidArgumentException;

class AiManager
{
    public function provider(): AiProvider
    {
        $driver = config('ai.driver');

        if ($driver === 'fake') {
            return new FakeAiProvider;
        }

        if ($driver === 'groq') {
            return $this->groqProvider();
        }

        if ($driver === 'gemini') {
            $gemini = new GeminiAiProvider(
                apiKey: (string) config('ai.gemini.api_key'),
                modelName: (string) config('ai.gemini.model'),
                baseUrl: rtrim((string) config('ai.gemini.base_url'), '/'),
            );

            $groqApiKey = (string) config('ai.groq.api_key');
            if ($groqApiKey !== '') {
                return new FallbackAiProvider(
                    primary: $gemini,
                    fallback: $this->groqProvider(),
                );
            }

            return $gemini;
        }

        throw new InvalidArgumentException('AI driver tidak didukung.');
    }

    private function groqProvider(): GroqAiProvider
    {
        return new GroqAiProvider(
            apiKey: (string) config('ai.groq.api_key'),
            modelName: (string) config('ai.groq.model', 'llama-3.3-70b-versatile'),
            baseUrl: rtrim((string) config('ai.groq.base_url', 'https://api.groq.com/openai/v1'), '/'),
        );
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
