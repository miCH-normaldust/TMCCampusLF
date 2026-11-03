<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageReencoder
{
    public function store(UploadedFile $upload, string $directory = 'posts'): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The PHP GD extension is required to process uploaded images.');
        }

        $source = file_get_contents($upload->getRealPath());
        $imageInfo = $source === false ? false : @getimagesizefromstring($source);
        $image = $source === false ? false : @imagecreatefromstring($source);

        if ($image === false || $imageInfo === false) {
            if ($image !== false) {
                imagedestroy($image);
            }
            throw new RuntimeException('The uploaded image could not be decoded.');
        }

        $mime = $imageInfo['mime'];
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => function_exists('imagewebp') ? 'webp' : 'png',
            default => 'png',
        };

        ob_start();
        try {
            $written = match ($extension) {
                'jpg' => imagejpeg($image, null, 88),
                'png' => imagepng($image, null, 6),
                'webp' => function_exists('imagewebp') && imagewebp($image, null, 88),
            };
            $encoded = ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (! $written || ! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('The uploaded image could not be safely re-encoded.');
        }

        $path = trim($directory, '/').'/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $encoded);

        return $path;
    }
}
