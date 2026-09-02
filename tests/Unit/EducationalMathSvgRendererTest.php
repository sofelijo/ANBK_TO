<?php

namespace Tests\Unit;

use App\Services\AI\EducationalMathSvgRenderer;
use App\Services\AI\MathIllustrationProfile;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationalMathSvgRendererTest extends TestCase
{
    #[DataProvider('visualSpecs')]
    public function test_it_renders_supported_lite_math_visuals(array $spec, string $expected): void
    {
        $renderer = new EducationalMathSvgRenderer;

        $this->assertNull($renderer->validationError($spec));
        $svg = $renderer->render($spec);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString($expected, $svg);
        $this->assertStringNotContainsString('Jawaban', $svg);
        $this->assertStringNotContainsString('Perhitungan', $svg);
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($svg));
    }

    public static function visualSpecs(): array
    {
        return [
            'spatial cubes' => [[
                'type' => 'spatial_cubes',
                'cubes' => [['x' => 0, 'y' => 0, 'z' => 0], ['x' => 1, 'y' => 0, 'z' => 0]],
            ], 'Susunan Kubus'],
            'rectangular prism' => [[
                'type' => 'solid_3d', 'shape' => 'rectangular_prism', 'unit' => 'cm',
                'dimensions' => ['length' => 12, 'width' => 8, 'height' => 5],
            ], 'panjang = 12 cm'],
            'ruler' => [[
                'type' => 'measurement', 'kind' => 'ruler', 'value' => 7, 'maximum' => 10, 'unit' => 'cm',
            ], 'Pengukuran Panjang'],
            'liquid' => [[
                'type' => 'measurement', 'kind' => 'liquid', 'value' => 600, 'maximum' => 1000, 'unit' => 'ml',
            ], 'Pengukuran Volume Cairan'],
            'mass' => [[
                'type' => 'measurement', 'kind' => 'mass', 'value' => 3, 'maximum' => 5, 'unit' => 'kg',
            ], 'Timbangan'],
            'clock' => [[
                'type' => 'clock', 'hour' => 8, 'minute' => 25,
            ], 'Jam Analog'],
            'angle' => [[
                'type' => 'angle', 'degrees' => 65,
            ], 'Diagram Sudut'],
            'bar chart' => [[
                'type' => 'data_chart', 'style' => 'bar', 'title' => 'Buah Favorit',
                'items' => [['label' => 'Apel', 'value' => 4], ['label' => 'Jeruk', 'value' => 6]],
            ], 'Buah Favorit'],
            'pictogram' => [[
                'type' => 'data_chart', 'style' => 'pictogram', 'title' => 'Buku Dibaca', 'legend_value' => 2, 'unit' => 'buku',
                'items' => [['label' => 'Ayu', 'value' => 4], ['label' => 'Beni', 'value' => 6]],
            ], '= 2 buku'],
            'table' => [[
                'type' => 'data_chart', 'style' => 'table', 'title' => 'Tabel Siswa',
                'items' => [['label' => 'Kelas A', 'value' => 20], ['label' => 'Kelas B', 'value' => 24]],
            ], 'Frekuensi'],
            'route' => [[
                'type' => 'route', 'unit' => 'km',
                'points' => [['label' => 'Rumah'], ['label' => 'Sekolah', 'distance_from_previous' => 6]],
            ], '6 km'],
        ];
    }

    public function test_all_23_math_subcompetencies_have_an_illustration_profile(): void
    {
        $codes = [
            'NUM6-BIL-PECAHAN-SENILAI', 'NUM6-BIL-BANDING-URUT-PECAHAN', 'NUM6-BIL-RELASI-PECAHAN',
            'NUM6-BIL-OPERASI-CACAH', 'NUM6-BIL-OPERASI-PECAHAN', 'NUM6-BIL-KPK-FPB',
            'BENTUK-BANGUN-DATAR', 'KONSTRUKSI-BANGUN-RUANG-DAN', 'NUM6-UKUR-PANJANG',
            'NUM6-UKUR-SATUAN-PANJANG', 'NUM6-UKUR-VOLUME', 'NUM6-UKUR-SATUAN-VOLUME',
            'NUM6-UKUR-BERAT', 'NUM6-UKUR-SATUAN-BERAT', 'NUM6-UKUR-WAKTU',
            'NUM6-UKUR-SATUAN-WAKTU', 'NUM6-UKUR-LAJU', 'KELILING-DAN-LUAS-BANGUN',
            'NUM6-UKUR-VOLUME-BANGUN-RUANG', 'NUM6-UKUR-SUDUT', 'NUM6-UKUR-PENAKSIRAN',
            'NUM6-DATA-PENYAJIAN', 'NUM6-DATA-INFORMASI',
        ];
        $profiles = (new MathIllustrationProfile)->forCodes($codes);

        $this->assertCount(23, $profiles);
        $this->assertCount(13, collect($profiles)->where('need', 'required'));
        $this->assertCount(8, collect($profiles)->where('need', 'optional'));
        $this->assertCount(2, collect($profiles)->where('need', 'none'));
    }

    public function test_angle_svg_does_not_print_the_answer_value(): void
    {
        $svg = (new EducationalMathSvgRenderer)->render(['type' => 'angle', 'degrees' => 65]);

        $this->assertStringNotContainsString('65°', $svg);
        $this->assertStringNotContainsString('65 derajat', $svg);
    }

    public function test_bar_chart_has_a_readable_axis_scale_without_printing_values_above_bars(): void
    {
        $svg = (new EducationalMathSvgRenderer)->render([
            'type' => 'data_chart',
            'style' => 'bar',
            'title' => 'Penjualan Buku Harian',
            'items' => [
                ['label' => 'Senin', 'value' => 25],
                ['label' => 'Selasa', 'value' => 40],
                ['label' => 'Rabu', 'value' => 30],
                ['label' => 'Kamis', 'value' => 55],
                ['label' => 'Jumat', 'value' => 35],
            ],
        ]);

        foreach ([0, 10, 20, 30, 40, 50, 60] as $tick) {
            $this->assertStringContainsString('class="axis-value">'.$tick.'</text>', $svg);
        }

        $this->assertStringNotContainsString('class="bar-value"', $svg);
    }
}
