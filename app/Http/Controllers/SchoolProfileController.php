<?php

namespace App\Http\Controllers;

use App\Models\AssessmentSchedule;
use App\Models\School;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SchoolProfileController extends Controller
{
    private const TIMEZONES = [
        'Asia/Jakarta' => 'WIB — Asia/Jakarta',
        'Asia/Makassar' => 'WITA — Asia/Makassar',
        'Asia/Jayapura' => 'WIT — Asia/Jayapura',
    ];

    public function edit(Request $request): Response
    {
        $school = $request->user()->school()->firstOrFail();

        return Inertia::render('Schools/Edit', [
            'school' => [
                'name' => $school->name,
                'npsn' => $school->npsn,
                'subdistrict' => $school->subdistrict,
                'timezone' => $school->timezone,
                'address' => data_get($school->settings, 'address', ''),
                'province' => data_get($school->settings, 'province', ''),
                'city' => data_get($school->settings, 'city', ''),
                'principal_name' => data_get($school->settings, 'principal_name', ''),
                'phone' => data_get($school->settings, 'phone', ''),
            ],
            'timezones' => collect(self::TIMEZONES)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'subdistricts' => School::SUBDISTRICTS,
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $school = $request->user()->school()->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'npsn' => ['required', 'digits:8', Rule::unique('schools', 'npsn')->ignore($school->id)],
            'subdistrict' => ['required', Rule::in(School::SUBDISTRICTS)],
            'timezone' => ['required', Rule::in(array_keys(self::TIMEZONES))],
            'address' => ['nullable', 'string', 'max:1000'],
            'province' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'principal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $oldNpsn = $school->npsn;
        $oldSubdistrict = $school->subdistrict;
        $settings = array_replace(
            $school->settings ?? [],
            Arr::only($data, ['address', 'province', 'city', 'principal_name', 'phone']),
        );

        DB::transaction(function () use ($school, $data, $settings, $oldNpsn): void {
            $school->update([
                'name' => $data['name'],
                'npsn' => $data['npsn'],
                'subdistrict' => $data['subdistrict'],
                'timezone' => $data['timezone'],
                'settings' => $settings,
            ]);

            if ($oldNpsn !== $data['npsn']) {
                AssessmentSchedule::query()
                    ->where('school_npsn', $oldNpsn)
                    ->update(['school_npsn' => $data['npsn']]);
            }
        });

        $auditLogger->log($request, 'school.updated', $school, [
            'old_npsn' => $oldNpsn,
            'new_npsn' => $school->npsn,
            'old_subdistrict' => $oldSubdistrict,
            'new_subdistrict' => $school->subdistrict,
        ]);

        return back()->with('success', 'Data sekolah berhasil diperbarui.');
    }
}
