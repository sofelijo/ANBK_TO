<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\StudentChatService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeacherChatController extends Controller
{
    public function index(Request $request): Response
    {
        $students = User::query()
            ->when(
                ! $request->user()->hasRole(UserRole::Admin),
                fn ($query) => $query->where('school_id', $request->user()->school_id),
            )
            ->where('role', UserRole::Student)
            ->with(['school:id,name,npsn', 'chatRoom.lastMessage'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Chat/TeacherIndex', [
            'students' => $students->map(fn (User $student): array => [
                'id' => $student->id,
                'name' => $student->name,
                'student_identifier' => $student->student_identifier,
                'grade_level' => $student->grade_level,
                'school' => $student->school?->only(['id', 'name', 'npsn']),
                'room_id' => $student->chatRoom?->id,
                'last_message' => $student->chatRoom?->lastMessage ? [
                    'content' => $student->chatRoom->lastMessage->content,
                    'sender_type' => $student->chatRoom->lastMessage->sender_type,
                    'created_at' => $student->chatRoom->lastMessage->created_at,
                    'needs_attention' => (bool) data_get($student->chatRoom->lastMessage->metadata, 'needs_teacher_attention', false),
                ] : null,
            ]),
        ]);
    }

    public function show(Request $request, User $student, StudentChatService $chatService): Response
    {
        abort_unless(
            $student->role === UserRole::Student
            && ($request->user()->hasRole(UserRole::Admin) || $student->school_id === $request->user()->school_id),
            404,
        );
        $room = $chatService->roomFor($student);
        $messages = $room->messages()->latest('id')->limit(100)->get()->reverse()->values();

        return Inertia::render('Chat/TeacherShow', [
            'student' => [
                ...$student->only(['id', 'name', 'student_identifier', 'grade_level']),
                'school' => $student->school?->only(['id', 'name', 'npsn']),
            ],
            'room' => ['id' => $room->id],
            'messages' => $messages->map(fn (ChatMessage $message): array => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'type' => $message->type,
                'status' => $message->status,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at,
            ]),
        ]);
    }
}
