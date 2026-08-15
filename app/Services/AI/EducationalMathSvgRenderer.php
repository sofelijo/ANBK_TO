<?php

namespace App\Services\AI;

use InvalidArgumentException;

class EducationalMathSvgRenderer
{
    public const TYPES = ['spatial_cubes', 'solid_3d', 'measurement', 'clock', 'angle', 'data_chart', 'route'];

    public function supports(mixed $spec): bool
    {
        return is_array($spec) && in_array(data_get($spec, 'type'), self::TYPES, true);
    }

    public function validationError(array $spec): ?string
    {
        if (! $this->supports($spec)) {
            return 'Jenis visual Matematika tidak didukung oleh mode Lite.';
        }

        return match ($spec['type']) {
            'spatial_cubes' => $this->validateCubes($spec),
            'solid_3d' => $this->validateSolid($spec),
            'measurement' => $this->validateMeasurement($spec),
            'clock' => $this->validateClock($spec),
            'angle' => $this->validateAngle($spec),
            'data_chart' => $this->validateData($spec),
            'route' => $this->validateRoute($spec),
        };
    }

    public function render(array $spec): string
    {
        if (($error = $this->validationError($spec)) !== null) {
            throw new InvalidArgumentException($error);
        }

        return match ($spec['type']) {
            'spatial_cubes' => $this->spatialCubes($spec),
            'solid_3d' => $this->solid($spec),
            'measurement' => $this->measurement($spec),
            'clock' => $this->clock($spec),
            'angle' => $this->angle($spec),
            'data_chart' => $this->dataChart($spec),
            'route' => $this->route($spec),
        };
    }

    private function validateCubes(array $spec): ?string
    {
        $cubes = $spec['cubes'] ?? null;
        if (! is_array($cubes) || $cubes === [] || count($cubes) > 40) {
            return 'spatial_cubes membutuhkan 1–40 koordinat kubus.';
        }
        foreach ($cubes as $cube) {
            if (! is_array($cube) || collect(['x', 'y', 'z'])->contains(fn (string $axis): bool => ! isset($cube[$axis]) || ! is_int($cube[$axis]) || abs($cube[$axis]) > 8)) {
                return 'Setiap kubus wajib memiliki koordinat integer x, y, z antara -8 dan 8.';
            }
        }

        return null;
    }

    private function validateSolid(array $spec): ?string
    {
        if (! in_array($spec['shape'] ?? null, ['cube', 'rectangular_prism'], true)) {
            return 'solid_3d hanya mendukung cube atau rectangular_prism.';
        }
        $required = ($spec['shape'] ?? null) === 'cube' ? ['side'] : ['length', 'width', 'height'];
        foreach ($required as $key) {
            if (! isset($spec['dimensions'][$key]) || ! is_numeric($spec['dimensions'][$key]) || (float) $spec['dimensions'][$key] <= 0) {
                return "Ukuran {$key} wajib berupa angka positif.";
            }
        }

        return null;
    }

    private function validateMeasurement(array $spec): ?string
    {
        if (! in_array($spec['kind'] ?? null, ['ruler', 'liquid', 'mass'], true)) {
            return 'measurement.kind harus ruler, liquid, atau mass.';
        }
        if (! is_numeric($spec['value'] ?? null) || (float) $spec['value'] < 0 || ! is_numeric($spec['maximum'] ?? null) || (float) $spec['maximum'] <= 0 || (float) $spec['value'] > (float) $spec['maximum']) {
            return 'Nilai pengukuran harus berada antara 0 dan maximum.';
        }

        return null;
    }

    private function validateClock(array $spec): ?string
    {
        if (! is_int($spec['hour'] ?? null) || ($spec['hour'] < 0 || $spec['hour'] > 23) || ! is_int($spec['minute'] ?? null) || ($spec['minute'] < 0 || $spec['minute'] > 59)) {
            return 'Jam harus 0–23 dan menit harus 0–59.';
        }

        return null;
    }

    private function validateAngle(array $spec): ?string
    {
        if (! is_numeric($spec['degrees'] ?? null) || (float) $spec['degrees'] <= 0 || (float) $spec['degrees'] >= 360) {
            return 'Besar sudut harus lebih dari 0 dan kurang dari 360 derajat.';
        }

        return null;
    }

    private function validateData(array $spec): ?string
    {
        if (! in_array($spec['style'] ?? null, ['bar', 'pictogram', 'table'], true)) {
            return 'data_chart.style harus bar, pictogram, atau table.';
        }
        $items = $spec['items'] ?? null;
        if (! is_array($items) || $items === [] || count($items) > 8) {
            return 'Diagram data membutuhkan 1–8 item.';
        }
        foreach ($items as $item) {
            if (! is_string($item['label'] ?? null) || trim($item['label']) === '' || mb_strlen($item['label']) > 30 || ! is_numeric($item['value'] ?? null) || (float) $item['value'] < 0) {
                return 'Setiap item data membutuhkan label dan nilai nonnegatif.';
            }
        }
        if (($spec['style'] ?? null) === 'pictogram' && (! is_numeric($spec['legend_value'] ?? null) || (float) $spec['legend_value'] <= 0)) {
            return 'Piktogram membutuhkan legend_value positif.';
        }

        return null;
    }

    private function validateRoute(array $spec): ?string
    {
        $points = $spec['points'] ?? null;
        if (! is_array($points) || count($points) < 2 || count($points) > 6) {
            return 'Diagram rute membutuhkan 2–6 titik.';
        }
        foreach ($points as $index => $point) {
            if (! is_string($point['label'] ?? null) || trim($point['label']) === '' || ($index > 0 && (! is_numeric($point['distance_from_previous'] ?? null) || (float) $point['distance_from_previous'] <= 0))) {
                return 'Setiap titik rute membutuhkan label dan jarak positif dari titik sebelumnya.';
            }
        }

        return null;
    }

    private function spatialCubes(array $spec): string
    {
        $cubes = $spec['cubes'];
        usort($cubes, fn (array $a, array $b): int => [$a['z'], $a['y'], $a['x']] <=> [$b['z'], $b['y'], $b['x']]);
        $parts = '';
        foreach ($cubes as $cube) {
            $cx = 540 + (($cube['x'] - $cube['z']) * 58);
            $cy = 360 - ($cube['y'] * 58) + (($cube['x'] + $cube['z']) * 29);
            $parts .= $this->cube($cx, $cy, 58);
        }

        return $this->frame('Susunan Kubus', $parts, 'Amati susunan dan tampak dari setiap arah.');
    }

    private function solid(array $spec): string
    {
        $d = $spec['dimensions'];
        $unit = $this->escape((string) ($spec['unit'] ?? 'cm'));
        $length = $spec['shape'] === 'cube' ? $d['side'] : $d['length'];
        $width = $spec['shape'] === 'cube' ? $d['side'] : $d['width'];
        $height = $spec['shape'] === 'cube' ? $d['side'] : $d['height'];
        $name = $spec['shape'] === 'cube' ? 'Kubus' : 'Balok';
        $labels = $spec['shape'] === 'cube'
            ? '<text x="640" y="520" text-anchor="middle" class="label">sisi = '.$this->number($length).' '.$unit.'</text>'
            : '<text x="640" y="520" text-anchor="middle" class="label">panjang = '.$this->number($length).' '.$unit.' · lebar = '.$this->number($width).' '.$unit.' · tinggi = '.$this->number($height).' '.$unit.'</text>';
        $body = '<g transform="translate(640 335)">'.$this->cube(0, 0, 180).'</g>'.$labels;

        return $this->frame($name, $body);
    }

    private function measurement(array $spec): string
    {
        return match ($spec['kind']) {
            'ruler' => $this->ruler($spec),
            'liquid' => $this->liquid($spec),
            'mass' => $this->mass($spec),
        };
    }

    private function ruler(array $spec): string
    {
        $max = (float) $spec['maximum'];
        $value = (float) $spec['value'];
        $unit = $this->escape((string) ($spec['unit'] ?? 'cm'));
        $ticks = '';
        $segments = min(20, max(1, (int) round($max)));
        for ($i = 0; $i <= $segments; $i++) {
            $x = 160 + (960 * $i / $segments);
            $ticks .= '<line x1="'.$this->number($x).'" y1="390" x2="'.$this->number($x).'" y2="'.($i % 5 === 0 ? 345 : 365).'"/><text x="'.$this->number($x).'" y="430" text-anchor="middle" class="small">'.$this->number($max * $i / $segments).'</text>';
        }
        $end = 160 + (960 * $value / $max);
        $body = '<rect x="160" y="335" width="960" height="115" rx="12" fill="#fef3c7" stroke="#d97706" stroke-width="3"/><g stroke="#92400e" stroke-width="3">'.$ticks.'</g><line x1="160" y1="270" x2="'.$this->number($end).'" y2="270" stroke="#2563eb" stroke-width="18" stroke-linecap="round"/><circle cx="'.$this->number($end).'" cy="270" r="10" fill="#1d4ed8"/><text x="640" y="500" text-anchor="middle" class="label">Skala dalam '.$unit.'</text>';

        return $this->frame('Pengukuran Panjang', $body);
    }

    private function liquid(array $spec): string
    {
        $ratio = (float) $spec['value'] / (float) $spec['maximum'];
        $height = 300 * $ratio;
        $y = 470 - $height;
        $unit = $this->escape((string) ($spec['unit'] ?? 'ml'));
        $body = '<path d="M470 155 L500 500 H780 L810 155" fill="none" stroke="#334155" stroke-width="8"/><rect x="500" y="'.$this->number($y).'" width="280" height="'.$this->number($height).'" fill="#38bdf8" opacity=".75"/><g stroke="#475569" stroke-width="3">';
        for ($i = 0; $i <= 10; $i++) {
            $tickY = 470 - (30 * $i);
            $body .= '<line x1="780" y1="'.$tickY.'" x2="'.($i % 5 === 0 ? 840 : 815).'" y2="'.$tickY.'"/>';
        }
        $body .= '</g><text x="870" y="330" class="label">'.$unit.'</text>';

        return $this->frame('Pengukuran Volume Cairan', $body);
    }

    private function mass(array $spec): string
    {
        $max = (float) $spec['maximum'];
        $value = (float) $spec['value'];
        $angle = -135 + (270 * $value / $max);
        $radian = deg2rad($angle);
        $x = 640 + 155 * cos($radian);
        $y = 345 + 155 * sin($radian);
        $unit = $this->escape((string) ($spec['unit'] ?? 'kg'));
        $body = '<circle cx="640" cy="345" r="210" fill="#f8fafc" stroke="#334155" stroke-width="8"/><path d="M490 540 H790 L840 625 H440 Z" fill="#cbd5e1" stroke="#475569" stroke-width="5"/><line x1="640" y1="345" x2="'.$this->number($x).'" y2="'.$this->number($y).'" stroke="#ef4444" stroke-width="10" stroke-linecap="round"/><circle cx="640" cy="345" r="18" fill="#991b1b"/><text x="640" y="425" text-anchor="middle" class="label">'.$unit.'</text>';

        return $this->frame('Timbangan', $body, 'Baca posisi jarum pada skala.');
    }

    private function clock(array $spec): string
    {
        $hour = ($spec['hour'] % 12) + ($spec['minute'] / 60);
        $minute = $spec['minute'];
        $hourAngle = deg2rad(($hour * 30) - 90);
        $minuteAngle = deg2rad(($minute * 6) - 90);
        $body = '<circle cx="640" cy="350" r="235" fill="#ffffff" stroke="#1e3a8a" stroke-width="10"/>';
        for ($n = 1; $n <= 12; $n++) {
            $a = deg2rad(($n * 30) - 90);
            $body .= '<text x="'.$this->number(640 + 190 * cos($a)).'" y="'.$this->number(359 + 190 * sin($a)).'" text-anchor="middle" class="clock-number">'.$n.'</text>';
        }
        $body .= '<line x1="640" y1="350" x2="'.$this->number(640 + 125 * cos($hourAngle)).'" y2="'.$this->number(350 + 125 * sin($hourAngle)).'" stroke="#0f172a" stroke-width="16" stroke-linecap="round"/><line x1="640" y1="350" x2="'.$this->number(640 + 180 * cos($minuteAngle)).'" y2="'.$this->number(350 + 180 * sin($minuteAngle)).'" stroke="#2563eb" stroke-width="10" stroke-linecap="round"/><circle cx="640" cy="350" r="16" fill="#0f172a"/>';

        return $this->frame('Jam Analog', $body);
    }

    private function angle(array $spec): string
    {
        $degrees = (float) $spec['degrees'];
        $radian = deg2rad(-$degrees);
        $x = 640 + 300 * cos($radian);
        $y = 410 + 300 * sin($radian);
        $arcX = 640 + 105 * cos($radian);
        $arcY = 410 + 105 * sin($radian);
        $largeArc = $degrees > 180 ? 1 : 0;
        $body = '<line x1="640" y1="410" x2="980" y2="410" stroke="#1e40af" stroke-width="10" stroke-linecap="round"/><line x1="640" y1="410" x2="'.$this->number($x).'" y2="'.$this->number($y).'" stroke="#1e40af" stroke-width="10" stroke-linecap="round"/><path d="M745 410 A105 105 0 '.$largeArc.' 0 '.$this->number($arcX).' '.$this->number($arcY).'" fill="none" stroke="#f59e0b" stroke-width="12"/><circle cx="640" cy="410" r="14" fill="#0f172a"/>';

        return $this->frame('Diagram Sudut', $body, 'Tentukan jenis atau besar sudut dari gambar.');
    }

    private function dataChart(array $spec): string
    {
        return match ($spec['style']) {
            'bar' => $this->barChart($spec),
            'pictogram' => $this->pictogram($spec),
            'table' => $this->table($spec),
        };
    }

    private function barChart(array $spec): string
    {
        $items = $spec['items'];
        $max = max(1, ...array_map(fn (array $item): float => (float) $item['value'], $items));
        $slot = 900 / count($items);
        $body = '<line x1="170" y1="520" x2="1110" y2="520" stroke="#334155" stroke-width="4"/><line x1="170" y1="160" x2="170" y2="520" stroke="#334155" stroke-width="4"/>';
        foreach ($items as $index => $item) {
            $height = 320 * (float) $item['value'] / $max;
            $x = 190 + ($index * $slot);
            $body .= '<rect x="'.$this->number($x).'" y="'.$this->number(520 - $height).'" width="'.$this->number($slot - 35).'" height="'.$this->number($height).'" rx="8" fill="#38bdf8" stroke="#0369a1" stroke-width="3"/><text x="'.$this->number($x + (($slot - 35) / 2)).'" y="560" text-anchor="middle" class="small">'.$this->escape($item['label']).'</text>';
        }

        return $this->frame($this->escape((string) ($spec['title'] ?? 'Diagram Batang')), $body);
    }

    private function pictogram(array $spec): string
    {
        $legend = (float) $spec['legend_value'];
        $body = '';
        foreach ($spec['items'] as $row => $item) {
            $y = 190 + ($row * 58);
            $icons = (int) round((float) $item['value'] / $legend);
            $body .= '<text x="260" y="'.($y + 8).'" text-anchor="end" class="small">'.$this->escape($item['label']).'</text>';
            for ($icon = 0; $icon < $icons; $icon++) {
                $x = 300 + ($icon * 52);
                $body .= '<circle cx="'.$x.'" cy="'.$y.'" r="18" fill="#f59e0b" stroke="#92400e" stroke-width="3"/><path d="M'.($x - 8).' '.($y - 2).' Q'.$x.' '.($y + 10).' '.($x + 8).' '.($y - 2).'" fill="none" stroke="#fff" stroke-width="3"/>';
            }
        }
        $body .= '<circle cx="430" cy="620" r="15" fill="#f59e0b" stroke="#92400e" stroke-width="3"/><text x="458" y="627" class="small">= '.$this->number($legend).' '.$this->escape((string) ($spec['unit'] ?? 'objek')).'</text>';

        return $this->frame($this->escape((string) ($spec['title'] ?? 'Piktogram')), $body);
    }

    private function table(array $spec): string
    {
        $body = '<rect x="300" y="150" width="680" height="70" fill="#dbeafe" stroke="#334155" stroke-width="3"/><text x="470" y="195" text-anchor="middle" class="label">Kategori</text><text x="810" y="195" text-anchor="middle" class="label">Frekuensi</text>';
        foreach ($spec['items'] as $row => $item) {
            $y = 220 + ($row * 58);
            $body .= '<rect x="300" y="'.$y.'" width="680" height="58" fill="'.($row % 2 ? '#f8fafc' : '#fff').'" stroke="#94a3b8" stroke-width="2"/><line x1="640" y1="'.$y.'" x2="640" y2="'.($y + 58).'" stroke="#94a3b8" stroke-width="2"/><text x="470" y="'.($y + 38).'" text-anchor="middle" class="small">'.$this->escape($item['label']).'</text><text x="810" y="'.($y + 38).'" text-anchor="middle" class="small">'.$this->number($item['value']).'</text>';
        }

        return $this->frame($this->escape((string) ($spec['title'] ?? 'Tabel Frekuensi')), $body);
    }

    private function route(array $spec): string
    {
        $points = $spec['points'];
        $gap = 900 / (count($points) - 1);
        $unit = $this->escape((string) ($spec['unit'] ?? 'km'));
        $body = '';
        foreach ($points as $index => $point) {
            $x = 190 + ($index * $gap);
            if ($index > 0) {
                $previousX = 190 + (($index - 1) * $gap);
                $body .= '<line x1="'.$previousX.'" y1="350" x2="'.$x.'" y2="350" stroke="#2563eb" stroke-width="10"/><text x="'.$this->number(($previousX + $x) / 2).'" y="315" text-anchor="middle" class="small">'.$this->number($point['distance_from_previous']).' '.$unit.'</text>';
            }
            $body .= '<circle cx="'.$x.'" cy="350" r="28" fill="#f59e0b" stroke="#92400e" stroke-width="5"/><text x="'.$x.'" y="415" text-anchor="middle" class="label">'.$this->escape($point['label']).'</text>';
        }

        return $this->frame('Diagram Jarak dan Rute', $body);
    }

    private function cube(float $cx, float $cy, float $size): string
    {
        $half = $size * .86;
        $rise = $size * .5;
        $top = "{$this->number($cx)},{$this->number($cy - $size)} {$this->number($cx + $half)},{$this->number($cy - $size + $rise)} {$this->number($cx)},{$this->number($cy - $size + 2 * $rise)} {$this->number($cx - $half)},{$this->number($cy - $size + $rise)}";
        $left = "{$this->number($cx - $half)},{$this->number($cy - $size + $rise)} {$this->number($cx)},{$this->number($cy)} {$this->number($cx)},{$this->number($cy + $size)} {$this->number($cx - $half)},{$this->number($cy + $rise)}";
        $right = "{$this->number($cx)},{$this->number($cy)} {$this->number($cx + $half)},{$this->number($cy - $size + $rise)} {$this->number($cx + $half)},{$this->number($cy + $rise)} {$this->number($cx)},{$this->number($cy + $size)}";

        return '<g stroke="#1e40af" stroke-width="3" stroke-linejoin="round"><polygon points="'.$top.'" fill="#bfdbfe"/><polygon points="'.$left.'" fill="#60a5fa"/><polygon points="'.$right.'" fill="#2563eb"/></g>';
    }

    private function frame(string $title, string $body, string $note = ''): string
    {
        $noteSvg = $note === '' ? '' : '<text x="640" y="675" text-anchor="middle" class="note">'.$this->escape($note).'</text>';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720" role="img" aria-label="'.$title.'"><style>.title{font:700 34px sans-serif;fill:#0f172a}.label{font:700 22px sans-serif;fill:#334155}.small{font:600 18px sans-serif;fill:#334155}.note{font:500 17px sans-serif;fill:#64748b}.clock-number{font:700 25px sans-serif;fill:#0f172a}</style><rect width="1280" height="720" fill="#f8fafc"/><rect x="24" y="22" width="1232" height="676" rx="24" fill="#fff" stroke="#cbd5e1" stroke-width="3"/><text x="640" y="80" text-anchor="middle" class="title">'.$title.'</text>'.$body.$noteSvg.'</svg>';
    }

    private function number(float|int $number): string
    {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
