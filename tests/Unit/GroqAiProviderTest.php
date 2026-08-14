<?php

namespace Tests\Unit;

use App\Services\AI\AiProvider;
use App\Services\AI\AiResponse;
use App\Services\AI\FallbackAiProvider;
use App\Services\AI\GroqAiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GroqAiProviderTest extends TestCase
{
    public function test_groq_ai_provider_generates_json(): void
    {
        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(['title' => 'Test Soal Groq']),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 150,
                    'completion_tokens' => 300,
                ],
            ]),
        ]);

        $provider = new GroqAiProvider(apiKey: 'gsk_fake_key_123');
        $response = $provider->generateJson('Buat JSON');

        $this->assertSame('groq', $provider->name());
        $this->assertSame('llama-3.3-70b-versatile', $provider->model());
        $this->assertSame(['title' => 'Test Soal Groq'], $response->data);
        $this->assertSame(150, $response->inputTokens);
        $this->assertSame(300, $response->outputTokens);
    }

    public function test_fallback_ai_provider_switches_when_primary_fails(): void
    {
        $primary = new class implements AiProvider {
            public function name(): string { return 'failing-primary'; }
            public function model(): string { return 'fail-v1'; }
            public function generateJson(string $prompt, array $context = []): AiResponse {
                throw new RuntimeException('429 Rate limit exceeded');
            }
        };

        $fallback = new class implements AiProvider {
            public function name(): string { return 'groq-backup'; }
            public function model(): string { return 'llama-3.3-70b'; }
            public function generateJson(string $prompt, array $context = []): AiResponse {
                return new AiResponse(data: ['status' => 'fallback-success'], inputTokens: 50, outputTokens: 50);
            }
        };

        $provider = new FallbackAiProvider($primary, $fallback);
        $response = $provider->generateJson('Buat JSON');

        $this->assertSame(['status' => 'fallback-success'], $response->data);
    }
}
