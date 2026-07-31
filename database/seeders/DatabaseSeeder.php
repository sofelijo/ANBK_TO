<?php

namespace Database\Seeders;

use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $school = School::create([
            'name' => 'Sekolah Demo Nusantara',
            'npsn' => '69999999',
        ]);

        $teacher = User::create([
            'school_id' => $school->id,
            'name' => 'Ibu Guru Demo',
            'email' => 'guru@example.com',
            'password' => 'password',
            'role' => UserRole::Teacher,
            'email_verified_at' => now(),
        ]);

        User::create([
            'school_id' => $school->id,
            'name' => 'Admin Sekolah Demo',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ]);

        $student = User::create([
            'school_id' => $school->id,
            'name' => 'Murid Demo',
            'email' => 'murid@example.com',
            'password' => 'password',
            'role' => UserRole::Student,
            'student_identifier' => 'D-0001',
            'grade_level' => 5,
            'email_verified_at' => now(),
        ]);

        $competencies = collect([
            ['code' => 'LIT5-INFO', 'domain' => 'Literasi', 'name' => 'Menemukan informasi tersurat'],
            ['code' => 'LIT5-INFER', 'domain' => 'Literasi', 'name' => 'Membuat inferensi'],
            ['code' => 'NUM5-DATA', 'domain' => 'Numerasi', 'name' => 'Membaca data dan grafik'],
        ])->mapWithKeys(function (array $data) use ($school): array {
            $competency = Competency::create([
                'school_id' => $school->id,
                ...$data,
                'grade_level' => 5,
            ]);

            return [$data['code'] => $competency];
        });

        $questions = collect([
            [
                'competency' => 'LIT5-INFO',
                'title' => 'Jadwal perpustakaan',
                'stimulus' => 'Perpustakaan sekolah buka Senin–Kamis pukul 07.00–15.00. Pada Jumat, perpustakaan tutup pukul 11.00.',
                'prompt' => 'Pada hari apa perpustakaan memiliki waktu pelayanan paling singkat?',
                'difficulty' => 1,
                'options' => [['Senin', false], ['Rabu', false], ['Kamis', false], ['Jumat', true]],
            ],
            [
                'competency' => 'LIT5-INFER',
                'title' => 'Kebun sekolah',
                'stimulus' => 'Tanaman cabai di kebun sekolah tampak layu. Tanah di sekitarnya kering dan retak. Rani segera mengambil penyiram tanaman.',
                'prompt' => 'Mengapa Rani mengambil penyiram tanaman?',
                'difficulty' => 2,
                'options' => [['Ia ingin membersihkan kebun', false], ['Tanaman kemungkinan kekurangan air', true], ['Ia hendak memindahkan tanaman', false], ['Tanah terlalu basah', false]],
            ],
            [
                'competency' => 'NUM5-DATA',
                'title' => 'Buku yang dipinjam',
                'stimulus' => 'Jumlah buku yang dipinjam: Senin 24 buku, Selasa 31 buku, Rabu 28 buku, Kamis 35 buku.',
                'prompt' => 'Berapa selisih jumlah buku yang dipinjam pada Kamis dan Senin?',
                'difficulty' => 1,
                'options' => [['7 buku', false], ['9 buku', false], ['11 buku', true], ['13 buku', false]],
            ],
            [
                'competency' => 'LIT5-INFO',
                'title' => 'Pengumpulan botol',
                'stimulus' => 'Kelas 5A mengumpulkan botol plastik setiap Selasa dan Kamis. Botol diserahkan ke bank sampah pada Jumat pagi.',
                'prompt' => 'Kapan botol plastik diserahkan ke bank sampah?',
                'difficulty' => 1,
                'options' => [['Selasa pagi', false], ['Kamis pagi', false], ['Jumat pagi', true], ['Jumat sore', false]],
            ],
            [
                'competency' => 'LIT5-INFER',
                'title' => 'Langit mendung',
                'stimulus' => 'Langit berubah gelap dan angin bertiup lebih kencang. Ayah meminta Dika mengangkat pakaian dari jemuran.',
                'prompt' => 'Peristiwa apa yang kemungkinan akan segera terjadi?',
                'difficulty' => 2,
                'options' => [['Matahari semakin terik', false], ['Hujan akan turun', true], ['Angin berhenti total', false], ['Malam segera tiba', false]],
            ],
            [
                'competency' => 'NUM5-DATA',
                'title' => 'Hasil panen',
                'stimulus' => 'Hasil panen kebun: tomat 18 kg, cabai 12 kg, terong 15 kg, dan mentimun 21 kg.',
                'prompt' => 'Berapa jumlah hasil panen tomat dan terong?',
                'difficulty' => 2,
                'options' => [['30 kg', false], ['31 kg', false], ['33 kg', true], ['36 kg', false]],
            ],
        ])->map(function (array $data) use ($school, $teacher, $competencies): Question {
            $question = Question::create([
                'school_id' => $school->id,
                'author_id' => $teacher->id,
                'competency_id' => $competencies[$data['competency']]->id,
                'type' => QuestionType::SingleChoice,
                'status' => QuestionStatus::Published,
                'title' => $data['title'],
                'stimulus' => $data['stimulus'],
                'prompt' => $data['prompt'],
                'explanation' => 'Periksa kembali informasi pada stimulus dan hubungkan dengan pertanyaan.',
                'difficulty' => $data['difficulty'],
                'grade_level' => 5,
                'approved_by' => $teacher->id,
                'approved_at' => now(),
            ]);

            foreach ($data['options'] as $index => [$content, $isCorrect]) {
                $question->options()->create([
                    'label' => chr(65 + $index),
                    'content' => $content,
                    'is_correct' => $isCorrect,
                    'position' => $index + 1,
                ]);
            }

            return $question;
        });

        $assessment = Assessment::create([
            'school_id' => $school->id,
            'created_by' => $teacher->id,
            'title' => 'Try Out ANBK Kelas 5 - Demo',
            'description' => 'Paket singkat untuk mencoba alur pengerjaan dan analisis kompetensi.',
            'grade_level' => 5,
            'duration_minutes' => 30,
            'status' => AssessmentStatus::Published,
        ]);

        $assessment->questions()->attach(
            $questions->take(3)->values()->mapWithKeys(fn (Question $question, int $index): array => [
                $question->id => ['position' => $index + 1, 'points' => 1],
            ])->all(),
        );
    }
}
