<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BlockedWordFilter
{
    public function assertAllowed(string ...$texts): void
    {
        $words = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) DB::table('settings')->where('key', 'blocked_words')->value('value')),
        )));
        if ($words === []) {
            return;
        }

        $pattern = '/(?:^|[^\pL])(?:'.implode('|', array_map(
            fn (string $word): string => preg_quote($word, '/'),
            $words,
        )).')(?:$|[^\pL])/iu';

        foreach ($texts as $text) {
            if (preg_match($pattern, $text) === 1) {
                abort(422, 'Please revise the post language before submitting.');
            }
        }
    }
}
