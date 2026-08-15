<?php

namespace App\Services\AI;

use App\Enums\AiGenerationStatus;
use App\Models\AiGeneration;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoryIllustrationService
{
    public function __construct(
        private readonly GeometrySvgRenderer $geometryRenderer,
        private readonly EducationalMathSvgRenderer $educationalMathRenderer,
    ) {}

    public function submit(AiGeneration $generation): void
    {
        if (data_get($generation->request_payload, 'illustration_mode', 'lite') !== 'pro'
            && $this->supportsDeterministicDiagram(data_get($generation->request_payload, 'visual_spec'))) {
            $this->completeDeterministicDiagram($generation);

            return;
        }

        if (config('ai.driver') === 'fake') {
            $this->completeFake($generation);

            return;
        }

        if (data_get($generation->request_payload, 'illustration_mode') === 'pro') {
            try {
                $this->submitGemini($generation, 'Mode Pro menggunakan Gemini secara langsung.');
            } catch (Throwable $exception) {
                $this->fail($generation, $exception->getMessage());

                throw new RuntimeException($exception->getMessage(), previous: $exception);
            }

            return;
        }

        if (data_get($generation->request_payload, 'illustration_mode', 'lite') !== 'pro'
            && $this->isMathOrDiagramRequest($generation)) {
            $this->completeFake($generation, true);

            return;
        }

        $cloudflareError = 'Cloudflare Workers AI belum dikonfigurasi.';
        if ($this->cloudflareConfigured()) {
            try {
                $this->submitCloudflare($generation);

                return;
            } catch (Throwable $exception) {
                $cloudflareError = $exception->getMessage();
            }
        }

        try {
            $this->submitGemini($generation, $cloudflareError);
        } catch (Throwable $exception) {
            $message = "Cloudflare gagal: {$cloudflareError} Gemini gagal: {$exception->getMessage()}";
            $this->fail($generation, $message);

            throw new RuntimeException($message, previous: $exception);
        }
    }

    public function refresh(AiGeneration $generation): void
    {
        if ($generation->status !== AiGenerationStatus::Processing
            || config('ai.driver') === 'fake'
            || $generation->provider !== 'gemini') {
            return;
        }

        try {
            $batchName = data_get($generation->result_payload, 'batch_name');
            if (! is_string($batchName) || $batchName === '') {
                throw new RuntimeException('ID batch gambar tidak ditemukan.');
            }

            $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
            $response = Http::timeout(30)
                ->retry(2, 500)
                ->withHeader('x-goog-api-key', (string) config('ai.gemini.api_key'))
                ->get("{$baseUrl}/{$batchName}")
                ->throw()
                ->json();

            $state = data_get($response, 'metadata.state') ?? data_get($response, 'state');
            $payload = [
                ...($generation->result_payload ?? []),
                'batch_state' => $state,
                'last_checked_at' => now()->toIso8601String(),
            ];
            $generation->update(['result_payload' => $payload]);

            if (in_array($state, ['JOB_STATE_FAILED', 'JOB_STATE_CANCELLED', 'JOB_STATE_EXPIRED'], true) || data_get($response, 'error')) {
                $message = data_get($response, 'error.message', "Batch gambar berakhir dengan status {$state}.");
                $this->fail($generation, (string) $message);

                return;
            }

            $done = data_get($response, 'done') === true;
            if ($state !== 'JOB_STATE_SUCCEEDED' && ! $done) {
                return;
            }

            $this->storeCompletedImage($generation, $response);
        } catch (Throwable $exception) {
            $this->fail($generation, $exception->getMessage());
        }
    }

    public function shouldRefresh(AiGeneration $generation): bool
    {
        if ($generation->provider !== 'gemini') {
            return false;
        }

        $lastCheckedAt = data_get($generation->result_payload, 'last_checked_at');

        return ! is_string($lastCheckedAt) || now()->diffInSeconds($lastCheckedAt) >= 15;
    }

    private function storeCompletedImage(AiGeneration $generation, array $response): void
    {
        $inlineResponses = $this->inlineResponses($response, [
            'response.inlinedResponses',
            'response.inlinedResponses.inlinedResponses',
            'output.inlinedResponses.inlinedResponses',
            'dest.inlinedResponses',
        ]);
        $inlineResponse = $inlineResponses[0] ?? null;
        $error = data_get($inlineResponse, 'error.message');
        if (is_string($error) && $error !== '') {
            throw new RuntimeException($error);
        }

        $parts = data_get($inlineResponse, 'response.candidates.0.content.parts', []);
        $imagePart = collect(is_array($parts) ? $parts : [])->first(
            fn (array $part): bool => is_string(data_get($part, 'inlineData.data'))
                || is_string(data_get($part, 'inline_data.data')),
        );
        $encodedImage = data_get($imagePart, 'inlineData.data') ?? data_get($imagePart, 'inline_data.data');
        $mimeType = data_get($imagePart, 'inlineData.mimeType') ?? data_get($imagePart, 'inline_data.mime_type');

        if (! is_string($encodedImage) || ! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Batch selesai tetapi tidak mengembalikan gambar yang valid.');
        }

        $image = base64_decode($encodedImage, true);
        if ($image === false || strlen($image) > 15 * 1024 * 1024) {
            throw new RuntimeException('Data gambar hasil Gemini tidak valid atau terlalu besar.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.{$extension}";
        if (! Storage::disk($disk)->put($path, $image)) {
            throw new RuntimeException('Gambar tidak dapat disimpan ke storage.');
        }

        $usage = data_get($inlineResponse, 'response.usageMetadata', []);
        $this->complete($generation, $disk, $path, $mimeType, [
            'input_tokens' => (int) data_get($usage, 'promptTokenCount', 0),
            'output_tokens' => (int) data_get($usage, 'candidatesTokenCount', 1120),
        ], (int) config('ai.image.batch_cost_microusd'));
    }

    private function isMathOrDiagramRequest(AiGeneration $generation): bool
    {
        $theme = (string) data_get($generation->request_payload, 'theme');
        $compName = (string) data_get($generation->request_payload, 'competency');
        $visualDesc = $this->visualDescription($generation);

        $combined = mb_strtolower("{$theme} {$compName} {$visualDesc}");

        return str_contains($combined, 'bangun datar')
            || str_contains($combined, 'bangun ruang')
            || str_contains($combined, 'persegi')
            || str_contains($combined, 'segitiga')
            || str_contains($combined, 'kubus')
            || str_contains($combined, 'balok')
            || str_contains($combined, 'spasial')
            || str_contains($combined, 'visualisasi spasial')
            || str_contains($combined, 'luas total')
            || str_contains($combined, 'keliling dan luas');
    }

    private function completeFake(AiGeneration $generation, bool $localDiagram = false): void
    {
        $theme = (string) data_get($generation->request_payload, 'theme');
        $subjectName = (string) data_get($generation->request_payload, 'subject');
        $compName = (string) data_get($generation->request_payload, 'competency');
        $visualDesc = $this->visualDescription($generation);

        $combinedText = mb_strtolower("{$theme} {$subjectName} {$compName} {$visualDesc}");

        $is2dGeometry = str_contains($combinedText, 'bangun datar')
            || str_contains($combinedText, 'persegi')
            || str_contains($combinedText, 'segitiga')
            || str_contains($combinedText, 'lingkaran')
            || str_contains($combinedText, 'segiempat')
            || str_contains($combinedText, 'keliling dan luas')
            || str_contains($combinedText, 'luas total');

        $is3dSpatial = str_contains($combinedText, 'bangun ruang')
            || str_contains($combinedText, 'kubus')
            || str_contains($combinedText, 'balok')
            || str_contains($combinedText, 'spasial')
            || str_contains($combinedText, 'visualisasi spasial')
            || str_contains($combinedText, '3d');

        if ($is2dGeometry) {
            $spec = data_get($generation->request_payload, 'visual_spec');
            if (! $this->geometryRenderer->supports($spec)) {
                $spec = $this->geometryRenderer->infer($visualDesc !== '' ? $visualDesc : $theme);
            }
            if (is_array($spec) && $this->geometryRenderer->validationError($spec) === null) {
                $svg = $this->geometryRenderer->render($spec);
            } else {
                throw new RuntimeException($this->geometryRenderer->validationError($spec ?? []) ?? 'Deskripsi bangun datar belum dapat dirender secara presisi.');
            }
        } elseif ($is3dSpatial) {
            $svg = $this->spatialCubeDiagramSvg($theme, $visualDesc);
        } else {
            $safeTheme = htmlspecialchars($theme, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#ecfdf5"/>
  <circle cx="180" cy="150" r="72" fill="#fbbf24"/>
  <path d="M0 430 Q220 320 430 430 T1024 410 V576 H0Z" fill="#34d399"/>
  <path d="M0 490 Q250 390 510 490 T1024 470 V576 H0Z" fill="#059669"/>
  <rect x="220" y="210" width="584" height="150" rx="28" fill="#ffffff" opacity=".92"/>
  <text x="512" y="275" text-anchor="middle" font-family="sans-serif" font-size="30" font-weight="700" fill="#064e3b">Ilustrasi Soal Cerita</text>
  <text x="512" y="322" text-anchor="middle" font-family="sans-serif" font-size="24" fill="#047857">{$safeTheme}</text>
</svg>
SVG;
        }

        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.svg";
        Storage::disk($disk)->put($path, $svg);
        $generation->update([
            'provider' => $localDiagram ? 'local-svg' : 'fake',
            'model' => $localDiagram ? 'deterministic-geometry-svg-v3' : 'deterministic-svg',
        ]);
        $this->complete($generation, $disk, $path, 'image/svg+xml', [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ], 0);
    }

    private function geometry2dDiagramSvg(string $theme, string $visualDescription): string
    {
        $text = mb_strtolower(trim($visualDescription) !== '' ? $visualDescription : $theme);

        // 1. Check for Circle (Lingkaran)
        if (str_contains($text, 'lingkaran') || str_contains($text, 'jari-jari') || str_contains($text, 'diameter')) {
            preg_match('/(?:jari-jari|r)\s*(\d+)/i', $text, $mR);
            preg_match('/diameter\s*(\d+)/i', $text, $mD);
            $r = isset($mR[1]) ? (int) $mR[1] : (isset($mD[1]) ? (int) ($mD[1] / 2) : 7);

            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Geometri 2D · Lingkaran</text>

  <g transform="translate(0, 10)">
    <circle cx="512" cy="280" r="130" fill="#fef3c7" stroke="#d97706" stroke-width="3"/>
    <line x1="512" y1="280" x2="642" y2="280" stroke="#d97706" stroke-width="2.5" stroke-dasharray="4,4"/>
    <circle cx="512" cy="280" r="4" fill="#92400e"/>
    <text x="577" y="270" text-anchor="middle" font-family="sans-serif" font-size="15" font-weight="700" fill="#b45309">r = {$r} cm</text>
  </g>

</svg>
SVG;
        }

        // 2. Check for Rectangle (Persegi Panjang) before other shapes.
        if (str_contains($text, 'persegi panjang') || (str_contains($text, 'panjang') && str_contains($text, 'lebar'))) {
            preg_match('/panjang\s*(\d+)/i', $text, $mP);
            preg_match('/lebar\s*(\d+)/i', $text, $mL);
            $p = isset($mP[1]) ? (int) $mP[1] : 12;
            $l = isset($mL[1]) ? (int) $mL[1] : 8;

            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Geometri 2D · Persegi Panjang</text>

  <g transform="translate(0, 20)">
    <rect x="362" y="165" width="300" height="200" fill="#e0f2fe" stroke="#0284c7" stroke-width="3" rx="8"/>
    <line x1="362" y1="140" x2="662" y2="140" stroke="#0284c7" stroke-width="2" stroke-dasharray="4,4"/>
    <text x="512" y="125" text-anchor="middle" font-family="sans-serif" font-size="18" font-weight="700" fill="#0284c7">Panjang = {$p} cm</text>
    <line x1="330" y1="165" x2="330" y2="365" stroke="#0284c7" stroke-width="2" stroke-dasharray="4,4"/>
    <text x="310" y="270" text-anchor="end" font-family="sans-serif" font-size="18" font-weight="700" fill="#0284c7">Lebar = {$l} cm</text>
  </g>
</svg>
SVG;
        }

        // 3. Check for Triangle (Segitiga)
        if (str_contains($text, 'segitiga')) {
            preg_match('/alas\s*(\d+)/i', $text, $mA);
            preg_match('/tinggi\s*(\d+)/i', $text, $mT);
            $a = isset($mA[1]) ? (int) $mA[1] : 14;
            $t = isset($mT[1]) ? (int) $mT[1] : 9;

            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Geometri 2D · Segitiga</text>
  <g transform="translate(0, 10)">
    <polygon points="362,380 662,380 512,180" fill="#dcfce7" stroke="#16a34a" stroke-width="3"/>
    <line x1="512" y1="180" x2="512" y2="380" stroke="#16a34a" stroke-width="2" stroke-dasharray="5,4"/>
    <text x="530" y="290" font-family="sans-serif" font-size="18" font-weight="700" fill="#15803d">Tinggi = {$t} cm</text>
    <text x="512" y="410" text-anchor="middle" font-family="sans-serif" font-size="18" font-weight="700" fill="#15803d">Alas = {$a} cm</text>
  </g>
</svg>
SVG;
        }

        // 4. Check for Square (Persegi dengan Sisi sama)
        if (str_contains($text, 'persegi') && ! str_contains($text, 'persegi panjang') && (str_contains($text, 'sisi') || ! str_contains($text, 'lebar'))) {
            preg_match('/sisi\s*(\d+)/i', $text, $mS);
            $s = isset($mS[1]) ? (int) $mS[1] : 10;

            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Geometri 2D · Persegi</text>

  <g transform="translate(0, 10)">
    <rect x="402" y="180" width="220" height="220" fill="#e0f2fe" stroke="#0284c7" stroke-width="3" rx="4"/>
    <text x="512" y="295" text-anchor="middle" font-family="sans-serif" font-size="20" font-weight="700" fill="#0369a1">Persegi</text>
    <text x="512" y="160" text-anchor="middle" font-family="sans-serif" font-size="15" font-weight="700" fill="#0284c7">sisi (s) = {$s} cm</text>
  </g>

</svg>
SVG;
        }

        // 5. Composite shape (Persegi + Segitiga)
        preg_match('/sisi\s*(\d+)/i', $text, $mSisi);
        preg_match('/alas\s*(\d+)/i', $text, $mAlas);
        preg_match('/tinggi\s*(\d+)/i', $text, $mTinggi);
        $sisi = isset($mSisi[1]) ? (int) $mSisi[1] : 10;
        $alas = isset($mAlas[1]) ? (int) $mAlas[1] : $sisi;
        $tinggi = isset($mTinggi[1]) ? (int) $mTinggi[1] : 6;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Geometri 2D · Bangun Datar Gabungan</text>

  <g transform="translate(0, 10)">
    <rect x="422" y="240" width="180" height="180" fill="#e0f2fe" stroke="#0284c7" stroke-width="3"/>
    <text x="512" y="335" text-anchor="middle" font-family="sans-serif" font-size="16" font-weight="700" fill="#0369a1">Persegi</text>
    <text x="512" y="355" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="600" fill="#0284c7">sisi = {$sisi} cm</text>

    <polygon points="422,240 602,240 512,132" fill="#dcfce7" stroke="#16a34a" stroke-width="3"/>
    <text x="512" y="210" text-anchor="middle" font-family="sans-serif" font-size="14" font-weight="700" fill="#15803d">Segitiga</text>

    <line x1="512" y1="132" x2="512" y2="240" stroke="#16a34a" stroke-width="2" stroke-dasharray="5,4"/>
    <text x="525" y="180" font-family="sans-serif" font-size="12" font-weight="700" fill="#16a34a">t = {$tinggi} cm</text>

    <line x1="422" y1="435" x2="602" y2="435" stroke="#64748b" stroke-width="1.5" stroke-dasharray="3,3"/>
    <text x="512" y="455" text-anchor="middle" font-family="sans-serif" font-size="13" font-weight="700" fill="#334155">alas = {$alas} cm</text>
  </g>

</svg>
SVG;
    }

    private function visualDescription(AiGeneration $generation): string
    {
        $description = data_get($generation->request_payload, 'visual_description');
        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        $storyGenerationId = (int) data_get($generation->request_payload, 'story_generation_id');
        if ($storyGenerationId === 0) {
            return '';
        }

        $sourceGeneration = AiGeneration::query()
            ->whereKey($storyGenerationId)
            ->where('school_id', $generation->school_id)
            ->first();

        return trim((string) data_get($sourceGeneration?->result_payload, 'visual_description'));
    }

    private function spatialCubeDiagramSvg(string $theme, string $visualDescription): string
    {
        $cubeSvg = $this->renderIsometricCubeGroup([
            ['x' => 0, 'y' => 0, 'z' => 0],
            ['x' => 1, 'y' => 0, 'z' => 0],
            ['x' => 2, 'y' => 0, 'z' => 0],
            ['x' => 1, 'y' => 1, 'z' => 0],
            ['x' => 1, 'y' => 0, 'z' => 1],
            ['x' => 1, 'y' => 0, 'z' => -1],
        ]);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="576" viewBox="0 0 1024 576">
  <rect width="1024" height="576" fill="#f8fafc"/>
  <rect x="30" y="25" width="964" height="526" rx="20" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="512" y="65" text-anchor="middle" font-family="sans-serif" font-size="22" font-weight="700" fill="#0f172a">Diagram Matematika 3D · Visualisasi Spasial Bangun Ruang</text>

  <!-- 3D Isometric Projection Group -->
  <g transform="translate(512, 280)">
    {$cubeSvg}
  </g>

  <!-- Legend & Directional Indicators -->
  <g transform="translate(60, 475)">
    <rect width="904" height="55" rx="12" fill="#f1f5f9" stroke="#e2e8f0"/>
    <text x="40" y="33" font-family="sans-serif" font-size="14" font-weight="700" fill="#475569">Panduan Pengamatan:</text>
    <text x="210" y="33" font-family="sans-serif" font-size="14" font-weight="700" fill="#2563eb">👁️ Tampak Depan</text>
    <text x="440" y="33" font-family="sans-serif" font-size="14" font-weight="700" fill="#059669">⬆️ Tampak Atas</text>
    <text x="650" y="33" font-family="sans-serif" font-size="14" font-weight="700" fill="#0284c7">➡️ Tampak Samping</text>
  </g>
</svg>
SVG;
    }

    private function renderIsometricCubeGroup(array $cubes): string
    {
        usort($cubes, function ($a, $b) {
            if ($a['z'] !== $b['z']) {
                return $a['z'] <=> $b['z'];
            }
            if ($a['y'] !== $b['y']) {
                return $a['y'] <=> $b['y'];
            }

            return $a['x'] <=> $b['x'];
        });

        $svg = '';
        $unit = 48;
        $isoX = $unit * cos(M_PI / 6);
        $isoY = $unit * sin(M_PI / 6);

        foreach ($cubes as $c) {
            $x3d = $c['x'];
            $y3d = $c['y'];
            $z3d = $c['z'];

            $cx = ($x3d - $z3d) * $isoX - 25;
            $cy = -($y3d * $unit) + ($x3d + $z3d) * $isoY;

            $pTop = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
                $cx, $cy - $unit,
                $cx + $isoX, $cy - $unit + $isoY,
                $cx, $cy - $unit + 2 * $isoY,
                $cx - $isoX, $cy - $unit + $isoY
            );
            $pLeft = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
                $cx - $isoX, $cy - $unit + $isoY,
                $cx, $cy - $unit + 2 * $isoY,
                $cx, $cy + 2 * $isoY,
                $cx - $isoX, $cy + $isoY
            );
            $pRight = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
                $cx, $cy - $unit + 2 * $isoY,
                $cx + $isoX, $cy - $unit + $isoY,
                $cx + $isoX, $cy + $isoY,
                $cx, $cy + 2 * $isoY
            );

            $svg .= '<g>';
            $svg .= "<polygon points=\"{$pTop}\" fill=\"#38bdf8\" stroke=\"#0284c7\" stroke-width=\"2\"/>";
            $svg .= "<polygon points=\"{$pLeft}\" fill=\"#34d399\" stroke=\"#059669\" stroke-width=\"2\"/>";
            $svg .= "<polygon points=\"{$pRight}\" fill=\"#2563eb\" stroke=\"#1d4ed8\" stroke-width=\"2\"/>";
            $svg .= '</g>';
        }

        return $svg;
    }

    private function supportsDeterministicDiagram(mixed $spec): bool
    {
        return is_array($spec)
            && (in_array(data_get($spec, 'type'), ['fraction_models', 'object_groups'], true)
                || $this->geometryRenderer->supports($spec)
                || $this->educationalMathRenderer->supports($spec));
    }

    private function completeDeterministicDiagram(AiGeneration $generation): void
    {
        $spec = data_get($generation->request_payload, 'visual_spec');
        $svg = match (data_get($spec, 'type')) {
            'fraction_models' => $this->fractionModelsSvg(data_get($spec, 'items', [])),
            'object_groups' => $this->objectGroupsSvg((int) data_get($spec, 'groups'), (int) data_get($spec, 'objects_per_group')),
            'geometry_2d' => $this->geometryRenderer->render($spec),
            default => $this->educationalMathRenderer->render($spec),
        };
        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.svg";

        if (! Storage::disk($disk)->put($path, $svg)) {
            throw new RuntimeException('Diagram Matematika tidak dapat disimpan ke storage.');
        }

        $generation->update([
            'provider' => 'local-svg',
            'model' => match (data_get($spec, 'type')) {
                'geometry_2d' => 'deterministic-geometry-svg-v3',
                'fraction_models', 'object_groups' => 'deterministic-math-svg-v1',
                default => 'deterministic-math-svg-v2',
            },
            'result_payload' => [
                'image_provider' => 'local-svg',
                'fallback_used' => false,
            ],
        ]);
        $this->complete($generation, $disk, $path, 'image/svg+xml', [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ], 0);
    }

    private function fractionModelsSvg(array $items): string
    {
        $items = array_values(array_slice($items, 0, 6));
        $count = max(1, count($items));
        $spacing = 1100 / $count;
        $models = '';

        foreach ($items as $itemIndex => $item) {
            $centerX = 90 + ($spacing * $itemIndex) + ($spacing / 2);
            $centerY = 330;
            $total = max(1, min(20, (int) ($item['total_parts'] ?? 1)));
            $shaded = max(0, min($total, (int) ($item['shaded_parts'] ?? 0)));
            $shape = $item['shape'] ?? 'circle';

            if ($shape === 'rectangle') {
                $width = min(280, $spacing - 50);
                $height = 260;
                $startX = $centerX - ($width / 2);
                $partWidth = $width / $total;
                for ($part = 0; $part < $total; $part++) {
                    $fill = $part < $shaded ? '#38bdf8' : '#ffffff';
                    $x = $startX + ($part * $partWidth);
                    $models .= '<rect x="'.$this->svgNumber($x).'" y="200" width="'.$this->svgNumber($partWidth).'" height="'.$height.'" fill="'.$fill.'" stroke="#0f172a" stroke-width="4"/>';
                }
            } elseif ($total === 1) {
                $models .= '<circle cx="'.$this->svgNumber($centerX).'" cy="'.$centerY.'" r="140" fill="'.($shaded === 1 ? '#38bdf8' : '#ffffff').'" stroke="#0f172a" stroke-width="5"/>';
            } else {
                for ($part = 0; $part < $total; $part++) {
                    $startAngle = (-M_PI / 2) + ($part * 2 * M_PI / $total);
                    $endAngle = (-M_PI / 2) + (($part + 1) * 2 * M_PI / $total);
                    $startX = $centerX + (140 * cos($startAngle));
                    $startY = $centerY + (140 * sin($startAngle));
                    $endX = $centerX + (140 * cos($endAngle));
                    $endY = $centerY + (140 * sin($endAngle));
                    $fill = $part < $shaded ? '#38bdf8' : '#ffffff';
                    $models .= '<path d="M '.$this->svgNumber($centerX).' '.$centerY.' L '.$this->svgNumber($startX).' '.$this->svgNumber($startY).' A 140 140 0 0 1 '.$this->svgNumber($endX).' '.$this->svgNumber($endY).' Z" fill="'.$fill.'" stroke="#0f172a" stroke-width="4"/>';
                }
            }

            $models .= '<text x="'.$this->svgNumber($centerX).'" y="535" text-anchor="middle" font-family="sans-serif" font-size="28" font-weight="700" fill="#334155">Model '.($itemIndex + 1).'</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720"><rect width="1280" height="720" fill="#f8fafc"/><text x="640" y="85" text-anchor="middle" font-family="sans-serif" font-size="34" font-weight="700" fill="#0f172a">Perhatikan bagian yang diarsir</text>'.$models.'</svg>';
    }

    private function objectGroupsSvg(int $groups, int $objectsPerGroup): string
    {
        $groups = max(1, min(10, $groups));
        $objectsPerGroup = max(1, min(20, $objectsPerGroup));
        $columns = min(5, $groups);
        $rows = (int) ceil($groups / $columns);
        $panelWidth = 1120 / $columns;
        $panelHeight = 520 / $rows;
        $content = '';

        for ($group = 0; $group < $groups; $group++) {
            $column = $group % $columns;
            $row = intdiv($group, $columns);
            $x = 80 + ($column * $panelWidth);
            $y = 120 + ($row * $panelHeight);
            $content .= '<rect x="'.$this->svgNumber($x).'" y="'.$this->svgNumber($y).'" width="'.$this->svgNumber($panelWidth - 24).'" height="'.$this->svgNumber($panelHeight - 24).'" rx="24" fill="#ffffff" stroke="#94a3b8" stroke-width="3"/>';

            for ($object = 0; $object < $objectsPerGroup; $object++) {
                $objectColumn = $object % 5;
                $objectRow = intdiv($object, 5);
                $circleX = $x + 35 + ($objectColumn * 34);
                $circleY = $y + 42 + ($objectRow * 34);
                $content .= '<circle cx="'.$this->svgNumber($circleX).'" cy="'.$this->svgNumber($circleY).'" r="12" fill="#f59e0b" stroke="#92400e" stroke-width="2"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720"><rect width="1280" height="720" fill="#f8fafc"/><text x="640" y="72" text-anchor="middle" font-family="sans-serif" font-size="34" font-weight="700" fill="#0f172a">Kelompok objek</text>'.$content.'</svg>';
    }

    private function svgNumber(float|int $number): string
    {
        return rtrim(rtrim(number_format((float) $number, 2, '.', ''), '0'), '.');
    }

    private function complete(AiGeneration $generation, string $disk, string $path, string $mimeType, array $usage, int $costMicrousd): void
    {
        $questionIds = data_get($generation->request_payload, 'question_ids')
            ?? data_get($generation->result_payload, 'question_ids')
            ?? [];
        $alt = (string) data_get(
            $generation->request_payload,
            'alt',
            'Ilustrasi untuk soal '.data_get($generation->request_payload, 'theme', data_get($generation->result_payload, 'title', '')),
        );

        DB::transaction(function () use ($generation, $questionIds, $disk, $path, $mimeType, $alt, $usage, $costMicrousd): void {
            Question::query()
                ->where('school_id', $generation->school_id)
                ->whereIn('id', $questionIds)
                ->get()
                ->each(function (Question $question) use ($generation, $disk, $path, $mimeType, $alt): void {
                    $question->update(['metadata' => [
                        ...($question->metadata ?? []),
                        'illustration' => [
                            'generation_id' => $generation->id,
                            'disk' => $disk,
                            'path' => $path,
                            'mime_type' => $mimeType,
                            'alt' => $alt,
                        ],
                    ]]);
                });

            $generation->update([
                'status' => AiGenerationStatus::Completed,
                'result_payload' => [
                    ...($generation->result_payload ?? []),
                    'image_disk' => $disk,
                    'image_path' => $path,
                    'mime_type' => $mimeType,
                    'batch_state' => 'JOB_STATE_SUCCEEDED',
                    'completed_at' => now()->toIso8601String(),
                ],
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cost_microusd' => $costMicrousd,
                'error' => null,
            ]);
        });
    }

    private function cloudflareConfigured(): bool
    {
        return (string) config('ai.cloudflare.account_id') !== ''
            && (string) config('ai.cloudflare.api_token') !== '';
    }

    private function submitCloudflare(AiGeneration $generation): void
    {
        $accountId = (string) config('ai.cloudflare.account_id');
        $apiToken = (string) config('ai.cloudflare.api_token');
        $model = (string) config('ai.cloudflare.image_model');
        $baseUrl = rtrim((string) config('ai.cloudflare.base_url'), '/');
        $response = Http::timeout(90)
            ->retry(1, 500)
            ->withToken($apiToken)
            ->post("{$baseUrl}/accounts/{$accountId}/ai/run/{$model}", [
                'prompt' => (string) data_get($generation->request_payload, 'prompt'),
                'steps' => min(8, max(1, (int) config('ai.cloudflare.image_steps'))),
            ])
            ->throw()
            ->json();

        $encodedImage = data_get($response, 'result.image');
        if (data_get($response, 'success') !== true || ! is_string($encodedImage) || $encodedImage === '') {
            $error = data_get($response, 'errors.0.message', 'Cloudflare tidak mengembalikan gambar yang valid.');

            throw new RuntimeException((string) $error);
        }

        $image = base64_decode($encodedImage, true);
        $imageInfo = $image === false ? false : @getimagesizefromstring($image);
        $mimeType = is_array($imageInfo) ? ($imageInfo['mime'] ?? null) : null;
        if ($image === false || ! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            throw new RuntimeException('Data gambar Cloudflare tidak valid.');
        }

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $disk = (string) config('ai.image.disk');
        $path = "question-illustrations/{$generation->school_id}/{$generation->id}.{$extension}";
        if (! Storage::disk($disk)->put($path, $image)) {
            throw new RuntimeException('Gambar Cloudflare tidak dapat disimpan ke storage.');
        }

        $generation->update([
            'provider' => 'cloudflare',
            'model' => $model,
            'result_payload' => [
                'image_provider' => 'cloudflare',
                'fallback_used' => false,
            ],
        ]);
        $this->complete($generation, $disk, $path, $mimeType, [
            'input_tokens' => 0,
            'output_tokens' => 0,
        ], 0);
    }

    private function submitGemini(AiGeneration $generation, string $cloudflareError): void
    {
        $apiKey = (string) config('ai.gemini.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('GEMINI_API_KEY belum dikonfigurasi.');
        }

        $model = (string) config('ai.image.model');
        $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
        $response = Http::timeout(30)
            ->withHeader('x-goog-api-key', $apiKey)
            ->post("{$baseUrl}/models/{$model}:batchGenerateContent", [
                'batch' => [
                    'display_name' => "story-illustration-{$generation->id}",
                    'input_config' => [
                        'requests' => [
                            'requests' => [[
                                'request' => [
                                    'contents' => [[
                                        'role' => 'user',
                                        'parts' => [['text' => data_get($generation->request_payload, 'prompt')]],
                                    ]],
                                    'generationConfig' => [
                                        'responseModalities' => ['IMAGE'],
                                        'imageConfig' => [
                                            'aspectRatio' => '16:9',
                                            'imageSize' => '1K',
                                        ],
                                    ],
                                ],
                                'metadata' => ['generation_id' => $generation->id],
                            ]],
                        ],
                    ],
                ],
            ])
            ->throw()
            ->json();

        $batchName = data_get($response, 'name') ?? data_get($response, 'batch.name');
        if (! is_string($batchName) || $batchName === '') {
            throw new RuntimeException('Gemini tidak mengembalikan ID batch gambar.');
        }

        $generation->update([
            'status' => AiGenerationStatus::Processing,
            'provider' => 'gemini',
            'model' => $model,
            'result_payload' => [
                'image_provider' => 'gemini',
                'fallback_used' => true,
                'cloudflare_error' => mb_substr($cloudflareError, 0, 500),
                'batch_name' => $batchName,
                'batch_state' => data_get($response, 'metadata.state', 'JOB_STATE_PENDING'),
                'last_checked_at' => now()->toIso8601String(),
            ],
            'error' => null,
        ]);
    }

    private function fail(AiGeneration $generation, string $message): void
    {
        $generation->update([
            'status' => AiGenerationStatus::Failed,
            'error' => mb_substr($message, 0, 5000),
        ]);
    }

    private function inlineResponses(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);

            if (is_array($value) && isset($value['inlinedResponses']) && is_array($value['inlinedResponses'])) {
                $value = $value['inlinedResponses'];
            }

            if (is_array($value) && $value !== [] && array_is_list($value)) {
                return $value;
            }
        }

        return [];
    }
}
