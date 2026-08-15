<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RebrandHistoricalContentToToaTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_product_copy_and_generated_email_are_rebranded(): void
    {
        $oldAcronym = implode('', ['T', 'K', 'A']);
        $school = School::create([
            'name' => "Sekolah {$oldAcronym} Cerdas",
            'npsn' => '12345678',
        ]);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru Rebrand',
            'email' => 'guru@'.strtolower($oldAcronym).'.local',
            'password' => 'password',
            'role' => UserRole::Teacher,
        ]);
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => "Try Out {$oldAcronym} Kelas 6",
            'grade_level' => 6,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Draft,
        ]);

        $migration = require database_path('migrations/2026_08_14_000100_rebrand_historical_content_to_toa.php');
        $migration->up();

        $this->assertSame('Sekolah TOA', $school->fresh()->name);
        $this->assertSame('guru@toa.local', $teacher->fresh()->email);
        $this->assertSame('Try Out Adaptif Kelas 6', $assessment->fresh()->title);
    }
}
