<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\CompetencyResult;
use App\Services\AuditLogger;
use App\Services\ItemAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ItemAnalysisService $itemAnalysisService): Response
    {
        $assessments = Assessment::query()
            ->where('school_id', $request->user()->school_id)
            ->latest()
            ->get(['id', 'title', 'grade_level']);
        $assessment = $this->selectedAssessment($request, $assessments);

        if (! $assessment) {
            return Inertia::render('Reports/Index', [
                'assessments' => $assessments,
                'selectedAssessmentId' => null,
                'summary' => null,
                'students' => [],
                'competencies' => [],
                'items' => [],
                'itemAnalysis' => null,
            ]);
        }

        $attempts = Attempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::Submitted)
            ->with(['student:id,name,email,student_identifier,grade_level'])
            ->withCount('events')
            ->orderByDesc('score')
            ->get();
        $percentages = $attempts->map(fn (Attempt $attempt): float => $this->percentage($attempt));

        $itemAnalysis = $itemAnalysisService->analyze($assessment);

        return Inertia::render('Reports/Index', [
            'assessments' => $assessments,
            'selectedAssessmentId' => $assessment->id,
            'summary' => [
                'completed' => $attempts->count(),
                'average' => round($percentages->avg() ?? 0, 2),
                'highest' => round($percentages->max() ?? 0, 2),
                'lowest' => round($percentages->min() ?? 0, 2),
            ],
            'students' => $attempts->map(fn (Attempt $attempt): array => [
                'id' => $attempt->id,
                'name' => $attempt->student->name,
                'student_identifier' => $attempt->student->student_identifier,
                'grade_level' => $attempt->student->grade_level,
                'score' => (float) $attempt->score,
                'max_score' => (float) $attempt->max_score,
                'percentage' => $this->percentage($attempt),
                'duration_seconds' => $attempt->duration_seconds,
                'events_count' => $attempt->events_count,
                'submitted_at' => $attempt->submitted_at,
            ]),
            'competencies' => $this->competencyStats($assessment),
            'items' => $itemAnalysis['items'],
            'itemAnalysis' => collect($itemAnalysis)->except('items')->all(),
        ]);
    }

    public function export(Request $request, AuditLogger $auditLogger): StreamedResponse
    {
        $assessment = Assessment::query()
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($request->integer('assessment_id'));
        $attempts = Attempt::query()
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::Submitted)
            ->with('student')
            ->orderBy('user_id')
            ->get();
        $auditLogger->log($request, 'report.exported', $assessment, ['format' => 'csv']);

        return response()->streamDownload(function () use ($attempts): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['NIS', 'Nama', 'Kelas', 'Nilai', 'Skor', 'Skor Maksimal', 'Durasi Detik', 'Selesai']);
            foreach ($attempts as $attempt) {
                fputcsv($stream, [
                    $attempt->student->student_identifier,
                    $attempt->student->name,
                    $attempt->student->grade_level,
                    $this->percentage($attempt),
                    $attempt->score,
                    $attempt->max_score,
                    $attempt->duration_seconds,
                    $attempt->submitted_at?->toIso8601String(),
                ]);
            }
            fclose($stream);
        }, "hasil-try-out-{$assessment->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function selectedAssessment(Request $request, Collection $assessments): ?Assessment
    {
        $id = $request->integer('assessment_id') ?: $assessments->first()?->id;
        if (! $id) {
            return null;
        }

        return Assessment::query()
            ->where('school_id', $request->user()->school_id)
            ->findOrFail($id);
    }

    private function competencyStats(Assessment $assessment): Collection
    {
        return CompetencyResult::query()
            ->select('competency_id')
            ->selectRaw('AVG(percentage) as average_percentage')
            ->selectRaw('COUNT(*) as student_count')
            ->whereHas('attempt', fn ($query) => $query
                ->where('assessment_id', $assessment->id)
                ->where('status', AttemptStatus::Submitted))
            ->with('competency:id,code,domain,name')
            ->groupBy('competency_id')
            ->orderBy('average_percentage')
            ->get()
            ->map(fn (CompetencyResult $result): array => [
                'competency' => $result->competency,
                'average_percentage' => round((float) $result->average_percentage, 2),
                'student_count' => (int) $result->student_count,
            ]);
    }

    private function percentage(Attempt $attempt): float
    {
        return (float) $attempt->max_score > 0
            ? round(((float) $attempt->score / (float) $attempt->max_score) * 100, 2)
            : 0;
    }
}
