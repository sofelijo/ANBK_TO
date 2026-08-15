<?php

namespace Database\Seeders;

use App\Models\Competency;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MatematikaSdCompetencySeeder extends Seeder
{
    public function run(): void
    {
        Subject::query()
            ->where('code', 'MAT')
            ->orderBy('id')
            ->each(fn (Subject $subject) => DB::transaction(
                fn () => $this->seedSubject($subject)
            ));
    }

    private function seedSubject(Subject $subject): void
    {
        foreach ($this->competencies() as $parentData) {
            $children = $parentData['children'];
            unset($parentData['children']);

            $parent = $this->upsertCompetency($subject, null, $parentData);

            foreach ($children as $childData) {
                $this->upsertCompetency($subject, $parent, [
                    ...$childData,
                    'domain' => $parentData['domain'],
                ]);
            }
        }
    }

    /**
     * @param  array{code: string, domain: string, name: string, aliases?: list<string>}  $data
     */
    private function upsertCompetency(Subject $subject, ?Competency $parent, array $data): Competency
    {
        $aliases = $data['aliases'] ?? [];
        unset($data['aliases']);

        $competency = Competency::query()
            ->where('school_id', $subject->school_id)
            ->where('subject_id', $subject->id)
            ->where('grade_level', 6)
            ->where(function ($query) use ($data, $aliases): void {
                $query->where('code', $data['code'])
                    ->orWhere('name', $data['name']);

                if ($aliases !== []) {
                    $query->orWhereIn('name', $aliases);
                }
            })
            ->first();

        $competency ??= new Competency;
        $competency->fill([
            'school_id' => $subject->school_id,
            'subject_id' => $subject->id,
            'parent_id' => $parent?->id,
            'grade_level' => 6,
            ...$data,
        ]);
        $competency->save();

        return $competency;
    }

    /**
     * @return list<array{
     *     code: string,
     *     domain: string,
     *     name: string,
     *     aliases?: list<string>,
     *     children: list<array{code: string, name: string, aliases?: list<string>}>
     * }>
     */
    private function competencies(): array
    {
        return [
            [
                'code' => 'NUM6-BILANGAN',
                'domain' => 'Bilangan',
                'name' => 'Bilangan Rasional',
                'children' => [
                    [
                        'code' => 'NUM6-BIL-PECAHAN-SENILAI',
                        'name' => 'Pecahan senilai menggunakan gambar dan simbol matematika',
                        'aliases' => ['Pecahan sinilai menggunakan gambar dan simbol matematika'],
                    ],
                    ['code' => 'NUM6-BIL-BANDING-URUT-PECAHAN', 'name' => 'Perbandingan dan pengurutan bilangan pecahan'],
                    ['code' => 'NUM6-BIL-RELASI-PECAHAN', 'name' => 'Relasi berbagai bentuk pecahan (pecahan sederhana, desimal, persen)'],
                    ['code' => 'NUM6-BIL-OPERASI-CACAH', 'name' => 'Operasi penjumlahan, pengurangan, perkalian, dan pembagian bilangan cacah'],
                    ['code' => 'NUM6-BIL-OPERASI-PECAHAN', 'name' => 'Operasi penjumlahan dan pengurangan bilangan pecahan, serta operasi perkalian dan pembagian bilangan pecahan dengan bilangan asli'],
                    ['code' => 'NUM6-BIL-KPK-FPB', 'name' => 'Kelipatan, faktor, KPK, dan FPB bilangan asli'],
                ],
            ],
            [
                'code' => 'OBJEK-GEOMETRI',
                'domain' => 'Geometri dan Pengukuran',
                'name' => 'Objek Geometri',
                'children' => [
                    ['code' => 'BENTUK-BANGUN-DATAR', 'name' => 'Bentuk bangun datar'],
                    ['code' => 'KONSTRUKSI-BANGUN-RUANG-DAN', 'name' => 'Konstruksi bangun ruang dan visualisasi spasial (bagian depan, atas, dan samping).'],
                ],
            ],
            [
                'code' => 'PENGUKURAN',
                'domain' => 'Geometri dan Pengukuran',
                'name' => 'Pengukuran',
                'children' => [
                    ['code' => 'NUM6-UKUR-PANJANG', 'name' => 'Panjang benda menggunakan satuan baku'],
                    ['code' => 'NUM6-UKUR-SATUAN-PANJANG', 'name' => 'Hubungan antar-satuan baku panjang (mm, dm, cm, m, dam, hm, km)'],
                    ['code' => 'NUM6-UKUR-VOLUME', 'name' => 'Volume benda menggunakan satuan baku'],
                    ['code' => 'NUM6-UKUR-SATUAN-VOLUME', 'name' => 'Hubungan antar-satuan baku volume (ml, dl, cl, l, dal, hl, kl)'],
                    ['code' => 'NUM6-UKUR-BERAT', 'name' => 'Berat benda menggunakan satuan baku'],
                    ['code' => 'NUM6-UKUR-SATUAN-BERAT', 'name' => 'Hubungan antar-satuan baku berat (mg, dg, cg, g, dag, hg, kg)'],
                    ['code' => 'NUM6-UKUR-WAKTU', 'name' => 'Waktu'],
                    ['code' => 'NUM6-UKUR-SATUAN-WAKTU', 'name' => 'Hubungan antar-satuan waktu (detik, menit, jam, hari, pekan, bulan, tahun)'],
                    ['code' => 'NUM6-UKUR-LAJU', 'name' => 'Laju perubahan (kecepatan)'],
                    ['code' => 'KELILING-DAN-LUAS-BANGUN', 'name' => 'Keliling dan luas bangun datar (segitiga, segiempat, dan segi banyak)'],
                    ['code' => 'NUM6-UKUR-VOLUME-BANGUN-RUANG', 'name' => 'Volume bangun ruang (kubus, balok, dan gabungannya)'],
                    ['code' => 'NUM6-UKUR-SUDUT', 'name' => 'Besar sudut'],
                    ['code' => 'NUM6-UKUR-PENAKSIRAN', 'name' => 'Penaksiran ukuran'],
                ],
            ],
            [
                'code' => 'NUM6-PENYAJIAN-DATA',
                'domain' => 'Data',
                'name' => 'Penyajian dan Penggunaan Data',
                'children' => [
                    ['code' => 'NUM6-DATA-PENYAJIAN', 'name' => 'Penyajian data (gambar, piktogram, diagram batang, dan tabel frekuensi)'],
                    ['code' => 'NUM6-DATA-INFORMASI', 'name' => 'Pengambilan informasi dan penggunaan data'],
                ],
            ],
        ];
    }
}
