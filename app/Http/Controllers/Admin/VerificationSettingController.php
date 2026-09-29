<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApplicationSetting;
use App\Models\Question;
use App\Services\AuditLogger;
use App\Services\QuestionVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VerificationSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Admin/VerificationSetting/Edit', [
            'requiredVerifications' => QuestionVerificationService::requiredGlobally(),
            'default' => Question::REQUIRED_VERIFICATIONS,
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'required_verifications' => ['required', 'integer', 'min:1', 'max:10'],
        ], [
            'required_verifications.min' => 'Minimal verifikasi adalah 1 guru.',
            'required_verifications.max' => 'Maksimal verifikasi adalah 10 guru.',
        ]);

        $setting = ApplicationSetting::query()->updateOrCreate(
            ['key' => QuestionVerificationService::SETTINGS_KEY],
            ['value' => (string) $data['required_verifications']],
        );

        $auditLogger->log($request, 'verification_setting.updated', $setting, [
            'required_verifications' => (int) $data['required_verifications'],
        ]);

        return back()->with('success', "Minimal verifikasi global diperbarui menjadi {$data['required_verifications']} guru.");
    }
}
