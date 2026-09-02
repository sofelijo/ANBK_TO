<?php

namespace Database\Seeders;

use App\Models\Competency;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BahasaIndonesiaQuestionTypeSeeder extends Seeder
{
    public function run(): void
    {
        Subject::query()
            ->where('code', 'BIND')
            ->orderBy('id')
            ->each(fn (Subject $subject) => DB::transaction(
                fn () => $this->seedSubject($subject)
            ));
    }

    private function seedSubject(Subject $subject): void
    {
        $competencies = collect($this->competencies())->mapWithKeys(function (array $data) use ($subject): array {
            $legacyCodes = $data['legacy_codes'] ?? [];
            unset($data['legacy_codes']);

            $competency = Competency::query()
                ->where('school_id', $subject->school_id)
                ->where('subject_id', $subject->id)
                ->where(fn ($query) => $query
                    ->where('code', $data['code'])
                    ->when($legacyCodes !== [], fn ($query) => $query->orWhereIn('code', $legacyCodes)))
                ->first() ?? new Competency;

            $competency->fill([
                'school_id' => $subject->school_id,
                'subject_id' => $subject->id,
                'parent_id' => null,
                'grade_level' => 6,
                ...$data,
            ])->save();

            return [$data['code'] => $competency];
        });

        foreach ($this->questionTypes() as $data) {
            $competencyCodes = $data['competencies'];
            $legacyCodes = $data['legacy_codes'] ?? [];
            unset($data['competencies'], $data['legacy_codes']);

            $blueprint = QuestionBlueprint::query()
                ->where('school_id', $subject->school_id)
                ->where('subject_id', $subject->id)
                ->where(fn ($query) => $query
                    ->where('code', $data['code'])
                    ->when($legacyCodes !== [], fn ($query) => $query->orWhereIn('code', $legacyCodes)))
                ->first() ?? new QuestionBlueprint;

            $blueprint->fill([
                'school_id' => $subject->school_id,
                'subject_id' => $subject->id,
                ...$data,
            ])->save();

            $blueprint->competencies()->sync(
                collect($competencyCodes)->values()->mapWithKeys(fn (string $code, int $index): array => [
                    $competencies[$code]->id => ['position' => $index + 1],
                ])->all(),
            );
        }

        $this->mergeObsoleteBlueprints($subject);
    }

    private function mergeObsoleteBlueprints(Subject $subject): void
    {
        $canonical = QuestionBlueprint::query()
            ->where('school_id', $subject->school_id)
            ->where('subject_id', $subject->id)
            ->where('code', 'IDE-POKOK')
            ->first();

        if (! $canonical) {
            return;
        }

        QuestionBlueprint::query()
            ->where('school_id', $subject->school_id)
            ->where('subject_id', $subject->id)
            ->where('code', 'IDEPOKOK')
            ->whereKeyNot($canonical->id)
            ->get()
            ->each(function (QuestionBlueprint $obsolete) use ($canonical): void {
                Question::query()
                    ->where('question_blueprint_id', $obsolete->id)
                    ->update(['question_blueprint_id' => $canonical->id]);
                $obsolete->delete();
            });
    }

    private function competencies(): array
    {
        return [
            ['code' => 'BIND-INFO-DESKRIPSI', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Deskripsi', 'legacy_codes' => ['LIT6-INFO']],
            ['code' => 'BIND-INFO-PROSEDUR', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Prosedur'],
            ['code' => 'BIND-INFO-EKSPLANASI', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Eksplanasi'],
            ['code' => 'BIND-INFO-EKSPOSISI', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Eksposisi'],
            ['code' => 'BIND-INFO-PENGAMATAN', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Hasil Pengamatan'],
            ['code' => 'BIND-INFO-PIDATO', 'domain' => 'Informasi', 'name' => 'Informasi – Teks Pidato'],
            ['code' => 'BIND-INFO-SURAT-PRIBADI', 'domain' => 'Informasi', 'name' => 'Informasi – Surat Pribadi'],
            ['code' => 'BIND-FIKSI-FABEL', 'domain' => 'Fiksi', 'name' => 'Fiksi – Fabel'],
            ['code' => 'BIND-FIKSI-PUISI', 'domain' => 'Fiksi', 'name' => 'Fiksi – Puisi'],
            ['code' => 'BIND-FIKSI-CERITA-ANAK', 'domain' => 'Fiksi', 'name' => 'Fiksi – Cerita Anak', 'legacy_codes' => ['LIT6-INFER']],
        ];
    }

    private function questionTypes(): array
    {
        return [
            ['code' => 'OBJEK-KOSAKATA', 'name' => 'Menentukan objek berdasarkan kosakata', 'description' => 'Menentukan objek atau ciri berdasarkan kosakata yang digunakan dalam teks.', 'competencies' => ['BIND-INFO-DESKRIPSI']],
            ['code' => 'INFO-TERSURAT', 'name' => 'Menemukan informasi tersurat', 'description' => 'Menemukan informasi yang dinyatakan secara langsung dalam teks.', 'competencies' => ['BIND-INFO-DESKRIPSI', 'BIND-INFO-PROSEDUR', 'BIND-INFO-EKSPOSISI', 'BIND-INFO-PENGAMATAN', 'BIND-INFO-PIDATO', 'BIND-INFO-SURAT-PRIBADI']],
            ['code' => 'IDE-POKOK', 'name' => 'Menentukan ide pokok', 'description' => 'Menentukan gagasan utama paragraf atau keseluruhan teks.', 'competencies' => ['BIND-INFO-DESKRIPSI'], 'legacy_codes' => ['IDEPOKOK']],
            ['code' => 'SIMPUL-PERUBAHAN-KONDISI', 'name' => 'Menyimpulkan perubahan kondisi', 'description' => 'Menyimpulkan perubahan keadaan berdasarkan urutan langkah atau peristiwa.', 'competencies' => ['BIND-INFO-PROSEDUR']],
            ['code' => 'RELEVANSI-SEHARI-HARI', 'name' => 'Mengaitkan isi dengan kehidupan sehari-hari', 'description' => 'Menilai hubungan atau penerapan isi teks dalam kehidupan sehari-hari.', 'competencies' => ['BIND-INFO-PROSEDUR', 'BIND-FIKSI-CERITA-ANAK']],
            ['code' => 'KOSAKATA-UMUM-KHUSUS', 'name' => 'Memaknai kosakata umum dan khusus', 'description' => 'Menentukan makna dan hubungan antara kosakata umum dengan kosakata khusus.', 'competencies' => ['BIND-INFO-EKSPLANASI', 'BIND-INFO-SURAT-PRIBADI']],
            ['code' => 'RINGKAS-INFORMASI', 'name' => 'Meringkas informasi', 'description' => 'Merangkum informasi penting dalam teks secara ringkas dan runtut.', 'competencies' => ['BIND-INFO-EKSPLANASI']],
            ['code' => 'SIMPUL-ISI-BACAAN', 'name' => 'Menyimpulkan isi bacaan', 'description' => 'Menarik simpulan berdasarkan keseluruhan informasi dalam bacaan.', 'competencies' => ['BIND-INFO-EKSPLANASI']],
            ['code' => 'GAGASAN-PENDUKUNG', 'name' => 'Menentukan gagasan pendukung', 'description' => 'Menentukan gagasan yang menjelaskan atau memperkuat gagasan utama.', 'competencies' => ['BIND-INFO-EKSPOSISI']],
            ['code' => 'KESESUAIAN-INFORMASI', 'name' => 'Menilai kesesuaian antar-informasi', 'description' => 'Menilai keselarasan, hubungan, atau pertentangan antar-informasi dalam teks.', 'competencies' => ['BIND-INFO-EKSPOSISI', 'BIND-INFO-PENGAMATAN']],
            ['code' => 'IDENTIFIKASI-PERUBAHAN-KONDISI', 'name' => 'Mengidentifikasi perubahan kondisi', 'description' => 'Mengidentifikasi perubahan keadaan yang disajikan dalam teks hasil pengamatan.', 'competencies' => ['BIND-INFO-PENGAMATAN']],
            ['code' => 'AMANAT', 'name' => 'Menentukan amanat', 'description' => 'Menentukan pesan yang ingin disampaikan melalui teks.', 'competencies' => ['BIND-INFO-PIDATO']],
            ['code' => 'MAKNA-UNGKAPAN', 'name' => 'Memaknai ungkapan', 'description' => 'Menentukan makna ungkapan berdasarkan konteks penggunaannya.', 'competencies' => ['BIND-INFO-PIDATO', 'BIND-INFO-SURAT-PRIBADI', 'BIND-FIKSI-FABEL', 'BIND-FIKSI-PUISI']],
            ['code' => 'INTI-CERITA', 'name' => 'Menentukan inti cerita', 'description' => 'Menentukan pokok cerita berdasarkan rangkaian peristiwa utama.', 'competencies' => ['BIND-FIKSI-FABEL']],
            ['code' => 'RESPONS-EMOSIONAL', 'name' => 'Menentukan respons emosional terhadap cerita', 'description' => 'Menentukan perasaan atau respons yang sesuai terhadap peristiwa dalam cerita.', 'competencies' => ['BIND-FIKSI-FABEL']],
            ['code' => 'MAKNA-KOSAKATA', 'name' => 'Memaknai kosakata', 'description' => 'Menentukan makna kosakata berdasarkan konteks puisi.', 'competencies' => ['BIND-FIKSI-PUISI']],
            ['code' => 'IDENTIFIKASI-TOKOH', 'name' => 'Mengidentifikasi tokoh', 'description' => 'Mengidentifikasi tokoh yang dinyatakan atau digambarkan dalam puisi.', 'competencies' => ['BIND-FIKSI-PUISI']],
            ['code' => 'IKHTISAR-BAGAN', 'name' => 'Merangkum informasi dalam ikhtisar atau bagan', 'description' => 'Menyajikan kembali informasi cerita dalam bentuk ikhtisar atau bagan.', 'competencies' => ['BIND-FIKSI-CERITA-ANAK']],
            ['code' => 'KARAKTER-TOKOH', 'name' => 'Menentukan karakter tokoh', 'description' => 'Menentukan sifat atau karakter tokoh berdasarkan perkataan, tindakan, dan peristiwa.', 'competencies' => ['BIND-FIKSI-CERITA-ANAK']],
        ];
    }
}
