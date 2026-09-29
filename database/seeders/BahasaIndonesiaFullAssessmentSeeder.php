<?php

namespace Database\Seeders;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Enums\AssessmentStatus;
use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AiGeneration;
use App\Models\Assessment;
use App\Models\Competency;
use App\Models\Question;
use App\Models\Subject;
use App\Models\User;
use App\Services\QuestionSnapshotService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BahasaIndonesiaFullAssessmentSeeder extends Seeder
{
    private const BUNDLE_KEY = 'bind-kelas-6-lengkap-v1';

    public function run(): void
    {
        $this->call(BahasaIndonesiaQuestionTypeSeeder::class);

        $subject = Subject::query()->where('code', 'BIND')->orderBy('id')->first();

        if (! $subject instanceof Subject) {
            $this->command?->warn('Mata pelajaran BIND tidak ditemukan.');

            return;
        }

        $author = User::query()
            ->where('school_id', $subject->school_id)
            ->whereIn('role', [UserRole::Admin->value, UserRole::Teacher->value])
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $author instanceof User) {
            $this->command?->warn('Admin atau guru untuk membuat paket Bahasa Indonesia tidak ditemukan.');

            return;
        }

        DB::transaction(fn () => $this->seedPackage($subject, $author));
    }

    private function seedPackage(Subject $subject, User $author): void
    {
        $competencies = $subject->competencies()
            ->where('grade_level', 6)
            ->whereNull('parent_id')
            ->with('questionBlueprints')
            ->get()
            ->keyBy('code');
        $questions = collect();

        foreach ($this->stimuli() as $stimulusIndex => $stimulus) {
            $competency = $competencies->get($stimulus['competency_code']);

            if (! $competency instanceof Competency) {
                throw new \RuntimeException("Kompetensi {$stimulus['competency_code']} tidak ditemukan.");
            }

            $generation = $this->seedGeneration(
                $subject,
                $author,
                $competency,
                $stimulusIndex + 1,
                $stimulus['title'],
                $stimulus['text'],
            );
            $bundleQuestions = collect();

            foreach ($stimulus['questions'] as $questionIndex => $specification) {
                $question = $this->seedQuestion(
                    $subject,
                    $author,
                    $competency,
                    $generation,
                    $stimulusIndex + 1,
                    $questionIndex + 1,
                    $stimulus['title'],
                    $stimulus['text'],
                    $specification,
                );
                $questions->push($question);
                $bundleQuestions->push($question);
            }

            $generation->update(['result_payload' => [
                'source' => 'seeder',
                'format' => 'story',
                'title' => $stimulus['title'],
                'story' => $stimulus['text'],
                'question_count' => $bundleQuestions->count(),
                'question_ids' => $bundleQuestions->pluck('id')->all(),
            ]]);
        }

        $assessment = Assessment::query()->firstOrNew([
            'title' => 'Try Out Bahasa Indonesia Kelas 6 – Paket Lengkap',
        ]);
        $assessment->fill([
            'subject_id' => $subject->id,
            'created_by' => $author->id,
            'description' => 'Paket 30 soal dari 10 stimulus yang mencakup seluruh kompetensi Bahasa Indonesia kelas 6.',
            'grade_level' => 6,
            'duration_minutes' => 75,
            'status' => AssessmentStatus::Published,
            'settings' => [
                'type' => Assessment::TYPE_REGULAR,
                'type_label' => 'Try Out Reguler',
                'selection_mode' => 'manual',
                'question_count' => 30,
                'stimulus_count' => 10,
                'candidate_question_ids' => $questions->pluck('id')->all(),
                'shuffle_questions' => false,
                'shuffle_options' => true,
                'show_navigation' => true,
                'require_all_answers' => true,
                'seed_key' => self::BUNDLE_KEY,
            ],
            'competency_slots' => $competencies->values()->pluck('id')->all(),
        ]);
        $assessment->save();
        $assessment->questions()->sync($questions->values()->mapWithKeys(fn (Question $question, int $index): array => [
            $question->id => ['position' => $index + 1, 'points' => 1],
        ])->all());
        app(QuestionSnapshotService::class)->snapshotAssessment($assessment, true);
    }

    private function seedQuestion(
        Subject $subject,
        User $author,
        Competency $competency,
        AiGeneration $generation,
        int $stimulusNumber,
        int $questionNumber,
        string $stimulusTitle,
        string $stimulus,
        array $specification,
    ): Question {
        $seedKey = self::BUNDLE_KEY."-s{$stimulusNumber}-q{$questionNumber}";
        $type = QuestionType::from($specification['type']);
        $blueprints = $competency->questionBlueprints;
        $blueprint = $blueprints->isEmpty() ? null : $blueprints[($questionNumber - 1) % $blueprints->count()];
        $metadata = [
            'seed_key' => $seedKey,
            'bundle_key' => self::BUNDLE_KEY,
            'stimulus_number' => $stimulusNumber,
            'story_generation_id' => $generation->id,
            'generation_format' => 'story',
            'answer_format' => $type === QuestionType::CategoryMatrix ? 'true_false' : $type->value,
        ];

        if ($type === QuestionType::CategoryMatrix) {
            $columns = collect(['Benar', 'Salah'])->map(fn (string $label, int $index): array => [
                'id' => $this->stableUuid("{$seedKey}:column:{$index}"),
                'label' => $label,
            ]);
            $metadata['matrix_columns'] = $columns->all();
            $metadata['matrix_rows'] = collect($specification['rows'])->map(fn (array $row, int $index): array => [
                'id' => $this->stableUuid("{$seedKey}:row:{$index}"),
                'statement' => $row[0],
                'correct_column_id' => $columns[$row[1]]['id'],
            ])->all();
        }

        $question = Question::query()
            ->where('metadata->seed_key', $seedKey)
            ->first() ?? new Question;
        $question->fill([
            'author_id' => $author->id,
            'story_generation_id' => $generation->id,
            'competency_id' => $competency->id,
            'question_blueprint_id' => $blueprint?->id,
            'type' => $type,
            'status' => QuestionStatus::Published,
            'title' => sprintf('Paket Lengkap BIND %02d.%d · %s', $stimulusNumber, $questionNumber, $stimulusTitle),
            'stimulus' => $stimulus,
            'prompt' => $specification['prompt'],
            'explanation' => $specification['explanation'],
            'difficulty' => $specification['difficulty'],
            'grade_level' => 6,
            'cognitive_level' => $specification['cognitive_level'],
            'metadata' => $metadata,
            'approved_by' => $author->id,
            'approved_at' => now(),
        ]);
        $question->save();

        if ($type === QuestionType::CategoryMatrix) {
            $question->options()->delete();
        } else {
            foreach ($specification['options'] as $position => [$content, $isCorrect]) {
                $question->options()->updateOrCreate(
                    ['label' => chr(65 + $position)],
                    ['content' => $content, 'is_correct' => $isCorrect, 'position' => $position + 1],
                );
            }
            $question->options()->whereNotIn('label', ['A', 'B', 'C', 'D'])->delete();
        }

        return $question->load('options');
    }

    private function seedGeneration(
        Subject $subject,
        User $author,
        Competency $competency,
        int $stimulusNumber,
        string $title,
        string $story,
    ): AiGeneration {
        $inputHash = hash('sha256', self::BUNDLE_KEY.":stimulus:{$stimulusNumber}");

        return AiGeneration::query()->updateOrCreate(
            [
                'school_id' => $subject->school_id,
                'input_hash' => $inputHash,
            ],
            [
                'requested_by' => $author->id,
                'type' => AiGenerationType::StoryQuestions,
                'status' => AiGenerationStatus::Completed,
                'provider' => 'seeder',
                'model' => 'static',
                'request_payload' => [
                    'source' => 'seeder',
                    'format' => 'story',
                    'submission_mode' => 'review',
                    'draft_complete' => true,
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'competency_id' => $competency->id,
                    'competency_code' => $competency->code,
                    'competency_name' => $competency->name,
                    'theme' => $title,
                    'paragraph_count' => max(1, count(preg_split('/\R{2,}/u', trim($story)))),
                    'question_count' => 3,
                ],
            ],
        );
    }

    private function stableUuid(string $value): string
    {
        $hash = md5($value);

        return sprintf('%s-%s-4%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    /** @return list<array<string, mixed>> */
    private function stimuli(): array
    {
        return [
            [
                'competency_code' => 'BIND-INFO-DESKRIPSI',
                'title' => 'Jalur Mangrove Teluk Hijau',
                'text' => "Jalur Mangrove Teluk Hijau membentang di atas air payau. Papan-papan kayunya tersusun rapi di antara pohon bakau yang berdaun hijau mengilap. Akar tunjang mencuat seperti kaki-kaki kokoh yang menahan lumpur dan meredam ombak kecil.\n\nSaat pagi, burung kuntul mencari ikan di sela akar. Kepiting kecil bersembunyi ketika langkah pengunjung mendekat. Udara terasa lembap, tetapi angin laut membuat perjalanan tetap sejuk. Di ujung jalur terdapat menara pandang untuk mengamati teluk tanpa mengganggu satwa.",
                'questions' => [
                    $this->choice('single_choice', 'Objek yang digambarkan sebagai “kaki-kaki kokoh” dalam bacaan adalah ...', [['papan jalur', false], ['akar tunjang', true], ['menara pandang', false], ['burung kuntul', false]], 'Ungkapan tersebut digunakan untuk menggambarkan akar tunjang yang mencuat dan menopang pohon bakau.', 1, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua ciri Jalur Mangrove Teluk Hijau yang sesuai dengan bacaan.', [['Berada di lingkungan air payau.', true], ['Memiliki jalur dari papan kayu.', true], ['Udara di sana kering dan panas.', false], ['Terdapat menara untuk mengamati teluk.', true]], 'Bacaan menyebut air payau, papan kayu, udara lembap, dan menara pandang.', 2, 'penerapan'),
                    $this->matrix('Tentukan Benar atau Salah untuk setiap pernyataan berdasarkan bacaan.', [['Kepiting kecil bersembunyi saat pengunjung mendekat.', 0], ['Akar bakau membuat ombak kecil semakin kuat.', 1], ['Burung kuntul mencari ikan pada pagi hari.', 0]], 'Setiap jawaban dapat ditemukan langsung pada rincian deskripsi.', 2),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-PROSEDUR',
                'title' => 'Menanam Kacang Hijau dalam Botol',
                'text' => "Cara Menanam Kacang Hijau dalam Botol Bekas\n\nBahan: botol plastik bersih, kapas, biji kacang hijau, air, dan gunting.\n\nLangkah-langkah:\n1. Mintalah bantuan orang dewasa untuk memotong botol menjadi dua bagian.\n2. Letakkan kapas setebal dua sentimeter pada bagian bawah botol.\n3. Basahi kapas secukupnya; jangan sampai air menggenang.\n4. Susun lima biji kacang hijau di atas kapas dengan jarak yang cukup.\n5. Taruh botol di tempat terang yang tidak terkena matahari terik.\n6. Periksa kapas setiap pagi dan tambahkan air jika mulai kering.",
                'questions' => [
                    $this->choice('single_choice', 'Apa yang harus dilakukan setelah kapas diletakkan di dalam botol?', [['Menyusun biji kacang hijau.', false], ['Membasahi kapas secukupnya.', true], ['Meletakkan botol di bawah matahari terik.', false], ['Memotong kembali bagian bawah botol.', false]], 'Langkah ketiga, setelah meletakkan kapas, adalah membasahinya secukupnya.', 1, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua tindakan yang sesuai dengan petunjuk.', [['Meminta bantuan orang dewasa saat memotong botol.', true], ['Membiarkan air menggenang di dalam botol.', false], ['Memberi jarak antarbiji kacang hijau.', true], ['Memeriksa kelembapan kapas setiap pagi.', true]], 'Petunjuk menekankan keselamatan, jarak biji, dan pemeriksaan kapas; air tidak boleh menggenang.', 2, 'penerapan'),
                    $this->matrix('Nilai setiap perubahan langkah berikut.', [['Menaruh botol di tempat terang sesuai dengan prosedur.', 0], ['Menyiram kembali meskipun kapas masih sangat basah sesuai dengan prosedur.', 1], ['Kapas yang mulai kering perlu ditambah air.', 0]], 'Urutan dan ketepatan tindakan dibandingkan dengan langkah pada stimulus.', 2),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-EKSPLANASI',
                'title' => 'Terbentuknya Hujan',
                'text' => "Panas matahari membuat air dari laut, sungai, dan danau menguap. Uap air yang ringan bergerak naik bersama udara. Semakin tinggi posisinya, suhu udara semakin rendah sehingga uap air mendingin dan berubah menjadi butiran air sangat kecil.\n\nButiran-butiran itu berkumpul membentuk awan. Ketika jumlahnya semakin banyak, butiran air saling bergabung dan menjadi lebih berat. Udara tidak lagi mampu menahannya. Air kemudian jatuh ke permukaan bumi sebagai hujan. Sebagian air meresap ke tanah, sedangkan sebagian lainnya mengalir kembali menuju sungai dan laut.",
                'questions' => [
                    $this->choice('single_choice', 'Mengapa butiran air akhirnya jatuh sebagai hujan?', [['Butiran air terkena panas matahari.', false], ['Butiran air menjadi berat dan tidak lagi tertahan udara.', true], ['Air sungai berhenti mengalir.', false], ['Awan bergerak mendekati laut.', false]], 'Gabungan butiran air menjadi semakin berat hingga udara tidak mampu menahannya.', 2, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua proses yang terjadi sebelum hujan turun.', [['Air permukaan menguap akibat panas matahari.', true], ['Uap air mendingin menjadi butiran kecil.', true], ['Seluruh air langsung meresap ke tanah.', false], ['Butiran air berkumpul membentuk awan.', true]], 'Penguapan, pendinginan, dan pembentukan awan mendahului turunnya hujan.', 2, 'penerapan'),
                    $this->matrix('Tentukan ketepatan hubungan sebab-akibat berikut.', [['Suhu udara yang lebih rendah menyebabkan uap air mendingin.', 0], ['Butiran air jatuh karena menjadi semakin ringan.', 1], ['Panas matahari memicu penguapan air permukaan.', 0]], 'Bacaan menjelaskan urutan sebab-akibat dalam proses terjadinya hujan.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-EKSPOSISI',
                'title' => 'Kebun Sekolah sebagai Ruang Belajar',
                'text' => "Kebun sekolah sebaiknya tidak hanya dipandang sebagai penghias halaman. Kebun dapat menjadi ruang belajar yang menghubungkan berbagai mata pelajaran dengan pengalaman nyata. Saat merawat tanaman, siswa mengamati pertumbuhan, mengukur tinggi batang, mencatat perubahan, dan belajar bertanggung jawab.\n\nProgram kebun juga membantu pengelolaan lingkungan. Daun kering dapat diolah menjadi kompos, sedangkan botol bekas dapat dimanfaatkan sebagai wadah semai. Agar manfaatnya berlangsung lama, sekolah perlu menyusun jadwal perawatan dan membagi tugas secara adil. Dengan pengelolaan yang teratur, kebun menjadi laboratorium kecil yang bermanfaat bagi seluruh warga sekolah.",
                'questions' => [
                    $this->choice('single_choice', 'Gagasan utama penulis dalam teks tersebut adalah ...', [['Kebun hanya diperlukan untuk memperindah halaman.', false], ['Kebun sekolah dapat menjadi ruang belajar yang bermanfaat.', true], ['Seluruh tanaman harus ditanam dalam botol bekas.', false], ['Perawatan kebun hanya menjadi tugas guru.', false]], 'Seluruh alasan dan contoh dalam bacaan mendukung gagasan bahwa kebun adalah ruang belajar.', 2, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua gagasan pendukung yang digunakan penulis.', [['Siswa dapat mengukur dan mencatat pertumbuhan tanaman.', true], ['Daun kering dapat diolah menjadi kompos.', true], ['Kebun tidak membutuhkan jadwal perawatan.', false], ['Botol bekas dapat dimanfaatkan sebagai wadah semai.', true]], 'Ketiga gagasan benar menjadi bukti manfaat kebun bagi pembelajaran dan lingkungan.', 2, 'penerapan'),
                    $this->matrix('Tentukan apakah pernyataan berikut sesuai dengan pendapat penulis.', [['Pembagian tugas membantu keberlanjutan program kebun.', 0], ['Fungsi kebun terbatas sebagai penghias halaman.', 1], ['Kebun dapat menghubungkan pelajaran dengan pengalaman nyata.', 0]], 'Jawaban dinilai berdasarkan kesesuaian dengan argumen dalam teks eksposisi.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-PENGAMATAN',
                'title' => 'Pertumbuhan Bibit Kacang Merah',
                'text' => "Laporan Pengamatan Pertumbuhan Bibit Kacang Merah\n\nObjek: dua bibit kacang merah berumur sama. Bibit A diletakkan di dekat jendela, sedangkan Bibit B disimpan di dalam lemari. Keduanya diberi air sebanyak 20 ml setiap hari.\n\nHari ke-1: A setinggi 3 cm dan hijau; B setinggi 3 cm dan hijau.\nHari ke-4: A setinggi 7 cm dan hijau; B setinggi 9 cm dan hijau pucat.\nHari ke-7: A setinggi 11 cm dengan batang kokoh; B setinggi 14 cm dengan batang tipis dan kekuningan.\n\nBibit B tumbuh lebih tinggi, tetapi kondisinya lebih lemah dibandingkan Bibit A.",
                'questions' => [
                    $this->choice('single_choice', 'Perubahan yang terjadi pada Bibit B dari hari ke-4 hingga hari ke-7 adalah ...', [['Tingginya berkurang dan warnanya menghijau.', false], ['Tingginya bertambah 5 cm dan batangnya menjadi tipis.', true], ['Tingginya tetap dan batangnya semakin kokoh.', false], ['Tingginya bertambah 2 cm dan warnanya tetap hijau.', false]], 'Tinggi Bibit B berubah dari 9 cm menjadi 14 cm dan pada hari ke-7 batangnya tipis.', 2, 'penerapan'),
                    $this->choice('multiple_choice', 'Pilih semua informasi yang didukung oleh hasil pengamatan.', [['Kedua bibit menerima jumlah air yang sama.', true], ['Bibit A memperoleh cahaya dari dekat jendela.', true], ['Bibit B lebih kokoh daripada Bibit A.', false], ['Pada hari pertama kedua bibit memiliki tinggi yang sama.', true]], 'Jumlah air, lokasi Bibit A, dan tinggi awal dinyatakan langsung dalam laporan.', 2, 'pemahaman'),
                    $this->matrix('Bandingkan setiap simpulan dengan data pengamatan.', [['Bibit tanpa cahaya dapat tumbuh lebih tinggi tetapi lebih lemah.', 0], ['Perbedaan kedua bibit disebabkan oleh jumlah air yang berbeda.', 1], ['Pada hari ke-7 Bibit A lebih pendek daripada Bibit B.', 0]], 'Data menunjukkan air dibuat sama, sedangkan tempat dan paparan cahaya berbeda.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-PIDATO',
                'title' => 'Bijak Menggunakan Internet',
                'text' => "Teman-teman yang saya banggakan, internet membantu kita menemukan pengetahuan dan berkomunikasi. Namun, kemudahan itu harus disertai tanggung jawab. Jangan terburu-buru menyebarkan berita sebelum memeriksa sumbernya. Jagalah data pribadi seperti alamat rumah dan kata sandi. Gunakan pula bahasa yang santun karena tulisan kita dapat memengaruhi perasaan orang lain.\n\nMari kita menjadi pengguna internet yang cerdas: berpikir sebelum membagikan, bertanya ketika ragu, dan berhenti sejenak ketika waktu belajar atau beristirahat tiba. Jejak digital tidak mudah hilang. Oleh sebab itu, jadikan setiap unggahan sebagai cerminan sikap baik kita.",
                'questions' => [
                    $this->choice('single_choice', 'Amanat utama pidato tersebut adalah ...', [['Menghindari internet dalam segala keadaan.', false], ['Menggunakan internet secara cerdas dan bertanggung jawab.', true], ['Membagikan semua berita kepada teman.', false], ['Menyimpan seluruh kegiatan di media sosial.', false]], 'Pembicara mengajak pendengar memakai internet secara cerdas, aman, dan santun.', 1, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua tindakan yang sesuai dengan ajakan pembicara.', [['Memeriksa sumber berita sebelum menyebarkannya.', true], ['Menjaga alamat rumah dan kata sandi.', true], ['Menulis komentar tanpa memikirkan perasaan orang lain.', false], ['Berhenti menggunakan internet ketika waktu istirahat tiba.', true]], 'Ketiga tindakan benar disebutkan sebagai bentuk tanggung jawab digital.', 2, 'penerapan'),
                    $this->matrix('Tentukan kesesuaian setiap pernyataan dengan isi pidato.', [['Ungkapan “berpikir sebelum membagikan” berarti mempertimbangkan dampak unggahan.', 0], ['Jejak digital akan selalu hilang dalam waktu singkat.', 1], ['Bahasa santun diperlukan saat berkomunikasi di internet.', 0]], 'Pidato menekankan pertimbangan dampak, jejak digital, dan kesantunan.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-INFO-SURAT-PRIBADI',
                'title' => 'Surat untuk Nisa',
                'text' => "Bandung, 12 Agustus 2026\n\nUntuk Nisa, sahabatku,\n\nApa kabarmu di Surabaya? Minggu lalu sekolahku membuka perpustakaan baru. Ruangannya lebih terang dan memiliki sudut baca berbentuk rumah pohon. Aku menjadi anggota tim kecil yang menata buku cerita berdasarkan tema. Awalnya kami kewalahan, tetapi pustakawan mengajari kami membuat label warna. Kini pengunjung lebih mudah menemukan buku.\n\nAku teringat kegemaranmu membaca cerita petualangan. Kalau kamu berkunjung saat liburan, kita bisa membaca di sudut favoritku itu. Ceritakan juga kegiatan barumu, ya.\n\nSalam hangat,\nAlya",
                'questions' => [
                    $this->choice('single_choice', 'Tujuan utama Alya menulis surat adalah ...', [['Meminta Nisa mengirimkan label warna.', false], ['Menceritakan perpustakaan baru dan mengajak Nisa berkunjung.', true], ['Melaporkan pekerjaan pustakawan kepada kepala sekolah.', false], ['Meminjam buku petualangan milik Nisa.', false]], 'Isi surat berpusat pada pengalaman di perpustakaan serta ajakan kepada Nisa.', 2, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua informasi yang terdapat dalam surat.', [['Alya membantu menata buku berdasarkan tema.', true], ['Sudut baca berbentuk rumah pohon.', true], ['Nisa gemar membaca cerita petualangan.', true], ['Perpustakaan baru berada di Surabaya.', false]], 'Perpustakaan berada di sekolah Alya di Bandung, sedangkan Nisa tinggal di Surabaya.', 2, 'penerapan'),
                    $this->matrix('Tentukan Benar atau Salah berdasarkan surat tersebut.', [['Label warna membantu pengunjung menemukan buku.', 0], ['Alya dan timnya langsung mampu menata buku tanpa bantuan.', 1], ['Ungkapan “kami kewalahan” menunjukkan mereka sempat kesulitan.', 0]], 'Konteks surat menunjukkan kesulitan awal yang terbantu oleh sistem label warna.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-FIKSI-FABEL',
                'title' => 'Rangkong dan Tupai',
                'text' => "Di tepi hutan, Tupai menemukan pohon ara yang penuh buah. Ia segera menandai batangnya dan berkata kepada semua hewan bahwa pohon itu miliknya. Rangkong mengingatkan bahwa buah ara menjadi makanan banyak penghuni hutan, tetapi Tupai tidak peduli.\n\nMalamnya hujan deras menggoyangkan dahan. Tupai berusaha mengumpulkan semua buah ke sarangnya. Sarang itu tidak kuat menahan beban dan hampir jatuh. Rangkong memanggil hewan-hewan lain untuk membantu memindahkan buah dan memperkuat sarang.\n\nTupai merasa malu. Keesokan harinya ia membagikan buah ara kepada semua hewan. “Ternyata yang dibagi secukupnya lebih bermanfaat daripada yang ditimbun berlebihan,” katanya.",
                'questions' => [
                    $this->choice('single_choice', 'Amanat yang paling tepat dari fabel tersebut adalah ...', [['Kita harus menyimpan seluruh makanan sendiri.', false], ['Berbagi dan saling membantu membawa manfaat bersama.', true], ['Pohon yang tinggi selalu berbahaya.', false], ['Hujan membuat semua hewan kehilangan makanan.', false]], 'Perubahan sikap Tupai menunjukkan pentingnya berbagi dan menerima bantuan.', 2, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua tindakan yang menunjukkan sifat suka menolong.', [['Rangkong memperingatkan Tupai tentang kebutuhan hewan lain.', true], ['Tupai mengaku bahwa pohon ara hanya miliknya.', false], ['Rangkong memanggil hewan lain untuk memperkuat sarang.', true], ['Hewan-hewan membantu memindahkan buah.', true]], 'Peringatan, mengajak bantuan, dan memindahkan buah merupakan tindakan peduli.', 2, 'penerapan'),
                    $this->matrix('Nilai setiap pernyataan tentang tokoh dan peristiwa.', [['Pada awal cerita Tupai bersikap serakah.', 0], ['Rangkong meninggalkan Tupai saat sarangnya hampir jatuh.', 1], ['Tupai berubah setelah mengalami akibat dari tindakannya.', 0]], 'Watak tokoh disimpulkan dari tindakan mereka sepanjang cerita.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-FIKSI-PUISI',
                'title' => 'Pasar Pagi',
                'text' => "Pasar Pagi\n\nMentari mengintip di balik atap,\nroda gerobak mulai berderak.\nWangi rempah menari di udara,\nsapa pedagang menghangatkan suasana.\n\nIbu memilih sayur yang segar,\naku membawa keranjang dengan sabar.\nDi antara warna, suara, dan langkah,\npagi tumbuh menjadi kisah yang ramah.",
                'questions' => [
                    $this->choice('single_choice', 'Makna larik “mentari mengintip di balik atap” adalah ...', [['Matahari mulai tampak pada pagi hari.', true], ['Atap pasar sedang berlubang.', false], ['Pedagang bersembunyi dari matahari.', false], ['Hari segera berubah menjadi malam.', false]], 'Kata “mengintip” menggambarkan matahari yang mulai muncul dari balik atap.', 2, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua citraan yang terdapat dalam puisi.', [['Bunyi roda gerobak yang berderak.', true], ['Wangi rempah di udara.', true], ['Dinginnya salju di halaman.', false], ['Warna dan gerak di pasar.', true]], 'Puisi menghadirkan citraan pendengaran, penciuman, dan penglihatan.', 2, 'penerapan'),
                    $this->matrix('Tentukan ketepatan penafsiran berikut.', [['Suasana puisi terasa hidup dan ramah.', 0], ['Kata “menari” berarti rempah benar-benar memiliki kaki.', 1], ['Tokoh aku membantu Ibu membawa keranjang.', 0]], 'Bahasa kias “menari” menggambarkan aroma yang menyebar, bukan gerakan sebenarnya.', 3),
                ],
            ],
            [
                'competency_code' => 'BIND-FIKSI-CERITA-ANAK',
                'title' => 'Penyaring Air Kelompok Raka',
                'text' => "Kelompok Raka membuat penyaring air untuk pameran sains. Mereka menyusun kerikil, pasir, arang, dan kapas di dalam botol. Saat diuji, air masih tampak keruh. Dimas langsung ingin mengganti seluruh bahan, tetapi Raka mengajak kelompoknya memeriksa catatan.\n\nSiti menemukan bahwa lapisan pasir mereka jauh lebih tipis daripada rancangan. Mereka membongkar alat dengan hati-hati, menambah pasir, lalu menguji kembali. Air yang keluar menjadi lebih jernih. Raka mengingatkan bahwa air hasil saringan tetap tidak boleh diminum tanpa proses lanjutan.\n\nPada hari pameran, kelompok itu tidak hanya menunjukkan alat yang berhasil. Mereka juga memasang catatan kegagalan pertama agar pengunjung memahami pentingnya mencoba, mengamati, dan memperbaiki.",
                'questions' => [
                    $this->choice('single_choice', 'Tindakan yang menyelesaikan masalah kelompok Raka adalah ...', [['Mengganti semua bahan tanpa memeriksa alat.', false], ['Menambah lapisan pasir sesuai rancangan.', true], ['Meminum air hasil penyaringan.', false], ['Menyembunyikan catatan kegagalan.', false]], 'Kelompok memperbaiki ketebalan pasir setelah membandingkan alat dengan rancangan.', 1, 'pemahaman'),
                    $this->choice('multiple_choice', 'Pilih semua sifat Raka yang tampak dalam cerita.', [['Teliti karena mengajak kelompok memeriksa catatan.', true], ['Bertanggung jawab karena mengingatkan batas keamanan alat.', true], ['Mudah menyerah setelah pengujian pertama.', false], ['Terbuka terhadap proses belajar dari kegagalan.', true]], 'Tindakan Raka menunjukkan ketelitian, tanggung jawab, dan kemauan belajar.', 3, 'penalaran'),
                    $this->matrix('Tentukan kesesuaian ikhtisar peristiwa berikut.', [['Uji pertama gagal, kelompok memeriksa catatan, lalu memperbaiki lapisan pasir.', 0], ['Kelompok langsung berhasil tanpa melakukan perubahan.', 1], ['Catatan kegagalan dipamerkan sebagai bagian dari pembelajaran.', 0]], 'Urutan peristiwa menunjukkan proses mencoba, mengevaluasi, memperbaiki, dan berbagi pembelajaran.', 3),
                ],
            ],
        ];
    }

    private function choice(
        string $type,
        string $prompt,
        array $options,
        string $explanation,
        int $difficulty,
        string $cognitiveLevel,
    ): array {
        return compact('type', 'prompt', 'options', 'explanation', 'difficulty') + [
            'cognitive_level' => $cognitiveLevel,
        ];
    }

    private function matrix(string $prompt, array $rows, string $explanation, int $difficulty): array
    {
        return [
            'type' => QuestionType::CategoryMatrix->value,
            'prompt' => $prompt,
            'rows' => $rows,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'cognitive_level' => 'penalaran',
        ];
    }
}
