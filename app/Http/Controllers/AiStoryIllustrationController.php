<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Models\Question;
use App\Services\AI\StoryIllustrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiStoryIllustrationController extends Controller
{
    public function store(
        Request $request,
        AiGeneration $generation,
        StoryIllustrationService $service,
    ): RedirectResponse {
        abort_unless(
            $generation->school_id === $request->user()->school_id
            && $generation->type === AiGenerationType::StoryQuestions
            && $generation->status === AiGenerationStatus::Completed,
            404,
        );

        $existing = AiGeneration::query()
            ->where('school_id', $request->user()->school_id)
            ->where('type', AiGenerationType::StoryIllustration)
            ->where('input_hash', hash('sha256', "story-illustration:{$generation->id}"))
            ->whereIn('status', [AiGenerationStatus::Pending, AiGenerationStatus::Processing, AiGenerationStatus::Completed])
            ->latest()
            ->first();

        if ($existing) {
            return back()->with('success', 'Ilustrasi untuk cerita ini sudah dibuat atau masih diproses.');
        }

        $dailyUsage = AiGeneration::query()
            ->where('requested_by', $request->user()->id)
            ->where('type', AiGenerationType::StoryIllustration)
            ->whereDate('created_at', today())
            ->count();

        if ($dailyUsage >= config('ai.daily_image_limit')) {
            throw ValidationException::withMessages([
                'illustration' => 'Kuota ilustrasi AI hari ini sudah habis.',
            ]);
        }

        $questionIds = data_get($generation->result_payload, 'question_ids', []);
        $questionCount = Question::query()
            ->where('school_id', $request->user()->school_id)
            ->whereIn('id', $questionIds)
            ->count();
        if ($questionCount === 0 || $questionCount !== count($questionIds)) {
            throw ValidationException::withMessages([
                'illustration' => 'Soal pada paket cerita tidak ditemukan.',
            ]);
        }

        $theme = (string) data_get($generation->request_payload, 'theme');
        $story = (string) data_get($generation->result_payload, 'story');
        $prompt = $this->prompt($theme, $story);
        $imageGeneration = AiGeneration::create([
            'school_id' => $generation->school_id,
            'requested_by' => $request->user()->id,
            'source_question_id' => $questionIds[0],
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => config('ai.driver') === 'fake' ? 'fake' : 'gemini',
            'model' => config('ai.driver') === 'fake' ? 'deterministic-svg' : config('ai.image.model'),
            'input_hash' => hash('sha256', "story-illustration:{$generation->id}"),
            'request_payload' => [
                'story_generation_id' => $generation->id,
                'question_ids' => $questionIds,
                'theme' => $theme,
                'prompt' => $prompt,
                'aspect_ratio' => '16:9',
                'image_size' => '1K',
            ],
        ]);

        try {
            $service->submit($imageGeneration);
        } catch (Throwable) {
            return back()->withErrors([
                'illustration' => 'Batch ilustrasi gagal dikirim. Periksa billing Gemini dan coba lagi.',
            ]);
        }

        return back()->with('success', config('ai.driver') === 'fake'
            ? 'Ilustrasi simulasi berhasil dibuat.'
            : 'Ilustrasi masuk Batch API hemat. Hasil akan muncul setelah pemrosesan selesai.');
    }

    private function prompt(string $theme, string $story): string
    {
        return <<<PROMPT
Buat satu ilustrasi edukatif rasio 16:9 untuk mendampingi soal cerita ANBK siswa Indonesia.

Tema: {$theme}
Cerita: {$story}

Tampilkan adegan utama cerita dengan komposisi bersih, ramah anak, inklusif, warna natural, dan detail yang membantu memahami konteks. Jangan tampilkan tulisan, huruf, angka, logo, watermark buatan, kunci jawaban, atau informasi tambahan yang tidak ada dalam cerita. Hindari elemen menakutkan dan stereotip. Gambar harus dapat dipakai bersama oleh seluruh soal dalam paket.
PROMPT;
    }
}
