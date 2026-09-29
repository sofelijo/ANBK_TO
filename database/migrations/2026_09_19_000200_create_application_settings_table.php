<?php

use App\Models\Question;
use App\Services\QuestionVerificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $requiredVerifications = DB::table('schools')
            ->orderBy('id')
            ->pluck('settings')
            ->map(fn ($settings) => data_get(json_decode($settings ?? '[]', true), QuestionVerificationService::SETTINGS_KEY))
            ->first(fn ($value) => is_int($value) && $value >= 1)
            ?? Question::REQUIRED_VERIFICATIONS;

        DB::table('application_settings')->insert([
            'key' => QuestionVerificationService::SETTINGS_KEY,
            'value' => (string) $requiredVerifications,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('application_settings');
    }
};
