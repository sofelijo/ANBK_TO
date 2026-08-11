<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->string('school_npsn', 8);
            $table->date('scheduled_date');
            $table->unsignedTinyInteger('session_number');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['scheduled_date', 'session_number']);
            $table->unique(['assessment_id', 'school_npsn']);
            $table->index(['school_npsn', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_schedules');
    }
};
