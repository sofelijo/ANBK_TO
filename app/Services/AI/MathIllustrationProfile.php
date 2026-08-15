<?php

namespace App\Services\AI;

class MathIllustrationProfile
{
    /**
     * Visual requirements for every Matematika SD subcompetency.
     *
     * required: the competency is normally assessed through a visual.
     * optional: a visual can help, but the competency can be assessed accurately without it.
     * none: a visual is normally unnecessary.
     */
    private const PROFILES = [
        'NUM6-BIL-PECAHAN-SENILAI' => ['need' => 'required', 'types' => ['fraction_models']],
        'NUM6-BIL-BANDING-URUT-PECAHAN' => ['need' => 'optional', 'types' => ['fraction_models']],
        'NUM6-BIL-RELASI-PECAHAN' => ['need' => 'optional', 'types' => ['fraction_models']],
        'NUM6-BIL-OPERASI-CACAH' => ['need' => 'none', 'types' => ['object_groups']],
        'NUM6-BIL-OPERASI-PECAHAN' => ['need' => 'optional', 'types' => ['fraction_models']],
        'NUM6-BIL-KPK-FPB' => ['need' => 'none', 'types' => ['object_groups']],
        'BENTUK-BANGUN-DATAR' => ['need' => 'required', 'types' => ['geometry_2d']],
        'KONSTRUKSI-BANGUN-RUANG-DAN' => ['need' => 'required', 'types' => ['spatial_cubes']],
        'NUM6-UKUR-PANJANG' => ['need' => 'required', 'types' => ['measurement']],
        'NUM6-UKUR-SATUAN-PANJANG' => ['need' => 'optional', 'types' => ['measurement']],
        'NUM6-UKUR-VOLUME' => ['need' => 'required', 'types' => ['measurement']],
        'NUM6-UKUR-SATUAN-VOLUME' => ['need' => 'optional', 'types' => ['measurement']],
        'NUM6-UKUR-BERAT' => ['need' => 'required', 'types' => ['measurement']],
        'NUM6-UKUR-SATUAN-BERAT' => ['need' => 'optional', 'types' => ['measurement']],
        'NUM6-UKUR-WAKTU' => ['need' => 'required', 'types' => ['clock']],
        'NUM6-UKUR-SATUAN-WAKTU' => ['need' => 'optional', 'types' => ['clock']],
        'NUM6-UKUR-LAJU' => ['need' => 'required', 'types' => ['route']],
        'KELILING-DAN-LUAS-BANGUN' => ['need' => 'required', 'types' => ['geometry_2d']],
        'NUM6-UKUR-VOLUME-BANGUN-RUANG' => ['need' => 'required', 'types' => ['solid_3d']],
        'NUM6-UKUR-SUDUT' => ['need' => 'required', 'types' => ['angle']],
        'NUM6-UKUR-PENAKSIRAN' => ['need' => 'optional', 'types' => ['measurement']],
        'NUM6-DATA-PENYAJIAN' => ['need' => 'required', 'types' => ['data_chart']],
        'NUM6-DATA-INFORMASI' => ['need' => 'required', 'types' => ['data_chart']],
    ];

    public function forCodes(array $codes): array
    {
        return collect($codes)
            ->mapWithKeys(fn (string $code): array => isset(self::PROFILES[$code]) ? [$code => self::PROFILES[$code]] : [])
            ->all();
    }

    public function requiresVisual(array $codes): bool
    {
        return collect($codes)->contains(fn (string $code): bool => data_get(self::PROFILES, "{$code}.need") === 'required');
    }

    public function acceptsType(array $codes, ?string $type): bool
    {
        if ($type === null) {
            return false;
        }

        $requiredProfiles = collect($this->forCodes($codes))->where('need', 'required');
        if ($requiredProfiles->isEmpty()) {
            return true;
        }

        return $requiredProfiles->contains(fn (array $profile): bool => in_array($type, $profile['types'], true));
    }

    public function promptDirection(array $codes): string
    {
        $profiles = $this->forCodes($codes);
        if ($profiles === []) {
            return '';
        }

        $json = json_encode($profiles, JSON_UNESCAPED_UNICODE);

        return "Peta kebutuhan visual subkompetensi terpilih: {$json}. Jika need=required dan ilustrasi digunakan, visual_spec wajib memakai salah satu types yang tercantum. Jika need=none, jangan memaksakan gambar dekoratif.";
    }
}
