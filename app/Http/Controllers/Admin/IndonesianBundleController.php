<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\IndonesianBundleConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IndonesianBundleController extends Controller
{
    public function edit(Request $request, IndonesianBundleConfiguration $configuration): Response
    {
        return Inertia::render('Admin/IndonesianBundles/Edit', [
            'slots' => $configuration->forUser($request->user()),
        ]);
    }

    public function update(Request $request, IndonesianBundleConfiguration $configuration, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'slots' => ['required', 'array', 'size:3'],
            'slots.*.answer_format' => ['required', Rule::in(IndonesianBundleConfiguration::ANSWER_FORMATS)],
            'slots.*.cognitive_level' => ['required', Rule::in(IndonesianBundleConfiguration::COGNITIVE_LEVELS)],
        ]);
        $slots = $configuration->ensureComplete($data['slots']);
        $school = $request->user()->school()->firstOrFail();
        $settings = $school->settings ?? [];
        $settings['indonesian_bundle_defaults'] = ['slots' => $slots];
        $school->update(['settings' => $settings]);

        $auditLogger->log($request, 'indonesian_bundle_defaults.updated', $school, ['slots' => $slots]);

        return back()->with('success', 'Default bundle Bahasa Indonesia berhasil diperbarui.');
    }
}
