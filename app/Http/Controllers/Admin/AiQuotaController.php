<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return Inertia::render('Admin/AiQuotas/Edit', [
            'quotas' => $quota->limits($request->user()),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'question_variants' => ['required', 'integer', 'between:0,1000'],
            'story_questions' => ['required', 'integer', 'between:0,1000'],
            'story_illustrations' => ['required', 'integer', 'between:0,1000'],
        ]);
        $school = $request->user()->school()->firstOrFail();
        $settings = $school->settings ?? [];
        $settings['ai_teacher_quotas'] = $data;
        $school->update(['settings' => $settings]);

        $auditLogger->log($request, 'teacher_ai_quotas.updated', $school, $data);

        return back()->with('success', 'Kuota AI harian guru berhasil diperbarui.');
    }
}
