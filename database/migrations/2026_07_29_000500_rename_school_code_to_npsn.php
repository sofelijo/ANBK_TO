<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->renameColumn('code', 'npsn');
        });

        DB::table('schools')
            ->where('npsn', 'DEMO-001')
            ->update(['npsn' => '69999999']);
    }

    public function down(): void
    {
        DB::table('schools')
            ->where('npsn', '69999999')
            ->update(['npsn' => 'DEMO-001']);

        Schema::table('schools', function (Blueprint $table) {
            $table->renameColumn('npsn', 'code');
        });
    }
};
