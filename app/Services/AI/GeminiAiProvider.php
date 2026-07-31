<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiAiProvider implements AiProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelName,
        private readonly string $baseUrl,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function generateJson(string $prompt, array $context = []): AiResponse
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY belum dikonfigurasi.');
        }

        $task = $context['task'] ?? null;
        $creativeTask = in_array($task, ['question_variants', 'story_questions'], true);

        $response = Http::timeout(60)
            ->retry(2, 500)
            ->withHeader('x-goog-api-key', $this->apiKey)
            ->post("{$this->baseUrl}/models/{$this->modelName}:generateContent", [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => $creativeTask ? 0.7 : 0.2,
                    'maxOutputTokens' => match ($task) {
                        'story_questions' => 6000,
                        'question_variants' => 4000,
                        default => 500,
                    },
                ],
            ])
            ->throw()
            ->json();

        $text = data_get($response, 'candidates.0.content.parts.0.text');
        if (! is_string($text)) {
            throw new RuntimeException('Gemini tidak mengembalikan konten yang dapat dibaca.');
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new RuntimeException('Gemini tidak mengembalikan JSON yang valid.');
        }

        return new AiResponse(
            data: $data,
            inputTokens: (int) data_get($response, 'usageMetadata.promptTokenCount', 0),
            outputTokens: (int) data_get($response, 'usageMetadata.candidatesTokenCount', 0),
        );
    }
}
