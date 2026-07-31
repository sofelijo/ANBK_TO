<?php

namespace App\Http\Controllers;

use App\Enums\AssessmentStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\Question;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasRole(UserRole::Student)) {
            return Inertia::render('Dashboard', [
                'stats' => [
                    'availableAssessments' => Assessment::query()
                        ->where('school_id', $user->school_id)
                        ->where('grade_level', $user->grade_level)
                        ->where('status', AssessmentStatus::Published)
                        ->count(),
                    'completedAttempts' => Attempt::query()
                        ->where('user_id', $user->id)
                        ->whereNotNull('submitted_at')
                        ->count(),
                ],
                'mode' => 'student',
            ]);
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'questions' => Question::query()->where('school_id', $user->school_id)->count(),
                'publishedQuestions' => Question::query()
                    ->where('school_id', $user->school_id)
                    ->where('status', 'published')
                    ->count(),
                'assessments' => Assessment::query()->where('school_id', $user->school_id)->count(),
                'completedAttempts' => Attempt::query()
                    ->whereHas('assessment', fn ($query) => $query->where('school_id', $user->school_id))
                    ->whereNotNull('submitted_at')
                    ->count(),
            ],
            'mode' => 'teacher',
        ]);
    }
}
