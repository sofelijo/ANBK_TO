<?php

namespace App\Http\Controllers;

use App\Models\CensoredResponse;
use App\Models\CensoredWord;
use App\Services\StudentChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CensoredWordController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $words = CensoredWord::query()
            ->where(function ($query) use ($schoolId) {
                $query->whereNull('school_id');
                if ($schoolId) {
                    $query->orWhere('school_id', $schoolId);
                }
            })
            ->orderBy('word')
            ->get()
            ->map(fn (CensoredWord $word): array => [
                'id' => $word->id,
                'school_id' => $word->school_id,
                'word' => $word->word,
                'is_global' => $word->school_id === null,
                'created_at' => $word->created_at?->translatedFormat('d M Y H:i'),
            ]);

        $responses = CensoredResponse::query()
            ->where(function ($query) use ($schoolId) {
                $query->whereNull('school_id');
                if ($schoolId) {
                    $query->orWhere('school_id', $schoolId);
                }
            })
            ->latest('id')
            ->get()
            ->map(fn (CensoredResponse $res): array => [
                'id' => $res->id,
                'school_id' => $res->school_id,
                'response_text' => $res->response_text,
                'is_active' => $res->is_active,
                'is_global' => $res->school_id === null,
                'created_at' => $res->created_at?->translatedFormat('d M Y H:i'),
            ]);

        return Inertia::render('CensoredWords/Index', [
            'words' => $words,
            'responses' => $responses,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'words_input' => ['required', 'string', 'max:2000'],
        ], [
            'words_input.required' => 'Masukkan setidaknya satu kata terlarang.',
        ]);

        // Support batch comma or newline separated words
        $rawWords = preg_split('/[\r\n,]+/', $data['words_input']);
        $added = 0;

        foreach ($rawWords as $rawWord) {
            $cleaned = mb_strtolower(trim($rawWord));
            if ($cleaned === '') {
                continue;
            }

            $exists = CensoredWord::query()
                ->where('word', $cleaned)
                ->where(function ($q) use ($user) {
                    $q->whereNull('school_id')->orWhere('school_id', $user->school_id);
                })
                ->exists();

            if (! $exists) {
                CensoredWord::create([
                    'school_id' => $user->school_id,
                    'created_by' => $user->id,
                    'word' => $cleaned,
                ]);
                $added++;
            }
        }

        return back()->with('success', "{$added} kata terlarang berhasil ditambahkan.");
    }

    public function destroy(Request $request, CensoredWord $censoredWord): RedirectResponse
    {
        $censoredWord->delete();

        return back()->with('success', 'Kata terlarang berhasil dihapus.');
    }

    // Manage response templates pool
    public function storeResponse(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'response_text' => ['required', 'string', 'max:1000'],
        ], [
            'response_text.required' => 'Teks respon edukasi wajib diisi.',
        ]);

        CensoredResponse::create([
            'school_id' => $user->school_id,
            'created_by' => $user->id,
            'response_text' => trim($data['response_text']),
            'is_active' => true,
        ]);

        return back()->with('success', 'Respon edukasi ASKA berhasil ditambahkan ke pool.');
    }

    public function updateResponse(Request $request, CensoredResponse $censoredResponse): RedirectResponse
    {
        $data = $request->validate([
            'response_text' => ['required', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $censoredResponse->update([
            'response_text' => trim($data['response_text']),
            'is_active' => $data['is_active'] ?? $censoredResponse->is_active,
        ]);

        return back()->with('success', 'Respon edukasi ASKA berhasil diperbarui.');
    }

    public function destroyResponse(Request $request, CensoredResponse $censoredResponse): RedirectResponse
    {
        $censoredResponse->delete();

        return back()->with('success', 'Respon edukasi ASKA berhasil dihapus.');
    }

    public function test(Request $request, StudentChatService $chatService)
    {
        $request->validate(['message' => ['required', 'string', 'max:500']]);

        $response = $chatService->sensitiveResponse($request->string('message')->toString(), $request->user());

        return response()->json([
            'triggered' => $response !== null,
            'response' => $response ?? 'Pesan tidak memicu sensor. Pesan akan diteruskan ke AI ASKA seperti biasa.',
        ]);
    }
}
