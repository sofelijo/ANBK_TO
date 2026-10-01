<?php

namespace App\Http\Controllers;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Jobs\GenerateStudentChatReply;
use App\Models\AiGeneration;
use App\Models\ChatMessage;
use App\Notifications\ActionNotification;
use App\Services\AI\AiManager;
use App\Services\NotificationAudience;
use App\Services\StudentChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentChatController extends Controller
{
    public function show(Request $request, StudentChatService $chatService): Response
    {
        $room = $chatService->roomFor($request->user());

        return Inertia::render('Chat/StudentShow', [
            'room' => ['id' => $room->id],
            'messages' => $this->messages($room->messages()->latest('id')->limit(100)->get()->reverse()),
            'chatEnabled' => ! $chatService->hasActiveAttempt($request->user()),
            'dailyLimit' => (int) config('ai.daily_chat_limit', 20),
            'dailyUsed' => $chatService->dailyMessageCount($request->user()),
        ]);
    }

    public function store(
        Request $request,
        StudentChatService $chatService,
        AiManager $manager,
        NotificationAudience $audience,
    ): RedirectResponse {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000']]);
        if ($chatService->hasActiveAttempt($request->user())) {
            throw ValidationException::withMessages([
                'content' => 'Chat dinonaktifkan selama try out sedang berlangsung.',
            ]);
        }
        if ($chatService->dailyMessageCount($request->user()) >= (int) config('ai.daily_chat_limit', 20)) {
            throw ValidationException::withMessages([
                'content' => 'Kuota percakapan ASKA AI hari ini (maksimal '.(int) config('ai.daily_chat_limit', 20).' pesan/hari) sudah habis. Kuota akan direset otomatis besok pukul 00.00 WIB (tengah malam).',
            ]);
        }

        $room = $chatService->roomFor($request->user());
        $sensitiveResponse = $chatService->sensitiveResponse($data['content'], $request->user());

        $needsAttention = DB::transaction(function () use ($room, $request, $data, $sensitiveResponse, $manager): bool {
            $room->messages()->create([
                'sender_id' => $request->user()->id,
                'sender_type' => 'student',
                'type' => 'chat',
                'status' => 'completed',
                'content' => trim($data['content']),
            ]);

            if ($sensitiveResponse !== null) {
                $room->messages()->create([
                    'sender_type' => 'assistant',
                    'type' => 'safety',
                    'status' => 'completed',
                    'content' => $sensitiveResponse,
                    'metadata' => ['needs_teacher_attention' => true],
                ]);
                $room->update(['last_message_at' => now()]);

                return true;
            }

            $provider = $manager->provider();
            $generation = AiGeneration::create([
                'school_id' => $request->user()->school_id,
                'requested_by' => $request->user()->id,
                'type' => AiGenerationType::StudentChat,
                'status' => AiGenerationStatus::Pending,
                'provider' => $provider->name(),
                'model' => $provider->model(),
                'input_hash' => hash('sha256', $room->id.':'.$request->user()->id.':'.$data['content'].':'.now()->timestamp),
                'request_payload' => ['room_id' => $room->id, 'message' => trim($data['content'])],
            ]);
            $assistantMessage = $room->messages()->create([
                'ai_generation_id' => $generation->id,
                'sender_type' => 'assistant',
                'type' => 'chat',
                'status' => 'pending',
            ]);
            $room->update(['last_message_at' => now()]);
            GenerateStudentChatReply::dispatchAfterResponse($generation->id, $assistantMessage->id);

            return false;
        });

        if ($needsAttention) {
            $student = $request->user();
            $audience->send(
                $audience->staffForSchool($student->school_id),
                new ActionNotification(
                    'Chat siswa perlu perhatian',
                    "Percakapan {$student->name} ditandai oleh sistem keselamatan dan perlu segera ditinjau.",
                    route('teacher-chat.show', $student, absolute: false),
                    'warning',
                    "chat-attention:{$room->id}:{$room->last_message_at?->timestamp}",
                ),
            );
        }

        return back();
    }

    private function messages(iterable $messages): array
    {
        return collect($messages)->map(fn (ChatMessage $message): array => [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'type' => $message->type,
            'status' => $message->status,
            'content' => $message->content,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at,
        ])->values()->all();
    }
}
