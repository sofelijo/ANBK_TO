<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StimulusImageService
{
    public const MAX_BYTES = 200 * 1024;

    private const MAX_DIMENSION = 1920;

    private const MIN_DIMENSION = 160;

    public function store(UploadedFile $file, int $schoolId, string $alt): array
    {
        $contents = file_get_contents($file->getRealPath());
        $source = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (! $source instanceof GdImage) {
            throw ValidationException::withMessages([
                'stimulus_image' => 'File tidak dapat diproses sebagai gambar.',
            ]);
        }

        $image = $this->normalize($source);
        $compressed = $this->compress($image);
        $disk = 'public';
        $path = "question-stimuli/{$schoolId}/".Str::uuid().'.jpg';

        if (! Storage::disk($disk)->put($path, $compressed, ['visibility' => 'public'])) {
            throw ValidationException::withMessages([
                'stimulus_image' => 'Gambar stimulus gagal disimpan. Silakan coba kembali.',
            ]);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($compressed),
            'alt' => $alt,
            'source' => 'upload',
        ];
    }

    private function normalize(GdImage $source): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $normalized = $this->resize($source, $targetWidth, $targetHeight);

        imagedestroy($source);

        return $normalized;
    }

    private function compress(GdImage $image): string
    {
        try {
            while (true) {
                foreach ([82, 74, 66, 58, 50, 42, 34, 26] as $quality) {
                    $encoded = $this->encodeJpeg($image, $quality);

                    if (strlen($encoded) <= self::MAX_BYTES) {
                        return $encoded;
                    }
                }

                if (max(imagesx($image), imagesy($image)) <= self::MIN_DIMENSION) {
                    throw ValidationException::withMessages([
                        'stimulus_image' => 'Gambar tidak dapat dikompresi hingga maksimal 200 KB.',
                    ]);
                }

                $resized = $this->resize(
                    $image,
                    max(1, (int) floor(imagesx($image) * 0.8)),
                    max(1, (int) floor(imagesy($image) * 0.8)),
                );
                imagedestroy($image);
                $image = $resized;
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function resize(GdImage $source, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source),
        );

        return $target;
    }

    private function encodeJpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
