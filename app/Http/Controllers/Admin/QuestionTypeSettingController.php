<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\QuestionTypeConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QuestionTypeSettingController extends Controller
{
    public function edit(Request $request, QuestionTypeConfiguration $configuration): Response
    {
        $school = $request->user()->school()->firstOrFail();

        return Inertia::render('Admin/QuestionTypes/Edit', [
            'questionTypes' => $configuration->options($school),
            'allQuestionTypes' => collect(QuestionType::cases())->map(fn (QuestionType $type): array => [
                'value' => $type->value,
                'label' => $configuration->label($type),
                'description' => $configuration->description($type),
            ])->values(),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'enabled_question_types' => ['required', 'array', 'min:1'],
            'enabled_question_types.*' => ['required', 'string', 'distinct', Rule::enum(QuestionType::class)],
        ], [
            'enabled_question_types.min' => 'Aktifkan minimal satu bentuk soal.',
        ]);

        $submitted = $data['enabled_question_types'];
        $enabled = collect(QuestionType::cases())
            ->pluck('value')
            ->filter(fn (string $value): bool => in_array($value, $submitted, true))
            ->values()
            ->all();
        $school = $request->user()->school()->firstOrFail();
        $settings = $school->settings ?? [];
        $settings[QuestionTypeConfiguration::SETTINGS_KEY] = $enabled;
        $school->update(['settings' => $settings]);

        $auditLogger->log($request, 'question_types.updated', $school, [
            'enabled_question_types' => $enabled,
        ]);

        return back()->with('success', 'Pengaturan bentuk soal aktif berhasil disimpan.');
    }
}
