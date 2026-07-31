<?php

namespace App\Http\Controllers;

use App\Enums\AttemptStatus;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\AttemptAnswer;
use App\Models\CompetencyResult;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
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
            'items' => $this->itemStats($assessment),
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

    private function itemStats(Assessment $assessment): Collection
    {
        return AttemptAnswer::query()
            ->select('question_id')
            ->selectRaw('COUNT(*) as answer_count')
            ->selectRaw('SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct_count')
            ->selectRaw('AVG(duration_seconds) as average_duration')
            ->whereHas('attempt', fn ($query) => $query
                ->where('assessment_id', $assessment->id)
                ->where('status', AttemptStatus::Submitted))
            ->with('question:id,title,prompt,difficulty')
            ->groupBy('question_id')
            ->get()
            ->map(function (AttemptAnswer $answer): array {
                $answerCount = (int) $answer->answer_count;

                return [
                    'question' => $answer->question,
                    'answer_count' => $answerCount,
                    'correct_percentage' => $answerCount > 0
                        ? round(((int) $answer->correct_count / $answerCount) * 100, 2)
                        : 0,
                    'average_duration' => round((float) $answer->average_duration),
                ];
            });
    }

    private function percentage(Attempt $attempt): float
    {
        return (float) $attempt->max_score > 0
            ? round(((float) $attempt->score / (float) $attempt->max_score) * 100, 2)
            : 0;
    }
}
