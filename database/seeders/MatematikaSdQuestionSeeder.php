<?php

namespace Database\Seeders;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\Competency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MatematikaSdQuestionSeeder extends Seeder
{
    public function run(): void
    {
        Storage::disk('public')->put(
            'question-illustrations/seeded/matematika-sd-piktogram.svg',
            file_get_contents(database_path('seeders/assets/matematika-sd-piktogram.svg')),
        );

        $this->call(MatematikaSdCompetencySeeder::class);

        Subject::query()
            ->where('code', 'MAT')
            ->orderBy('id')
            ->each(function (Subject $subject): void {
                $author = User::query()
                    ->where('school_id', $subject->school_id)
                    ->whereIn('role', ['admin', 'teacher'])
                    ->orderByRaw("case when role = 'admin' then 0 else 1 end")
                    ->orderBy('id')
                    ->first();

                if ($author === null) {
                    $this->command?->warn("Melewati {$subject->name} #{$subject->id}: admin/guru tidak ditemukan.");

                    return;
                }

                DB::transaction(fn () => $this->seedSubject($subject, $author));
            });
    }

    private function seedSubject(Subject $subject, User $author): void
    {
        $competencies = $subject->competencies()
            ->where('grade_level', 6)
            ->whereNotNull('parent_id')
            ->get()
            ->keyBy('code');

        foreach ($this->questions() as $data) {
            $competency = $competencies->get($data['competency_code']);

            if (! $competency instanceof Competency) {
                throw new \RuntimeException("Subkompetensi {$data['competency_code']} tidak ditemukan.");
            }

            $seedKey = "matematika-sd-v1:{$data['competency_code']}";
            $question = Question::query()
                ->where('school_id', $subject->school_id)
                ->where('metadata->seed_key', $seedKey)
                ->first() ?? new Question;

            $question->fill([
                'school_id' => $subject->school_id,
                'author_id' => $author->id,
                'competency_id' => $competency->id,
                'type' => QuestionType::SingleChoice,
                'status' => QuestionStatus::Draft,
                'title' => $data['title'],
                'stimulus' => $data['stimulus'],
                'prompt' => $data['prompt'],
                'explanation' => $data['explanation'],
                'difficulty' => $data['difficulty'],
                'grade_level' => 6,
                'cognitive_level' => $data['cognitive_level'],
                'metadata' => [
                    'seed_key' => $seedKey,
                    'source' => 'Matriks Asesmen Matematika SD Pusmendik',
                    'generation_format' => 'direct',
                    ...($data['illustration'] === null ? [] : ['illustration' => $data['illustration']]),
                ],
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $question->save();

            foreach ($data['options'] as $position => $option) {
                $question->options()->updateOrCreate(
                    ['label' => chr(65 + $position)],
                    [
                        'content' => $option['content'],
                        'is_correct' => $option['correct'],
                        'position' => $position + 1,
                    ],
                );
            }

            $question->options()->whereNotIn('label', ['A', 'B', 'C', 'D'])->delete();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(): array
    {
        return [
            $this->question(
                'NUM6-BIL-PECAHAN-SENILAI',
                'Pecahan Senilai pada Bagian Kebun',
                'Sebanyak 3/4 bagian kebun sekolah telah ditanami sayuran.',
                'Pecahan manakah yang menunjukkan bagian kebun yang sama?',
                ['3/8', '4/6', '6/8', '9/16'],
                2,
                'Pecahan 3/4 senilai dengan 6/8 karena pembilang dan penyebut sama-sama dikalikan 2.',
                1,
            ),
            $this->question(
                'NUM6-BIL-BANDING-URUT-PECAHAN',
                'Mengurutkan Jarak Tempuh',
                'Tiga peserta menempuh bagian lintasan masing-masing 1/2, 3/4, dan 2/3 dari panjang lintasan yang sama.',
                'Urutan bagian lintasan dari yang paling kecil hingga paling besar adalah ...',
                ['1/2, 2/3, 3/4', '1/2, 3/4, 2/3', '2/3, 1/2, 3/4', '3/4, 2/3, 1/2'],
                0,
                'Nilainya adalah 1/2 = 0,5; 2/3 sekitar 0,67; dan 3/4 = 0,75.',
                2,
            ),
            $this->question(
                'NUM6-BIL-RELASI-PECAHAN',
                'Relasi Desimal, Persen, dan Pecahan',
                'Sebanyak 0,35 bagian dari seluruh peserta memilih kegiatan melukis.',
                'Pasangan bentuk bilangan yang setara dengan 0,35 adalah ...',
                ['3,5% dan 7/20', '35% dan 7/20', '35% dan 7/10', '350% dan 7/20'],
                1,
                'Bilangan 0,35 sama dengan 35/100 = 35% = 7/20.',
                2,
            ),
            $this->question(
                'NUM6-BIL-OPERASI-CACAH',
                'Pembagian Pensil untuk Kelas',
                'Sekolah membeli 24 kotak pensil. Setiap kotak berisi 18 pensil. Seluruh pensil dibagikan sama banyak kepada 9 kelas.',
                'Berapa pensil yang diterima setiap kelas?',
                ['42 pensil', '46 pensil', '48 pensil', '54 pensil'],
                2,
                'Jumlah pensil 24 × 18 = 432. Setiap kelas menerima 432 ÷ 9 = 48 pensil.',
                2,
            ),
            $this->question(
                'NUM6-BIL-OPERASI-PECAHAN',
                'Sisa Minuman di Wadah',
                'Sebuah wadah berisi 3 1/2 liter minuman. Sebanyak 1 3/4 liter digunakan untuk kegiatan kelas.',
                'Berapa liter minuman yang tersisa?',
                ['1 1/4 liter', '1 1/2 liter', '1 3/4 liter', '2 1/4 liter'],
                2,
                'Sisa minuman adalah 3 1/2 − 1 3/4 = 3 2/4 − 1 3/4 = 1 3/4 liter.',
                2,
            ),
            $this->question(
                'NUM6-BIL-KPK-FPB',
                'Jadwal Dua Bel Sekolah',
                'Bel A berbunyi setiap 8 menit dan bel B berbunyi setiap 12 menit. Kedua bel berbunyi bersama pada pukul 09.00.',
                'Setelah berapa menit kedua bel akan berbunyi bersama lagi?',
                ['16 menit', '20 menit', '24 menit', '48 menit'],
                2,
                'KPK dari 8 dan 12 adalah 24, sehingga kedua bel berbunyi bersama lagi setelah 24 menit.',
                2,
            ),
            $this->question(
                'BENTUK-BANGUN-DATAR',
                'Mengenali Bangun Datar',
                'Sebuah bangun datar memiliki empat sisi sama panjang. Sudut-sudut yang berhadapan sama besar dan kedua diagonalnya berpotongan tegak lurus. Keempat sudutnya tidak harus siku-siku.',
                'Bangun datar yang paling sesuai dengan ciri tersebut adalah ...',
                ['Persegi panjang', 'Belah ketupat', 'Trapesium', 'Layang-layang'],
                1,
                'Belah ketupat memiliki empat sisi sama panjang, sudut berhadapan sama besar, dan diagonal yang saling tegak lurus.',
                2,
            ),
            $this->question(
                'KONSTRUKSI-BANGUN-RUANG-DAN',
                'Tampak Atas Susunan Kubus',
                'Empat kubus disusun membentuk alas 2 × 2. Satu kubus lagi diletakkan tepat di atas salah satu kubus pada alas.',
                'Jika dilihat tepat dari atas, berapa bidang persegi yang terlihat sebagai jejak susunan tersebut?',
                ['3 bidang', '4 bidang', '5 bidang', '6 bidang'],
                1,
                'Kubus di tingkat atas menutupi jejak kubus yang sama. Tampak atas tetap membentuk alas 2 × 2, yaitu 4 bidang persegi.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-PANJANG',
                'Membagi Pita Sama Panjang',
                'Pita sepanjang 2 m 35 cm dipotong menjadi 5 bagian sama panjang.',
                'Berapa panjang setiap potongan pita?',
                ['45 cm', '47 cm', '52 cm', '57 cm'],
                1,
                'Panjang pita 2 m 35 cm = 235 cm. Setiap bagian panjangnya 235 ÷ 5 = 47 cm.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-SATUAN-PANJANG',
                'Menjumlahkan Ukuran Panjang',
                'Sebuah tali memiliki panjang 3,5 m. Tali lain panjangnya 75 cm.',
                'Berapa panjang kedua tali tersebut jika dinyatakan dalam sentimeter?',
                ['350 cm', '375 cm', '425 cm', '750 cm'],
                2,
                'Panjang 3,5 m = 350 cm. Jadi, panjang seluruhnya 350 + 75 = 425 cm.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-VOLUME',
                'Volume Kotak Pensil',
                'Sebuah kotak berbentuk balok memiliki panjang 8 cm, lebar 5 cm, dan tinggi 4 cm.',
                'Berapa volume kotak tersebut?',
                ['80 cm³', '120 cm³', '160 cm³', '200 cm³'],
                2,
                'Volume balok = panjang × lebar × tinggi = 8 × 5 × 4 = 160 cm³.',
                1,
            ),
            $this->question(
                'NUM6-UKUR-SATUAN-VOLUME',
                'Mengisi Botol Minuman',
                'Tersedia 2,5 liter minuman yang akan dimasukkan ke dalam botol berkapasitas 250 ml hingga penuh.',
                'Berapa botol yang dapat diisi penuh?',
                ['8 botol', '10 botol', '12 botol', '15 botol'],
                1,
                'Sebanyak 2,5 liter = 2.500 ml. Jumlah botol penuh adalah 2.500 ÷ 250 = 10.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-BERAT',
                'Berat Setiap Buah',
                'Enam buah jeruk yang beratnya sama memiliki berat total 2,4 kg.',
                'Berapa berat satu buah jeruk?',
                ['250 gram', '300 gram', '400 gram', '600 gram'],
                2,
                'Berat total 2,4 kg = 2.400 gram. Berat satu jeruk adalah 2.400 ÷ 6 = 400 gram.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-SATUAN-BERAT',
                'Total Berat Belanjaan',
                'Ibu membeli 1,25 kg tepung dan 750 gram gula.',
                'Berapa total berat belanjaan tersebut?',
                ['1,5 kg', '1,75 kg', '2 kg', '2,5 kg'],
                2,
                'Berat 750 gram = 0,75 kg. Jadi totalnya 1,25 + 0,75 = 2 kg.',
                1,
            ),
            $this->question(
                'NUM6-UKUR-WAKTU',
                'Waktu Selesai Kegiatan',
                'Kegiatan kelas dimulai pukul 08.35 dan berlangsung selama 1 jam 45 menit.',
                'Pukul berapa kegiatan tersebut selesai?',
                ['10.10', '10.20', '10.25', '11.20'],
                1,
                'Pukul 08.35 ditambah 1 jam menjadi 09.35, lalu ditambah 45 menit menjadi 10.20.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-SATUAN-WAKTU',
                'Mengubah Hari Menjadi Jam',
                'Sebuah kegiatan berlangsung selama 2 hari 6 jam.',
                'Berapa lama kegiatan tersebut dalam satuan jam?',
                ['30 jam', '48 jam', '52 jam', '54 jam'],
                3,
                'Dua hari sama dengan 2 × 24 = 48 jam. Ditambah 6 jam menjadi 54 jam.',
                1,
            ),
            $this->question(
                'NUM6-UKUR-LAJU',
                'Kecepatan Rata-Rata Bus',
                'Sebuah bus menempuh jarak 150 km dalam waktu 3 jam dengan kecepatan tetap.',
                'Berapa kecepatan rata-rata bus tersebut?',
                ['45 km/jam', '50 km/jam', '55 km/jam', '75 km/jam'],
                1,
                'Kecepatan rata-rata = jarak ÷ waktu = 150 ÷ 3 = 50 km/jam.',
                2,
            ),
            $this->question(
                'KELILING-DAN-LUAS-BANGUN',
                'Luas Taman di Luar Kolam',
                'Sebuah taman berbentuk persegi panjang berukuran 18 m × 12 m. Di dalamnya terdapat kolam berbentuk persegi dengan sisi 6 m.',
                'Berapa luas bagian taman yang tidak ditempati kolam?',
                ['144 m²', '180 m²', '204 m²', '216 m²'],
                1,
                'Luas taman 18 × 12 = 216 m² dan luas kolam 6 × 6 = 36 m². Luas sisanya 216 − 36 = 180 m².',
                2,
            ),
            $this->question(
                'NUM6-UKUR-VOLUME-BANGUN-RUANG',
                'Volume Peti Penyimpanan',
                'Sebuah peti berbentuk balok berukuran panjang 12 dm, lebar 8 dm, dan tinggi 5 dm.',
                'Berapa volume peti tersebut?',
                ['240 dm³', '400 dm³', '480 dm³', '520 dm³'],
                2,
                'Volume balok = 12 × 8 × 5 = 480 dm³.',
                1,
            ),
            $this->question(
                'NUM6-UKUR-SUDUT',
                'Sudut pada Garis Lurus',
                'Dua sudut bersebelahan membentuk sebuah garis lurus. Salah satu sudut besarnya 65°.',
                'Berapa besar sudut yang lain?',
                ['25°', '65°', '105°', '115°'],
                3,
                'Jumlah sudut pada garis lurus adalah 180°. Sudut lainnya 180° − 65° = 115°.',
                2,
            ),
            $this->question(
                'NUM6-UKUR-PENAKSIRAN',
                'Menaksir Jumlah Buku',
                'Sebuah percetakan mengemas 398 buku dalam setiap kelompok. Terdapat 21 kelompok yang sama.',
                'Dengan membulatkan 398 ke ratusan terdekat dan 21 ke puluhan terdekat, berapa taksiran jumlah seluruh buku?',
                ['6.000 buku', '8.000 buku', '8.400 buku', '10.000 buku'],
                1,
                'Bilangan 398 dibulatkan menjadi 400 dan 21 menjadi 20. Taksirannya 400 × 20 = 8.000 buku.',
                2,
            ),
            $this->question(
                'NUM6-DATA-PENYAJIAN',
                'Membaca Piktogram Buku',
                'Perhatikan piktogram jumlah buku yang telah dibaca oleh empat siswa berikut.',
                'Berapa buku yang dibaca Beni?',
                ['9 buku', '16 buku', '20 buku', '24 buku'],
                2,
                'Beni memiliki 5 simbol dan setiap simbol mewakili 4 buku, sehingga 5 × 4 = 20 buku.',
                1,
                [
                    'disk' => 'public',
                    'path' => 'question-illustrations/seeded/matematika-sd-piktogram.svg',
                    'mime_type' => 'image/svg+xml',
                    'alt' => 'Piktogram buku yang dibaca Andi, Beni, Citra, dan Dini; satu ikon mewakili empat buku.',
                ],
            ),
            $this->question(
                'NUM6-DATA-INFORMASI',
                'Menggunakan Data Pengunjung',
                'Data pengunjung perpustakaan selama empat hari adalah: Senin 24 orang, Selasa 32 orang, Rabu 28 orang, dan Kamis 36 orang.',
                'Berapa selisih jumlah pengunjung pada hari paling ramai dan paling sepi?',
                ['8 orang', '10 orang', '12 orang', '16 orang'],
                2,
                'Jumlah terbesar adalah 36 pada Kamis dan terkecil 24 pada Senin. Selisihnya 36 − 24 = 12 orang.',
                2,
            ),
        ];
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function question(
        string $competencyCode,
        string $title,
        string $stimulus,
        string $prompt,
        array $options,
        int $correctIndex,
        string $explanation,
        int $difficulty,
        ?array $illustration = null,
    ): array {
        return [
            'competency_code' => $competencyCode,
            'title' => $title,
            'stimulus' => $stimulus,
            'prompt' => $prompt,
            'options' => array_map(
                fn (string $content, int $index): array => [
                    'content' => $content,
                    'correct' => $index === $correctIndex,
                ],
                $options,
                array_keys($options),
            ),
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'cognitive_level' => $difficulty === 1 ? 'pemahaman' : 'penerapan',
            'illustration' => $illustration,
        ];
    }
}
