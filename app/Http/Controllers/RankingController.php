<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\TogetherRankingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RankingController extends Controller
{
    public function __invoke(Request $request, TogetherRankingService $ranking): Response
    {
        $togetherAssessments = $ranking->assessments();
        $assessments = $ranking->selectableAssessments($request->user());
        $selectedAssessment = $request->integer('assessment_id')
            ? $assessments->firstWhere('id', $request->integer('assessment_id'))
            : null;
        $selectedSubdistrict = in_array($request->string('subdistrict')->toString(), School::SUBDISTRICTS, true)
            ? $request->string('subdistrict')->toString()
            : null;

        if ($assessments->isEmpty()) {
            return Inertia::render('Rankings/Index', [
                'assessments' => [],
                'selectedAssessment' => null,
                'selectedSubdistrict' => null,
                'subdistricts' => School::SUBDISTRICTS,
                'rankings' => [],
                'schoolRankings' => [],
                'districtRankings' => [],
                'cumulativeAssessmentCount' => 0,
            ]);
        }

        $allAttempts = $ranking->attempts($selectedAssessment);
        $attempts = $selectedSubdistrict
            ? $ranking->attempts($selectedAssessment, $selectedSubdistrict)
            : $allAttempts;

        return Inertia::render('Rankings/Index', [
            'assessments' => $assessments->map(fn ($assessment): array => $ranking->assessmentData($assessment)),
            'selectedAssessment' => $selectedAssessment
                ? $ranking->assessmentData($selectedAssessment)
                : null,
            'selectedSubdistrict' => $selectedSubdistrict,
            'subdistricts' => School::SUBDISTRICTS,
            'rankings' => $ranking->individuals($attempts),
            'schoolRankings' => $ranking->schools($attempts),
            'districtRankings' => $ranking->districts($allAttempts),
            'cumulativeAssessmentCount' => $togetherAssessments->count(),
        ]);
    }
}
