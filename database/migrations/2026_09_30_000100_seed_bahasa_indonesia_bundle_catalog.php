<?php

use Database\Seeders\BahasaIndonesiaQuestionTypeSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BahasaIndonesiaQuestionTypeSeeder::class)->run();
    }

    public function down(): void
    {
        // Data katalog dapat sudah dipakai atau disesuaikan di production.
    }
};
