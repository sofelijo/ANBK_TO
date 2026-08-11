<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'name']);
        });

        Schema::table('competencies', function (Blueprint $table) {
            $table->foreignId('subject_id')
                ->nullable()
                ->after('school_id')
                ->constrained('subjects')
                ->restrictOnDelete();
            $table->index(['subject_id', 'grade_level']);
        });

        $subjects = [];
        DB::table('competencies')
            ->select(['id', 'school_id', 'domain'])
            ->orderBy('id')
            ->chunkById(100, function ($competencies) use (&$subjects): void {
                foreach ($competencies as $competency) {
                    [$code, $name] = $this->subjectIdentity((string) $competency->domain);
                    $schoolKey = $competency->school_id ?? 'global';
                    $key = "{$schoolKey}:{$code}";

                    $subjectId = $subjects[$key] ??= DB::table('subjects')->insertGetId([
                        'school_id' => $competency->school_id,
                        'code' => $code,
                        'name' => $name,
                        'description' => "Hasil migrasi dari domain {$competency->domain}.",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('competencies')->where('id', $competency->id)->update([
                        'subject_id' => $subjectId,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('competencies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
        });

        Schema::dropIfExists('subjects');
    }

    private function subjectIdentity(string $domain): array
    {
        $normalized = Str::lower($domain);

        if (Str::contains($normalized, ['numerasi', 'matematika'])) {
            return ['MAT', 'Matematika'];
        }

        if (Str::contains($normalized, ['literasi', 'bahasa indonesia'])) {
            return ['BIND', 'Bahasa Indonesia'];
        }

        $name = Str::squish($domain) ?: 'Mata Pelajaran Umum';
        $code = Str::upper(Str::limit(Str::slug($name, ''), 20, '')) ?: 'UMUM';

        return [$code, $name];
    }
};
