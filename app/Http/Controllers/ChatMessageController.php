<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function index(Request $request, ChatRoom $room): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->role === UserRole::Student && $room->student_id === $user->id)
            || (in_array($user->role, [UserRole::Admin, UserRole::Teacher], true) && $room->school_id === $user->school_id),
            404,
        );

        $messages = $room->messages()
            ->when($request->integer('after'), fn ($query, int $after) => $query->where('id', '>', $after))
            ->oldest('id')
            ->limit(100)
            ->get()
            ->map(fn (ChatMessage $message): array => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'type' => $message->type,
                'status' => $message->status,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at,
            ]);

        return response()->json(['messages' => $messages]);
    }
}
