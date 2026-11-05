<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

class PublicMediaUrl
{
    public static function forPath(string $path): string
    {
        if (str_starts_with($path, 'posts/')) {
            return URL::temporarySignedRoute(
                'api.media',
                now()->addDays(7),
                ['path' => $path],
                absolute: false,
            );
        }

        return url('/api/v1/media?path='.rawurlencode($path));
    }
}
