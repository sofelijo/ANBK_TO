<?php

namespace App\Services;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\AttemptStatus;
use App\Jobs\GenerateStudentChatReply;
use App\Models\AiGeneration;
use App\Models\Attempt;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use App\Services\AI\AiManager;
use Illuminate\Support\Facades\DB;

class StudentChatService
{
    public function roomFor(User $student): ChatRoom
    {
        return ChatRoom::firstOrCreate(
            ['student_id' => $student->id],
            ['school_id' => $student->school_id],
        );
    }

    public function hasActiveAttempt(User $student): bool
    {
        return $student->attempts()
            ->where('status', AttemptStatus::InProgress)
            ->with('assessment')
            ->get()
            ->contains(fn (Attempt $attempt): bool => ! $attempt->isExpired());
    }

    public function dailyMessageCount(User $student): int
    {
        return ChatMessage::query()
            ->where('sender_id', $student->id)
            ->where('sender_type', 'student')
            ->whereDate('created_at', today())
            ->count();
    }

    public function postAttemptSummary(Attempt $attempt): ChatMessage
    {
        $attempt->loadMissing('assessment');
        $room = $this->roomFor($attempt->student()->firstOrFail());
        $message = $room->messages()->updateOrCreate(
            ['source_key' => "attempt-summary:{$attempt->id}"],
            [
                'attempt_id' => $attempt->id,
                'sender_type' => 'assistant',
                'type' => 'attempt_summary',
                'status' => 'completed',
                'content' => $attempt->summary,
                'metadata' => [
                    'assessment_title' => $attempt->assessment->title,
                    'score' => (float) $attempt->score,
                    'max_score' => (float) $attempt->max_score,
                ],
            ],
        );
        $room->update(['last_message_at' => now()]);

        return $message;
    }

    public function requestPracticeFor(Attempt $attempt, AiManager $manager): ChatRoom
    {
        $attempt->loadMissing([
            'student',
            'assessment',
            'competencyResults.competency',
            'answers.question',
        ]);
        $room = $this->roomFor($attempt->student);
        $requestKey = "attempt-practice-request:{$attempt->id}";

        if ($room->messages()->where('source_key', $requestKey)->exists()) {
            return $room;
        }

        $weakest = $attempt->competencyResults->sortBy('percentage')->first();
        $competencyName = $weakest?->competency?->name ?? 'materi dengan capaian terendah';
        $wrongPrompts = $attempt->answers
            ->where('is_correct', false)
            ->filter(fn ($answer): bool => ! $weakest || $answer->question?->competency_id === $weakest->competency_id)
            ->pluck('question.prompt')
            ->filter()
            ->take(2)
            ->values();
        $pattern = $wrongPrompts->isEmpty()
            ? 'Gunakan pola materi dari hasil try out saya.'
            : "Gunakan pola materi berikut sebagai acuan, tetapi jangan menyalin:\n- ".$wrongPrompts->implode("\n- ");
        $prompt = "Buatkan satu contoh soal latihan baru untuk kompetensi {$competencyName}, yaitu pelajaran yang paling banyak saya salah pada try out {$attempt->assessment->title}. {$pattern} Jangan langsung berikan jawaban atau pembahasan. Tunggu sampai saya mencoba menjawab.";

        DB::transaction(function () use ($attempt, $manager, $prompt, $requestKey, $room, $competencyName): void {
            $room->messages()->create([
                'sender_id' => $attempt->user_id,
                'attempt_id' => $attempt->id,
                'sender_type' => 'student',
                'type' => 'chat',
                'status' => 'completed',
                'source_key' => $requestKey,
                'content' => $prompt,
            ]);

            $provider = $manager->provider();
            $generation = AiGeneration::create([
                'school_id' => $attempt->student->school_id,
                'requested_by' => $attempt->user_id,
                'attempt_id' => $attempt->id,
                'type' => AiGenerationType::StudentChat,
                'status' => AiGenerationStatus::Pending,
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'input_hash' => hash('sha256', $requestKey),
                'request_payload' => [
                    'room_id' => $room->id,
                    'attempt_id' => $attempt->id,
                    'competency' => $competencyName,
                    'message' => $prompt,
                ],
            ]);
            $assistantMessage = $room->messages()->create([
                'attempt_id' => $attempt->id,
                'ai_generation_id' => $generation->id,
                'sender_type' => 'assistant',
                'type' => 'chat',
                'status' => 'pending',
                'source_key' => "attempt-practice-reply:{$attempt->id}",
            ]);
            $room->update(['last_message_at' => now()]);
            GenerateStudentChatReply::dispatchAfterResponse($generation->id, $assistantMessage->id);
        });

        return $room;
    }

    public function sensitiveResponse(string $message): ?string
    {
        $normalized = mb_strtolower($message);
        foreach (['bunuh diri', 'mengakhiri hidup', 'menyakiti diri', 'tidak ingin hidup'] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'Aku ikut prihatin kamu sedang menghadapi hal yang berat. Tolong segera ceritakan ini kepada orang dewasa yang kamu percaya, seperti orang tua, wali, guru, atau konselor sekolah. Jika kamu merasa dalam bahaya sekarang, jangan sendirian dan segera minta bantuan langsung dari orang di sekitarmu.';
            }
        }

        return null;
    }
}
