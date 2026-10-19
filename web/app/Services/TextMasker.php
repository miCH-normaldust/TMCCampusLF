<?php

namespace App\Services;

class TextMasker
{
    public function mask(string $text): string
    {
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u', '[email hidden]', $text) ?? $text;
        $text = preg_replace('/(?<!\w)(?:\+?\d[\d\s().-]{7,}\d)(?!\w)/u', '[phone hidden]', $text) ?? $text;

        return preg_replace('/(?<!\w)@[A-Za-z0-9_.]{2,}/u', '[handle hidden]', $text) ?? $text;
    }
}
