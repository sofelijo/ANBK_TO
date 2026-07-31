<?php

namespace App\Services\AI;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoryIllustrationService
{
    public function submit(AiGeneration $generation): void
    {
        if (config('ai.driver') === 'fake') {
            $this->completeFake($generation);

            return;
        }

        try {
            $apiKey = (string) config('ai.gemini.api_key');
            if ($apiKey === '') {
                throw new RuntimeException('GEMINI_API_KEY belum dikonfigurasi.');
            }

            $model = (string) config('ai.image.model');
            $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
            $response = Http::timeout(30)
                ->withHeader('x-goog-api-key', $apiKey)
                ->post("{$baseUrl}/models/{$model}:batchGenerateContent", [
                    'batch' => [
                        'display_name' => "story-illustration-{$generation->id}",
                        'input_config' => [
                            'requests' => [
                                'requests' => [[
                                    'request' => [
                                        'contents' => [[
                                            'role' => 'user',
                                            'parts' => [['text' => data_get($generation->request_payload, 'prompt')]],
                                        ]],
                                        'generationConfig' => [
                                            'responseModalities' => ['IMAGE'],
                                            'imageConfig' => [
                                                'aspectRatio' => '16:9',
                                                'imageSize' => '1K',
                                            ],
                                        ],
                                    ],
                                    'metadata' => ['generation_id' => $generation->id],
                                ]],
                            ],
                        ],
                    ],
                ])
                ->throw()
                ->json();

            $batchName = data_get($response, 'name') ?? data_get($response, 'batch.name');
            if (! is_string($batchName) || $batchName === '') {
                throw new RuntimeException('Gemini tidak mengembalikan ID batch gambar.');
            }

            $generation->update([
                'status' => AiGenerationStatus::Processing,
                'result_payload' => [
                    'batch_name' => $batchName,
                    'batch_state' => data_get($response, 'metadata.state', 'JOB_STATE_PENDING'),
                    'last_checked_at' => now()->toIso8601String(),
                ],
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->fail($generation, $exception->getMessage());
            throw $exception;
        }
    }

    public function refresh(AiGeneration $generation): void
    {
        if ($generation->status !== AiGenerationStatus::Processing || config('ai.driver') === 'fake') {
            return;
        }

        try {
            $batchName = data_get($generation->result_payload, 'batch_name');
            if (! is_string($batchName) || $batchName === '') {
                throw new RuntimeException('ID batch gambar tidak ditemukan.');
            }

            $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
            $response = Http::timeout(30)
                ->retry(2, 500)
                ->withHeader('x-goog-api-key', (string) config('ai.gemini.api_key'))
                ->get("{$baseUrl}/{$batchName}")
                ->throw()
                ->json();

            $state = data_get($response, 'metadata.state') ?? data_get($response, 'state');
            $payload = [
                ...($generation->result_payload ?? []),
                'batch_state' => $state,
                'last_checked_at' => now()->toIso8601String(),
            ];
            $generation->update(['result_payload' => $payload]);

            if (in_array($state, ['JOB_STATE_FAILED', 'JOB_STATE_CANCELLED', 'JOB_STATE_EXPIRED'], true) || data_get($response, 'error')) {
                $message = data_get($response, 'error.message', "Batch gambar berakhir dengan status {$state}.");
                $this->fail($generation, (string) $message);

                return;
            }

            $done = data_get($response, 'done') === true;
            if ($state !== 'JOB_STATE_SUCCEEDED' && ! $done) {
                return;
            }

            $this->storeCompletedImage($generation, $response);
        } catch (Throwable $exception) {
            $this->fail($generation, $exception->getMessage());
        }
    }

    public function shouldRefresh(AiGeneration $generation): bool
    {
        $lastCheckedAt = data_get($generation->result_payload, 'last_checked_at');

        return ! is_string($lastCheckedAt) || now()->diffInSeconds($lastCheckedAt) >= 15;
    }

    private function storeCompletedImage(AiGeneration $generation, array $response): void
    {
        $inlineResponses = $this->inlineResponses($response, [
            'response.inlinedResponses',
            'response.inlinedResponses.inlinedResponses',
            'output.inlinedResponses.inlinedResponses',
            'dest.inlinedResponses',
        ]);
        $inlineResponse = $inlineResponses[0] ?? null;
        $error = data_get($inlineResponse, 'error.message');
        if (is_string($error) && $error !== '') {
            throw new RuntimeException($error);
        }

        $parts = data_get($inlineResponse, 'response.candidates.0.content.parts', []);
        $imagePart = collect(is_array($parts) ? $parts : [])->first(
            fn (array $part): bool => is_string(data_get($part, 'inlineData.data'))
                || is_string(data_get($part, 'inline_data.data')),
        );
        $encodedImage = data_get($imagePart, 'inlineData.data') ?? data_get($imagePart, 'inline_data.data');
        $mimeType = data_get($imagePart, 'inlineData.mimeType') ?? data_get($imagePart, 'inline_data.mime_type');

        if (! is_string($encodedImage) || ! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Batch selesai tetapi tidak mengembalikan gambar yang valid.');
        }

        $image = base64_decode($encodedImage, true);
        if ($image === false || strlen($image) > 15 * 1024 * 1024) {
            throw new RuntimeException('Data gambar hasil Gemini tidak valid atau terlalu besar.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.{$extension}";
        if (! Storage::disk($disk)->put($path, $image)) {
            throw new RuntimeException('Gambar tidak dapat disimpan ke storage.');
        }

        $usage = data_get($inlineResponse, 'response.usageMetadata', []);
        $this->complete($generation, $disk, $path, $mimeType, [
            'input_tokens' => (int) data_get($usage, 'promptTokenCount', 0),
            'output_tokens' => (int) data_get($usage, 'candidatesTokenCount', 1120),
        ]);
    }

    private function completeFake(AiGeneration $generation): void
    {
        $theme = htmlspecialchars((string) data_get($generation->request_payload, 'theme'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#ecfdf5"/>
  <circle cx="180" cy="150" r="72" fill="#fbbf24"/>
  <path d="M0 430 Q220 320 430 430 T1024 410 V576 H0Z" fill="#34d399"/>
  <path d="M0 490 Q250 390 510 490 T1024 470 V576 H0Z" fill="#059669"/>
  <rect x="220" y="210" width="584" height="150" rx="28" fill="#ffffff" opacity=".92"/>
  <text x="512" y="275" text-anchor="middle" font-family="sans-serif" font-size="30" font-weight="700" fill="#064e3b">Ilustrasi Soal Cerita</text>
  <text x="512" y="322" text-anchor="middle" font-family="sans-serif" font-size="24" fill="#047857">{$theme}</text>
</svg>
SVG;
        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.svg";
        Storage::disk($disk)->put($path, $svg);
        $this->complete($generation, $disk, $path, 'image/svg+xml', [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ]);
    }

    private function complete(AiGeneration $generation, string $disk, string $path, string $mimeType, array $usage): void
    {
        $questionIds = data_get($generation->request_payload, 'question_ids', []);
        $alt = 'Ilustrasi untuk soal cerita '.data_get($generation->request_payload, 'theme');

        DB::transaction(function () use ($generation, $questionIds, $disk, $path, $mimeType, $alt, $usage): void {
            Question::query()
                ->where('school_id', $generation->school_id)
                ->whereIn('id', $questionIds)
                ->get()
                ->each(function (Question $question) use ($generation, $disk, $path, $mimeType, $alt): void {
                    $question->update(['metadata' => [
                        ...($question->metadata ?? []),
                        'illustration' => [
                            'generation_id' => $generation->id,
                            'disk' => $disk,
                            'path' => $path,
                            'mime_type' => $mimeType,
                            'alt' => $alt,
                        ],
                    ]]);
                });

            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => [
                    ...($generation->result_payload ?? []),
                    'image_disk' => $disk,
                    'image_path' => $path,
                    'mime_type' => $mimeType,
                    'batch_state' => 'JOB_STATE_SUCCEEDED',
                    'completed_at' => now()->toIso8601String(),
                ],
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cost_microusd' => config('ai.driver') === 'fake'
                    ? 0
                    : (int) config('ai.image.batch_cost_microusd'),
                'error' => null,
            ]);
        });
    }

    private function fail(AiGeneration $generation, string $message): void
    {
        $generation->update([
            'status' => AiGenerationStatus::Failed,
            'error' => mb_substr($message, 0, 5000),
        ]);
    }

    private function inlineResponses(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);

            if (is_array($value) && isset($value['inlinedResponses']) && is_array($value['inlinedResponses'])) {
                $value = $value['inlinedResponses'];
            }

            if (is_array($value) && $value !== [] && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }
}
