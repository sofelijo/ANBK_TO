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
        $format = data_get($generation->request_payload, 'format', 'story');
        abort_unless(
            $generation->school_id === $request->user()->school_id
            && $generation->type === AiGenerationType::StoryQuestions
            && ($format === 'story' || data_get($generation->request_payload, 'use_illustration') === true)
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

        $sourceQuestion = Question::query()
            ->with(['competency.parent:id,code,name', 'competency.subject:id,code,name'])
            ->findOrFail($questionIds[0]);
        $theme = (string) data_get($generation->request_payload, 'theme');
        $content = $format === 'story'
            ? (string) data_get($generation->result_payload, 'story')
            : (string) data_get($generation->result_payload, 'visual_description');
        $subject = $sourceQuestion->competency->subject;
        $competency = $sourceQuestion->competency;
        $alt = $format === 'story'
            ? "Ilustrasi untuk soal cerita {$theme}"
            : "Ilustrasi untuk soal {$theme}";
        $prompt = $this->prompt(
            $theme,
            $content,
            $subject?->name ?? 'Umum',
            $competency->name,
            $format,
        );
        $imageGeneration = AiGeneration::create([
            'school_id' => $generation->school_id,
            'requested_by' => $request->user()->id,
            'source_question_id' => $questionIds[0],
            'type' => AiGenerationType::StoryIllustration,
            'status' => AiGenerationStatus::Pending,
            'provider' => config('ai.driver') === 'fake' ? 'fake' : 'image-router',
            'model' => config('ai.driver') === 'fake' ? 'deterministic-svg' : config('ai.cloudflare.image_model'),
            'input_hash' => hash('sha256', "story-illustration:{$generation->id}"),
            'request_payload' => [
                'story_generation_id' => $generation->id,
                'question_ids' => $questionIds,
                'theme' => $theme,
                'format' => $format,
                'subject' => $subject?->name,
                'competency' => $competency->name,
                'parent_competency' => $competency->parent?->name,
                'prompt' => $prompt,
                'aspect_ratio' => '16:9',
                'image_size' => '1K',
                'alt' => $alt,
                'visual_spec' => data_get($generation->result_payload, 'visual_spec'),
            ],
        ]);

        try {
            $service->submit($imageGeneration);
        } catch (Throwable) {
            return back()->withErrors([
                'illustration' => 'Ilustrasi gagal dibuat. Silakan coba lagi beberapa saat lagi.',
            ]);
        }

        $imageGeneration->refresh();

        return back()->with(
            'success',
            $imageGeneration->status === AiGenerationStatus::Completed
                ? 'Ilustrasi berhasil dibuat.'
                : 'Ilustrasi sedang diproses.',
        );
    }

    private function prompt(string $theme, string $content, string $subject, string $competency, string $format): string
    {
        $subjectDirection = str_contains(mb_strtolower($subject), 'matematika')
            ? 'Karena ini soal Matematika, tampilkan objek, kelompok, ukuran, pola, diagram, atau perbandingan secara teratur dan mudah diamati. Semua kuantitas visual harus persis sesuai deskripsi. Jangan menampilkan kunci jawaban.'
            : 'Gunakan detail visual yang membantu siswa memahami konteks tanpa memperlihatkan kunci jawaban.';
        $contentLabel = $format === 'story' ? 'Cerita' : 'Deskripsi visual';

        return <<<PROMPT
Buat satu ilustrasi edukatif rasio 16:9 untuk mendampingi soal TKA siswa Indonesia.

Mata pelajaran: {$subject}
Kompetensi atau subkompetensi: {$competency}
Tema: {$theme}
{$contentLabel}: {$content}

{$subjectDirection}

Tampilkan visual utama dalam format landscape lebar dengan komposisi bersih, ramah anak, inklusif, warna natural, dan detail yang membantu memahami soal. Hindari tulisan, logo, watermark buatan, elemen menakutkan, stereotip, dan informasi tambahan yang tidak diminta. Gambar harus dapat dipakai bersama oleh seluruh soal dalam paket.
PROMPT;
    }
}
