<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GroqAiProvider implements AiProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $modelName = 'llama-3.3-70b-versatile',
        private readonly string $baseUrl = 'https://api.groq.com/openai/v1',
    ) {}

    public function name(): string
    {
        return 'groq';
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function generateJson(string $prompt, array $context = []): AiResponse
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('GROQ_API_KEY belum dikonfigurasi.');
        }

        $task = $context['task'] ?? null;
        $creativeTask = in_array($task, ['question_variants', 'story_questions'], true);

        $response = Http::timeout(60)
            ->retry(2, 500)
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->modelName,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => $prompt,
                ]],
                'temperature' => $creativeTask ? 0.7 : 0.2,
                'max_tokens' => match ($task) {
                    'story_questions' => 6000,
                    'question_variants' => 4000,
                    default => 500,
                },
            ])
            ->throw()
            ->json();

        $text = data_get($response, 'choices.0.message.content');
        if (! is_string($text)) {
            throw new RuntimeException('Groq tidak mengembalikan konten yang dapat dibaca.');
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new RuntimeException('Groq tidak mengembalikan JSON yang valid.');
        }

        return new AiResponse(
            data: $data,
            inputTokens: (int) data_get($response, 'usage.prompt_tokens', 0),
            outputTokens: (int) data_get($response, 'usage.completion_tokens', 0),
        );
    }
}
