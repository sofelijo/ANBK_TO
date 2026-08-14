<?php

namespace App\Services\AI;

use InvalidArgumentException;

class GeometrySvgRenderer
{
    public const SHAPES = [
        'square', 'rectangle', 'triangle', 'circle', 'trapezoid', 'parallelogram',
        'rhombus', 'kite', 'regular_polygon',
    ];

    public function supports(mixed $spec): bool
    {
        return is_array($spec)
            && data_get($spec, 'type') === 'geometry_2d'
            && in_array(data_get($spec, 'shape'), self::SHAPES, true);
    }

    public function render(array $spec): string
    {
        if (! $this->supports($spec)) {
            throw new InvalidArgumentException('Spesifikasi geometri 2D tidak didukung.');
        }

        $shape = $spec['shape'];
        $unit = $this->escape((string) ($spec['unit'] ?? 'cm'));
        $dimensions = collect($spec['dimensions'] ?? [])->map(fn ($value): float => (float) $value)->all();
        [$name, $drawing] = match ($shape) {
            'square' => ['Persegi', $this->square($dimensions, $unit)],
            'rectangle' => ['Persegi Panjang', $this->rectangle($dimensions, $unit)],
            'triangle' => ['Segitiga', $this->triangle($dimensions, $unit)],
            'circle' => ['Lingkaran', $this->circle($dimensions, $unit)],
            'trapezoid' => ['Trapesium', $this->trapezoid($dimensions, $unit)],
            'parallelogram' => ['Jajar Genjang', $this->parallelogram($dimensions, $unit)],
            'rhombus' => ['Belah Ketupat', $this->rhombus($dimensions, $unit)],
            'kite' => ['Layang-layang', $this->kite($dimensions, $unit)],
            'regular_polygon' => ['Segi Banyak Beraturan', $this->regularPolygon($dimensions, $unit)],
        };

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576" role="img" aria-label="Diagram {$name}">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="70" text-anchor="middle" font-family="sans-serif" font-size="24" font-weight="700" fill="#0f172a">{$name}</text>
  {$drawing}
</svg>
SVG;
    }

    public function infer(string $description): ?array
    {
        $text = mb_strtolower($description);
        $unit = preg_match('/\b(mm|cm|dm|m|km)\b/u', $text, $match) ? $match[1] : 'cm';
        $number = function (string $pattern) use ($text): ?float {
            return preg_match($pattern, $text, $matches)
                ? (float) str_replace(',', '.', $matches[1])
                : null;
        };

        if (str_contains($text, 'trapesium')) {
            preg_match_all('/(?:sisi sejajar|alas)(?:\s+(?:atas|bawah|pertama|kedua))?\s*(?:=|adalah|sepanjang)?\s*(\d+(?:[.,]\d+)?)/u', $text, $matches);
            $bases = array_map(fn ($value): float => (float) str_replace(',', '.', $value), $matches[1] ?? []);

            return $this->legacySpec('trapezoid', $unit, [
                'top_base' => $bases[0] ?? $number('/sisi sejajar\s*(\d+(?:[.,]\d+)?)/u') ?? 10,
                'bottom_base' => $bases[1] ?? $number('/(?:dan|serta)\s*(\d+(?:[.,]\d+)?)\s*'.$unit.'/u') ?? 20,
                'height' => $number('/tinggi\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 8,
                'leg' => $number('/sisi miring(?:[^\d]{0,20})(\d+(?:[.,]\d+)?)/u'),
            ]);
        }
        if (str_contains($text, 'jajar genjang')) {
            return $this->legacySpec('parallelogram', $unit, ['base' => $number('/alas\s*(\d+(?:[.,]\d+)?)/u') ?? 12, 'height' => $number('/tinggi\s*(\d+(?:[.,]\d+)?)/u') ?? 8, 'side' => $number('/sisi miring\s*(\d+(?:[.,]\d+)?)/u')]);
        }
        if (str_contains($text, 'belah ketupat')) {
            return $this->legacySpec('rhombus', $unit, ['diagonal_1' => $number('/diagonal(?: pertama| 1)?\s*(\d+(?:[.,]\d+)?)/u') ?? 16, 'diagonal_2' => $number('/diagonal(?: kedua| 2)[^\d]*(\d+(?:[.,]\d+)?)/u') ?? 12, 'side' => $number('/sisi\s*(\d+(?:[.,]\d+)?)/u')]);
        }
        if (str_contains($text, 'layang-layang')) {
            return $this->legacySpec('kite', $unit, ['diagonal_1' => $number('/diagonal(?: pertama| 1)?\s*(\d+(?:[.,]\d+)?)/u') ?? 18, 'diagonal_2' => $number('/diagonal(?: kedua| 2)[^\d]*(\d+(?:[.,]\d+)?)/u') ?? 12]);
        }
        if (str_contains($text, 'lingkaran') || str_contains($text, 'jari-jari') || str_contains($text, 'diameter')) {
            return $this->legacySpec('circle', $unit, ['radius' => $number('/(?:jari-jari|radius|r)\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u'), 'diameter' => $number('/diameter\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u')]);
        }
        if (str_contains($text, 'persegi panjang') || (str_contains($text, 'panjang') && str_contains($text, 'lebar'))) {
            return $this->legacySpec('rectangle', $unit, ['length' => $number('/panjang\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 12, 'width' => $number('/lebar\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 8]);
        }
        if (str_contains($text, 'segitiga')) {
            return $this->legacySpec('triangle', $unit, ['base' => $number('/alas\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 14, 'height' => $number('/tinggi\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 9]);
        }
        if (str_contains($text, 'persegi')) {
            return $this->legacySpec('square', $unit, ['side' => $number('/sisi\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u') ?? 10]);
        }
        if (preg_match('/segi\s*(\d+)/u', $text, $match)) {
            return $this->legacySpec('regular_polygon', $unit, ['sides' => (float) $match[1], 'side' => $number('/sisi\s*(?:=|adalah)?\s*(\d+(?:[.,]\d+)?)/u')]);
        }

        return null;
    }

    public function validationError(array $spec): ?string
    {
        if (! $this->supports($spec)) {
            return 'Jenis bangun pada visual_spec tidak didukung.';
        }

        $d = $spec['dimensions'] ?? [];
        $required = match ($spec['shape']) {
            'square' => ['side'],
            'rectangle' => ['length', 'width'],
            'triangle', 'parallelogram' => ['base', 'height'],
            'circle' => isset($d['radius']) ? ['radius'] : ['diameter'],
            'trapezoid' => ['top_base', 'bottom_base'],
            'rhombus', 'kite' => ['diagonal_1', 'diagonal_2'],
            'regular_polygon' => ['sides', 'side'],
        };
        foreach ($required as $key) {
            if (! isset($d[$key]) || ! is_numeric($d[$key]) || (float) $d[$key] <= 0) {
                return "Ukuran {$key} wajib berupa angka positif.";
            }
        }

        if ($spec['shape'] === 'trapezoid' && ! isset($d['height']) && ! isset($d['leg'])) {
            return 'Trapesium membutuhkan ukuran tinggi atau sisi miring.';
        }

        if ($spec['shape'] === 'regular_polygon' && ((int) $d['sides'] < 5 || (int) $d['sides'] > 12)) {
            return 'Jumlah sisi segi banyak harus antara 5 dan 12.';
        }
        if ($spec['shape'] === 'trapezoid' && isset($d['leg'])) {
            $halfDifference = abs((float) $d['bottom_base'] - (float) $d['top_base']) / 2;
            if ((float) $d['leg'] <= $halfDifference) {
                return 'Sisi miring trapesium harus lebih panjang dari setengah selisih sisi sejajar.';
            }
            if (isset($d['height']) && abs(hypot($halfDifference, (float) $d['height']) - (float) $d['leg']) > 0.15) {
                return 'Ukuran trapesium sama kaki tidak konsisten antara sisi sejajar, tinggi, dan sisi miring.';
            }
        }

        return null;
    }

    private function square(array $d, string $unit): string
    {
        return $this->shape('<rect x="392" y="155" width="240" height="240" rx="4"/>', [$this->label(512, 430, 'Sisi', $d['side'] ?? null, $unit)]);
    }

    private function rectangle(array $d, string $unit): string
    {
        return $this->shape('<rect x="342" y="175" width="340" height="210" rx="6"/>', [$this->label(512, 425, 'Panjang', $d['length'] ?? null, $unit), $this->label(315, 285, 'Lebar', $d['width'] ?? null, $unit, 'end')]);
    }

    private function triangle(array $d, string $unit): string
    {
        return $this->shape('<polygon points="342,390 682,390 512,145"/><line x1="512" y1="145" x2="512" y2="390" stroke-dasharray="6,5"/>', [$this->label(512, 430, 'Alas', $d['base'] ?? null, $unit), $this->label(532, 275, 'Tinggi', $d['height'] ?? null, $unit)]);
    }

    private function circle(array $d, string $unit): string
    {
        $radius = $d['radius'] ?? (isset($d['diameter']) ? $d['diameter'] / 2 : null);
        $line = isset($d['diameter']) ? '<line x1="372" y1="285" x2="652" y2="285"/>' : '<line x1="512" y1="285" x2="652" y2="285"/>';
        $label = isset($d['diameter']) ? ['Diameter', $d['diameter']] : ['Jari-jari', $radius];

        return $this->shape('<circle cx="512" cy="285" r="140"/>'.$line, [$this->label(512, 455, $label[0], $label[1], $unit)]);
    }

    private function trapezoid(array $d, string $unit): string
    {
        return $this->shape('<polygon points="397,160 627,160 712,390 312,390"/><line x1="397" y1="160" x2="397" y2="390" stroke-dasharray="6,5"/>', [$this->label(512, 140, 'Sisi atas', $d['top_base'] ?? null, $unit), $this->label(512, 430, 'Sisi bawah', $d['bottom_base'] ?? null, $unit), $this->label(385, 280, 'Tinggi', $d['height'] ?? null, $unit, 'end'), $this->label(690, 275, 'Sisi miring', $d['leg'] ?? null, $unit)]);
    }

    private function parallelogram(array $d, string $unit): string
    {
        return $this->shape('<polygon points="397,165 702,165 627,390 322,390"/><line x1="397" y1="165" x2="397" y2="390" stroke-dasharray="6,5"/>', [$this->label(475, 430, 'Alas', $d['base'] ?? null, $unit), $this->label(385, 280, 'Tinggi', $d['height'] ?? null, $unit, 'end'), $this->label(670, 280, 'Sisi', $d['side'] ?? null, $unit)]);
    }

    private function rhombus(array $d, string $unit): string
    {
        return $this->shape('<polygon points="512,125 712,285 512,445 312,285"/><line x1="312" y1="285" x2="712" y2="285" stroke-dasharray="6,5"/><line x1="512" y1="125" x2="512" y2="445" stroke-dasharray="6,5"/>', [$this->label(512, 475, 'Diagonal 1', $d['diagonal_1'] ?? null, $unit), $this->label(725, 275, 'Diagonal 2', $d['diagonal_2'] ?? null, $unit)]);
    }

    private function kite(array $d, string $unit): string
    {
        return $this->shape('<polygon points="512,105 682,260 512,460 342,260"/><line x1="342" y1="260" x2="682" y2="260" stroke-dasharray="6,5"/><line x1="512" y1="105" x2="512" y2="460" stroke-dasharray="6,5"/>', [$this->label(512, 490, 'Diagonal 1', $d['diagonal_1'] ?? null, $unit), $this->label(695, 250, 'Diagonal 2', $d['diagonal_2'] ?? null, $unit)]);
    }

    private function regularPolygon(array $d, string $unit): string
    {
        $sides = max(5, min(12, (int) ($d['sides'] ?? 5)));
        $points = collect(range(0, $sides - 1))->map(function (int $index) use ($sides): string {
            $angle = (-M_PI / 2) + (2 * M_PI * $index / $sides);

            return $this->number(512 + 165 * cos($angle)).','.$this->number(285 + 165 * sin($angle));
        })->implode(' ');

        return $this->shape('<polygon points="'.$points.'"/>', [$this->label(512, 480, 'Sisi', $d['side'] ?? null, $unit)]);
    }

    private function shape(string $elements, array $labels): string
    {
        return '<g fill="#dbeafe" stroke="#2563eb" stroke-width="4" stroke-linejoin="round">'.$elements.'</g>'.implode('', array_filter($labels));
    }

    private function label(float $x, float $y, string $name, mixed $value, string $unit, string $anchor = 'middle'): string
    {
        if ($value === null || ! is_numeric($value)) {
            return '';
        }

        return '<text x="'.$this->number($x).'" y="'.$this->number($y).'" text-anchor="'.$anchor.'" font-family="sans-serif" font-size="18" font-weight="700" fill="#1e40af">'.$this->escape($name).' = '.$this->number((float) $value).' '.$unit.'</text>';
    }

    private function legacySpec(string $shape, string $unit, array $dimensions): array
    {
        return ['type' => 'geometry_2d', 'shape' => $shape, 'unit' => $unit, 'dimensions' => array_filter($dimensions, fn ($value): bool => $value !== null)];
    }

    private function number(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
