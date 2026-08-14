<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('censored_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed 10 varied default responses from ASKA
        $now = now();
        $defaultResponses = [
            'Menurut ASKA, itu kata-kata yang kurang sopan. Yuk gunakan bahasa yang ramah dan santun saat belajar!',
            'Menurut ASKA, sebaiknya kita tidak menggunakan kata-kata kasar ya. Mari fokus belajar dengan kata-kata yang positif!',
            'Kata yang kamu gunakan termasuk kurang sopan menurut ASKA. Lebih baik gunakan bahasa yang sopan dan santun ya!',
            'ASKA menyarankan untuk selalu memilih kata-kata yang baik. Bahasa yang santun akan membuat suasana belajar lebih menyenangkan!',
            'Menurut ASKA, mari kita saling menghargai dengan menggunakan kata-kata yang positif dan santun saat berdialog!',
            'ASKA percaya kamu siswa yang hebat dan bisa menggunakan tutur kata yang baik. Yuk tanyakan hal yang ingin kamu pelajari dengan sopan!',
            'Kata-kata tersebut kurang pantas digunakan saat belajar. Menurut ASKA, mari membiasakan diri berkata-kata yang santun!',
            'ASKA ingin menciptakan ruang belajar yang nyaman untukmu. Mari hindari kata-kata tidak baik dan gunakan bahasa yang ramah ya!',
            'Menurut ASKA, kata-kata yang baik mencerminkan karakter yang baik. Yuk pilih kata-kata yang sopan saat bertanya!',
            'ASKA selalu siap mendampingi kamu belajar, tapi ingat untuk selalu gunakan bahasa yang santun ya!',
        ];

        $records = array_map(fn ($text) => [
            'school_id' => null,
            'created_by' => null,
            'response_text' => $text,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $defaultResponses);

        DB::table('censored_responses')->insert($records);
    }

    public function down(): void
    {
        Schema::dropIfExists('censored_responses');
    }
};
