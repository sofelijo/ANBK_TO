<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Models\Attempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttemptEventController extends Controller
{
    public function store(Request $request, Attempt $attempt): JsonResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 404);
        abort_unless($attempt->status === AttemptStatus::InProgress, 409);

        $data = $request->validate([
            'event_type' => ['required', Rule::in([
                'tab_hidden',
                'fullscreen_exit',
                'connection_lost',
                'connection_restored',
                'autosave_failed',
            ])],
            'payload' => ['nullable', 'array', 'max:10'],
            'payload.*' => ['nullable'],
        ]);

        $attempt->events()->create([
            'event_type' => $data['event_type'],
            'payload' => $data['payload'] ?? null,
            'ip_address' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return response()->json(['recorded' => true], 201);
    }
}
