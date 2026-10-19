<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageReencoder
{
    public function store(UploadedFile $upload): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The PHP GD extension is required to process uploaded images.');
        }

        $source = file_get_contents($upload->getRealPath());
        $image = $source === false ? false : @imagecreatefromstring($source);

        if ($image === false) {
            throw new RuntimeException('The uploaded image could not be decoded.');
        }

        $mime = $upload->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };

        if ($extension === null) {
            imagedestroy($image);
            throw new RuntimeException('Only JPG, PNG, and WEBP images can be stored.');
        }

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

        $path = 'posts/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $encoded);

        return $path;
    }
}
