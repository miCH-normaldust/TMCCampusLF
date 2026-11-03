<?php

namespace App\Support;

class PublicMediaUrl
{
    public static function forPath(string $path): string
    {
        return url('/api/v1/media?path='.rawurlencode($path));
    }
}
