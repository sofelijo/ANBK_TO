<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\AuditLogger;
use App\Services\TeacherAiQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiQuotaController extends Controller
{
    public function edit(Request $request, TeacherAiQuota $quota): Response
    {
        $school = School::query()->findOrFail($request->integer('school_id') ?: School::query()->value('id'));

        return Inertia::render('Admin/AiQuotas/Edit', [
            'quotas' => $quota->limitsForSchool($school),
            'school' => $school->only(['id', 'name', 'npsn']),
            'schools' => School::query()->orderBy('name')->get(['id', 'name', 'npsn']),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'school_id' => ['required', 'integer', 'exists:schools,id'],
            'question_variants' => ['required', 'integer', 'between:0,1000'],
            'story_questions' => ['required', 'integer', 'between:0,1000'],
            'story_illustrations' => ['required', 'integer', 'between:0,1000'],
        ]);
        $school = School::query()->findOrFail($data['school_id']);
        unset($data['school_id']);
        $settings = $school->settings ?? [];
        $settings['ai_teacher_quotas'] = $data;
        $school->update(['settings' => $settings]);

        $auditLogger->log($request, 'teacher_ai_quotas.updated', $school, $data);

        return back()->with('success', 'Kuota AI harian guru berhasil diperbarui.');
    }
}
