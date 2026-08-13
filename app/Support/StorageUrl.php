<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class StorageUrl
{
    public static function for(string $disk, string $path): string
    {
        if ($disk === 'public') {
            return '/storage/'.ltrim($path, '/');
        }

        return Storage::disk($disk)->url($path);
    }
}
