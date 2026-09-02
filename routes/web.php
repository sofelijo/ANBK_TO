<?php

use App\Http\Controllers\Admin\AiQuotaController;
use App\Http\Controllers\Admin\IndonesianBundleController;
use App\Http\Controllers\Admin\QuestionTypeSettingController;
use App\Http\Controllers\Admin\TeacherVerificationAnalyticsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AiQuestionController;
use App\Http\Controllers\AiQuestionReviewController;
use App\Http\Controllers\AiStoryIllustrationController;
use App\Http\Controllers\AiStoryQuestionController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentMonitoringController;
use App\Http\Controllers\AssessmentScheduleController;
use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AttemptEventController;
use App\Http\Controllers\CensoredWordController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ManualIndonesianBundleController;
use App\Http\Controllers\MonitoringAiAnalysisController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionBlueprintController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionImportController;
use App\Http\Controllers\RankingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SchoolProfileController;
use App\Http\Controllers\SchoolStudentController;
use App\Http\Controllers\StudentChatController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherChatController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/assessments', [AssessmentController::class, 'index'])->name('assessments.index');

    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/rankings', RankingController::class)->name('rankings.index');
        Route::get('/student-chats', [TeacherChatController::class, 'index'])->name('teacher-chat.index');
        Route::get('/student-chats/{student}', [TeacherChatController::class, 'show'])->name('teacher-chat.show');
        Route::get('/censored-words', [CensoredWordController::class, 'index'])->name('censored-words.index');
        Route::post('/censored-words', [CensoredWordController::class, 'store'])->name('censored-words.store');
        Route::delete('/censored-words/{censoredWord}', [CensoredWordController::class, 'destroy'])->name('censored-words.destroy');
        Route::post('/censored-words/responses', [CensoredWordController::class, 'storeResponse'])->name('censored-words.responses.store');
        Route::put('/censored-words/responses/{censoredResponse}', [CensoredWordController::class, 'updateResponse'])->name('censored-words.responses.update');
        Route::delete('/censored-words/responses/{censoredResponse}', [CensoredWordController::class, 'destroyResponse'])->name('censored-words.responses.destroy');
        Route::post('/censored-words/test', [CensoredWordController::class, 'test'])->name('censored-words.test');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/questions/import', [QuestionImportController::class, 'create'])->name('questions.import.create');
        Route::post('/questions/import', [QuestionImportController::class, 'store'])->name('questions.import.store');
        Route::get('/questions/import/template', [QuestionImportController::class, 'template'])->name('questions.import.template');
        Route::get('/questions/manual-bundle/create', [ManualIndonesianBundleController::class, 'create'])->name('manual-story-bundles.create');
        Route::post('/questions/manual-bundle', [ManualIndonesianBundleController::class, 'store'])->name('manual-story-bundles.store');
        Route::get('/story-questions/create', [AiStoryQuestionController::class, 'create'])->name('story-questions.create');
        Route::get('/ai-questions/create', [AiStoryQuestionController::class, 'create'])->name('ai-questions.create');
        Route::post('/story-questions', [AiStoryQuestionController::class, 'store'])->name('story-questions.store');
        Route::post('/ai-questions', [AiStoryQuestionController::class, 'store'])->name('ai-questions.store');
        Route::get('/story-questions/{generation}', [AiStoryQuestionController::class, 'show'])->name('story-questions.show');
        Route::get('/ai-questions/{generation}', [AiStoryQuestionController::class, 'show'])->name('ai-questions.show');
        Route::post('/story-questions/{generation}/retry', [AiStoryQuestionController::class, 'retry'])->name('story-questions.retry');
        Route::post('/ai-questions/{generation}/retry', [AiStoryQuestionController::class, 'retry'])->name('ai-questions.retry');
        Route::post('/story-questions/{generation}/publish', [AiStoryQuestionController::class, 'publishBundle'])->name('story-questions.publish');
        Route::post('/ai-questions/{generation}/publish', [AiStoryQuestionController::class, 'publishBundle'])->name('ai-questions.publish');
        Route::post('/story-questions/{generation}/illustration', [AiStoryIllustrationController::class, 'store'])->name('story-questions.illustration.store');
        Route::post('/ai-questions/{generation}/illustration', [AiStoryIllustrationController::class, 'store'])->name('ai-questions.illustration.store');
        Route::put('/generated-questions/{generation}/questions/{question}', [QuestionController::class, 'inlineUpdate'])->name('generated-questions.inline-update');
        Route::delete('/generated-questions/{generation}/questions/{question}', [QuestionController::class, 'destroyGenerated'])->name('generated-questions.destroy');
        Route::resource('questions', QuestionController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::resource('subjects', SubjectController::class)->except('show');
        Route::resource('competencies', CompetencyController::class)->except('show');
        Route::resource('question-types', QuestionBlueprintController::class)
            ->parameters(['question-types' => 'questionType'])
            ->except('show');
        Route::post('/questions/{question}/approve', [QuestionController::class, 'approve'])->name('questions.approve');
        Route::post('/questions/{question}/duplicate-check', [QuestionController::class, 'duplicateCheck'])->name('questions.duplicate-check');
        Route::post('/questions/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('questions.duplicate');
        Route::post('/questions/{question}/archive', [QuestionController::class, 'archive'])->name('questions.archive');
        Route::post('/questions/{question}/ai-variants', [AiQuestionController::class, 'store'])->name('questions.ai-variants.store');
        Route::post('/questions/{question}/ai-review', [AiQuestionReviewController::class, 'store'])->name('questions.ai-review.store');

        Route::get('/assessments/create', [AssessmentController::class, 'create'])->name('assessments.create');
        Route::post('/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
        Route::get('/assessments/{assessment}', [AssessmentController::class, 'show'])->name('assessments.show');
        Route::get('/assessments/{assessment}/edit', [AssessmentController::class, 'edit'])->name('assessments.edit');
        Route::put('/assessments/{assessment}', [AssessmentController::class, 'update'])->name('assessments.update');
        Route::post('/assessments/{assessment}/publish', [AssessmentController::class, 'publish'])->name('assessments.publish');
        Route::delete('/assessments/{assessment}/questions/{question}', [AssessmentController::class, 'removeQuestion'])->name('assessments.questions.remove');
        Route::post('/assessments/{assessment}/questions/swap', [AssessmentController::class, 'swapQuestion'])->name('assessments.questions.swap');
        Route::post('/assessments/{assessment}/questions/attach', [AssessmentController::class, 'attachQuestion'])->name('assessments.questions.attach');
    });

    Route::middleware('role:admin,operator')->group(function () {
        Route::get('/monitoring', AssessmentMonitoringController::class)->name('monitoring.index');
        Route::post('/monitoring/{assessment}/ai-analysis', [MonitoringAiAnalysisController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('monitoring.ai-analysis.store');
        Route::get('/schedules', [AssessmentScheduleController::class, 'index'])->name('schedules.index');
        Route::post('/schedules', [AssessmentScheduleController::class, 'store'])->name('schedules.store');
        Route::delete('/schedules/{schedule}', [AssessmentScheduleController::class, 'destroy'])->name('schedules.destroy');
        Route::get('/school/settings', [SchoolProfileController::class, 'edit'])->name('school.edit');
        Route::patch('/school/settings', [SchoolProfileController::class, 'update'])->name('school.update');
        Route::get('/school/students', SchoolStudentController::class)->name('school.students.index');
        Route::patch('/school/students/{student}/approve', [SchoolStudentController::class, 'approve'])->name('school.students.approve');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/teacher-verifications', TeacherVerificationAnalyticsController::class)
            ->name('admin.teacher-verifications.index');
        Route::get('/admin/indonesian-bundles', [IndonesianBundleController::class, 'edit'])->name('admin.indonesian-bundles.edit');
        Route::patch('/admin/indonesian-bundles', [IndonesianBundleController::class, 'update'])->name('admin.indonesian-bundles.update');
        Route::get('/admin/ai-quotas', [AiQuotaController::class, 'edit'])->name('admin.ai-quotas.edit');
        Route::patch('/admin/ai-quotas', [AiQuotaController::class, 'update'])->name('admin.ai-quotas.update');
        Route::get('/admin/question-types', [QuestionTypeSettingController::class, 'edit'])->name('admin.question-types.edit');
        Route::patch('/admin/question-types', [QuestionTypeSettingController::class, 'update'])->name('admin.question-types.update');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}/approve', [AdminUserController::class, 'approve'])->name('admin.users.approve');
        Route::patch('/admin/users/{user}/toggle-active', [AdminUserController::class, 'toggleActive'])->name('admin.users.toggle-active');
    });

    Route::middleware('role:student')->group(function () {
        Route::get('/chat', [StudentChatController::class, 'show'])->name('student-chat.show');
        Route::post('/chat/messages', [StudentChatController::class, 'store'])->middleware('throttle:20,1')->name('student-chat.messages.store');
        Route::post('/assessments/{assessment}/start', [AttemptController::class, 'start'])->name('attempts.start');
        Route::get('/attempts/{attempt:public_id}', [AttemptController::class, 'show'])->name('attempts.show');
        Route::put('/attempts/{attempt:public_id}/answers/{question}', [AttemptController::class, 'saveAnswer'])->middleware('throttle:120,1')->name('attempts.answers.update');
        Route::post('/attempts/{attempt:public_id}/events', [AttemptEventController::class, 'store'])->middleware('throttle:30,1')->name('attempts.events.store');
        Route::post('/attempts/{attempt:public_id}/submit', [AttemptController::class, 'submit'])->name('attempts.submit');
        Route::get('/attempts/{attempt:public_id}/result', [AttemptController::class, 'result'])->name('attempts.result');
        Route::post('/attempts/{attempt:public_id}/practice-chat', [AttemptController::class, 'practiceChat'])->name('attempts.practice-chat');
    });

    Route::get('/chat-rooms/{room}/messages', [ChatMessageController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('chat.messages.index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
