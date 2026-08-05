<?php

namespace App\Jobs;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Models\ChatMessage;
use App\Services\AI\AiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Validator;
use Throwable;

class GenerateStudentChatReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $generationId,
        public readonly int $assistantMessageId,
    ) {}

    public function handle(AiManager $manager): void
    {
        $generation = AiGeneration::findOrFail($this->generationId);
        $message = ChatMessage::with('room.student')->findOrFail($this->assistantMessageId);
        $generation->update(['status' => AiGenerationStatus::Processing, 'error' => null]);
        $message->update(['status' => 'pending']);

        try {
            $student = $message->room->student;
            $recentMessages = $message->room->messages()
                ->where('id', '<', $message->id)
                ->where('status', 'completed')
                ->latest('id')
                ->limit((int) config('ai.chat_context_messages', 12))
                ->get()
                ->reverse()
                ->map(fn (ChatMessage $chatMessage): array => [
                    'role' => $chatMessage->sender_type,
                    'content' => $chatMessage->content,
                ])->values()->all();
            $learningHistory = $student->attempts()
                ->where('status', 'submitted')
                ->with(['assessment:id,title', 'competencyResults.competency:id,name'])
                ->latest('submitted_at')
                ->limit(3)
                ->get()
                ->map(fn ($attempt): array => [
                    'assessment' => $attempt->assessment->title,
                    'summary' => $attempt->summary,
                    'competencies' => $attempt->competencyResults->map(fn ($result): array => [
                        'name' => $result->competency->name,
                        'percentage' => (float) $result->percentage,
                    ])->all(),
                ])->all();
            $context = [
                'grade_level' => $student->grade_level,
                'recent_messages' => $recentMessages,
                'learning_history' => $learningHistory,
            ];
            $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $response = $manager->provider()->generateJson(
                <<<PROMPT
Anda adalah teman belajar AI untuk pelajar kelas {$student->grade_level}. Jawab dalam bahasa Indonesia yang ramah, jelas, dan maksimal 180 kata. Bantu memahami konsep, membuat rencana belajar, refleksi, kebiasaan baik, dan pengembangan diri. Ajukan paling banyak satu pertanyaan balik bila berguna.

Aturan wajib:
- Jangan memberikan diagnosis psikologis, label permanen, atau nasihat medis.
- Jangan meminta nama, alamat, nomor telepon, kata sandi, atau data pribadi.
- Jangan mengarang nilai maupun riwayat siswa di luar konteks.
- Jangan membantu menyontek atau memberikan kunci ujian aktif.
- Jika diminta latihan dari hasil try out, buat satu soal baru yang serupa pada konsep dan pola, bukan salinan. Jangan berikan jawaban sebelum siswa mencoba.
- Untuk masalah berbahaya atau krisis, arahkan siswa mencari orang dewasa tepercaya.
- Kembalikan JSON saja: {"reply":"..."}.

Konteks belajar anonim dan percakapan:
{$contextJson}
PROMPT,
                ['task' => 'student_chat', ...$context],
            );
            $reply = Validator::make($response->data, [
                'reply' => ['required', 'string', 'max:3000'],
            ])->validate()['reply'];

            $message->update(['status' => 'completed', 'content' => $reply]);
            $message->room->update(['last_message_at' => now()]);
            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => ['reply' => $reply, 'message_id' => $message->id],
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_microusd' => $manager->costMicrousd($response),
            ]);
        } catch (Throwable $exception) {
            $message->update([
                'status' => 'failed',
                'content' => 'Maaf, teman belajar AI sedang tidak tersedia. Coba kirim pesan lagi beberapa saat nanti.',
            ]);
            $generation->update([
                'status' => AiGenerationStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            throw $exception;
        }
    }
}
