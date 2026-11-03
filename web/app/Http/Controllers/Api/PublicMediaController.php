<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMediaController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        $path = $request->validate([
            'path' => [
                'required',
                'string',
                'max:512',
                'regex:~\A(?:profiles|posts)/[A-Za-z0-9._/-]+\.(?:jpe?g|png|webp)\z~',
            ],
        ])['path'];

        abort_if(str_contains($path, '..'), 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, basename($path), [], 'inline');
    }
}
