<?php

use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AiQuestionController;
use App\Http\Controllers\AiQuestionReviewController;
use App\Http\Controllers\AiStoryIllustrationController;
use App\Http\Controllers\AiStoryQuestionController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AttemptController;
use App\Http\Controllers\AttemptEventController;
use App\Http\Controllers\ChatMessageController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionImportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StudentChatController;
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
        Route::get('/student-chats', [TeacherChatController::class, 'index'])->name('teacher-chat.index');
        Route::get('/student-chats/{student}', [TeacherChatController::class, 'show'])->name('teacher-chat.show');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/questions/import', [QuestionImportController::class, 'create'])->name('questions.import.create');
        Route::post('/questions/import', [QuestionImportController::class, 'store'])->name('questions.import.store');
        Route::get('/questions/import/template', [QuestionImportController::class, 'template'])->name('questions.import.template');
        Route::get('/story-questions/create', [AiStoryQuestionController::class, 'create'])->name('story-questions.create');
        Route::post('/story-questions', [AiStoryQuestionController::class, 'store'])->name('story-questions.store');
        Route::get('/story-questions/{generation}', [AiStoryQuestionController::class, 'show'])->name('story-questions.show');
        Route::post('/story-questions/{generation}/retry', [AiStoryQuestionController::class, 'retry'])->name('story-questions.retry');
        Route::post('/story-questions/{generation}/publish', [AiStoryQuestionController::class, 'publishBundle'])->name('story-questions.publish');
        Route::post('/story-questions/{generation}/illustration', [AiStoryIllustrationController::class, 'store'])->name('story-questions.illustration.store');
        Route::resource('questions', QuestionController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);
        Route::resource('competencies', CompetencyController::class)->except('show');
        Route::post('/questions/{question}/approve', [QuestionController::class, 'approve'])->name('questions.approve');
        Route::post('/questions/{question}/duplicate', [QuestionController::class, 'duplicate'])->name('questions.duplicate');
        Route::post('/questions/{question}/archive', [QuestionController::class, 'archive'])->name('questions.archive');
        Route::post('/questions/{question}/ai-variants', [AiQuestionController::class, 'store'])->name('questions.ai-variants.store');
        Route::post('/questions/{question}/ai-review', [AiQuestionReviewController::class, 'store'])->name('questions.ai-review.store');

        Route::get('/assessments/create', [AssessmentController::class, 'create'])->name('assessments.create');
        Route::post('/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
        Route::get('/assessments/{assessment}/edit', [AssessmentController::class, 'edit'])->name('assessments.edit');
        Route::put('/assessments/{assessment}', [AssessmentController::class, 'update'])->name('assessments.update');
        Route::post('/assessments/{assessment}/publish', [AssessmentController::class, 'publish'])->name('assessments.publish');
    });

    Route::middleware('role:admin')->group(function () {
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
