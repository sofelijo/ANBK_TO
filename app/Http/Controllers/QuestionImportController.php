<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\QuestionImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuestionImportController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Questions/Import', [
            'errors' => $request->session()->get('import_errors', []),
        ]);
    }

    public function store(
        Request $request,
        QuestionImportService $importService,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = $importService->import($request->file('file'), $request->user());
        $auditLogger->log($request, 'questions.imported', metadata: [
            'imported' => $result['imported'],
            'failed' => count($result['errors']),
        ]);

        return back()
            ->with('success', "{$result['imported']} soal berhasil diimpor sebagai draft.")
            ->with('import_errors', $result['errors']);
    }

    public function template(): StreamedResponse
    {
        $headers = [
            'competency_code', 'type', 'title', 'stimulus', 'prompt', 'explanation',
            'difficulty', 'grade_level', 'cognitive_level', 'option_a', 'option_b',
            'option_c', 'option_d', 'option_e', 'option_f', 'correct_answers',
            'accepted_answers',
        ];

        return response()->streamDownload(function () use ($headers): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            fputcsv($stream, [
                'LIT5-INFO', 'single_choice', 'Contoh soal', 'Stimulus singkat',
                'Pertanyaan contoh?', 'Pembahasan', '1', '5', 'menemukan informasi',
                'Jawaban A', 'Jawaban B', 'Jawaban C', 'Jawaban D', '', '', 'B', '',
            ]);
            fclose($stream);
        }, 'template-import-soal.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
