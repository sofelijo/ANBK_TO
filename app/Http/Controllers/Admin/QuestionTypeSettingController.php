<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\School;
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
        $school = School::query()->findOrFail($request->integer('school_id') ?: School::query()->value('id'));

        return Inertia::render('Admin/QuestionTypes/Edit', [
            'questionTypes' => $configuration->options($school),
            'allQuestionTypes' => collect(QuestionType::cases())->map(fn (QuestionType $type): array => [
                'value' => $type->value,
                'label' => $configuration->label($type),
                'description' => $configuration->description($type),
            ])->values(),
            'school' => $school->only(['id', 'name', 'npsn']),
            'schools' => School::query()->orderBy('name')->get(['id', 'name', 'npsn']),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')],
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
        $school = School::query()->findOrFail($data['school_id']);
        $settings = $school->settings ?? [];
        $settings[QuestionTypeConfiguration::SETTINGS_KEY] = $enabled;
        $school->update(['settings' => $settings]);

        $auditLogger->log($request, 'question_types.updated', $school, [
            'enabled_question_types' => $enabled,
        ]);

        return back()->with('success', 'Pengaturan bentuk soal aktif berhasil disimpan.');
    }
}
