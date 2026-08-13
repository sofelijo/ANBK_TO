<?php

namespace Tests\Feature;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GradeLevelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_grade_levels_are_migrated_for_tka(): void
    {
        $school = School::create(['name' => 'Sekolah Migrasi', 'npsn' => '12345678']);
        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Guru',
            'email' => 'guru-migrasi@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
        ]);
        $student = User::create([
            'school_id' => $school->id,
            'name' => 'Siswa',
            'email' => 'siswa-migrasi@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => '0000000001',
            'grade_level' => 5,
        ]);
        $subject = Subject::create([
            'school_id' => $school->id,
            'code' => 'BIND-MIGRASI',
            'name' => 'Bahasa Indonesia',
        ]);
        $competency = Competency::create([
            'school_id' => $school->id,
            'subject_id' => $subject->id,
            'code' => 'LIT5-MIGRASI',
            'domain' => 'Literasi',
            'name' => 'Kompetensi Kelas 5',
            'grade_level' => 5,
        ]);
        $question = Question::create([
            'school_id' => $school->id,
            'author_id' => $teacher->id,
            'competency_id' => $competency->id,
            'type' => QuestionType::SingleChoice,
            'status' => QuestionStatus::Published,
            'stimulus' => 'Siswa kelas 5 membaca teks.',
            'prompt' => 'Apa kegiatan siswa Kelas 5?',
            'grade_level' => 5,
        ]);
        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out TKA Kelas 5',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);
        $assessment->questions()->attach($question->id, [
            'position' => 1,
            'points' => 1,
            'snapshot' => json_encode(['grade_level' => 5, 'prompt' => $question->prompt]),
        ]);

        $migration = require database_path('migrations/2026_08_12_000100_update_tka_grade_levels.php');
        $migration->up();

        $this->assertSame(6, $student->fresh()->grade_level);
        $this->assertSame(6, $competency->fresh()->grade_level);
        $this->assertSame('LIT6-MIGRASI', $competency->fresh()->code);
        $this->assertSame('Kompetensi Kelas 6', $competency->fresh()->name);
        $this->assertSame(6, $question->fresh()->grade_level);
        $this->assertSame('Siswa kelas 6 membaca teks.', $question->fresh()->stimulus);
        $this->assertSame(6, $assessment->fresh()->grade_level);
        $this->assertSame('Try Out TKA Kelas 6', $assessment->fresh()->title);
        $snapshot = json_decode(DB::table('assessment_question')->where('assessment_id', $assessment->id)->value('snapshot'), true);
        $this->assertSame(6, $snapshot['grade_level']);
    }
}
