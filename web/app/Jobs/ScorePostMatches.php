<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScorePostMatches implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $postId)
    {
    }

    public function handle(): void
    {
        $post = Post::with('author')->find($this->postId);
        if (! $post || $post->status !== 'active') {
            return;
        }

        Post::with('author')
            ->where('type', $post->type === 'lost' ? 'found' : 'lost')
            ->where('status', 'active')
            ->where('user_id', '!=', $post->user_id)
            ->whereDate('event_date', '>=', $post->event_date->copy()->subDays(30))
            ->whereDate('event_date', '<=', $post->event_date->copy()->addDays(30))
            ->chunkById(100, function ($candidates) use ($post): void {
                foreach ($candidates as $candidate) {
                    $score = (int) ($candidate->category_id === $post->category_id ? 30 : 0)
                        + (strcasecmp($candidate->color, $post->color) === 0 ? 20 : 0)
                        + ($candidate->location_id === $post->location_id ? 20 : 0)
                        + ($candidate->event_date->diffInDays($post->event_date) <= 7 ? 20 : 0)
                        + (Str::contains(mb_strtolower($candidate->item_name), mb_strtolower($post->item_name)) ? 10 : 0);
                    if ($score < 60) {
                        continue;
                    }

                    $created = DB::table('post_matches')->insertOrIgnore([
                        [
                            'post_id' => $post->id,
                            'matched_post_id' => $candidate->id,
                            'score' => $score,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'post_id' => $candidate->id,
                            'matched_post_id' => $post->id,
                            'score' => $score,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ]);
                    if ($created === 0) {
                        continue;
                    }

                    foreach ([
                        [$post->author, $post->id, $candidate->id],
                        [$candidate->author, $candidate->id, $post->id],
                    ] as [$author, $authorPostId, $matchedPostId]) {
                        $author->notifications()->create([
                            'id' => (string) Str::uuid(),
                            'type' => 'possible_match',
                            'data' => [
                                'title' => 'Possible item match',
                                'body' => 'A TMC lost-and-found post may match one of your items.',
                                'post_id' => $authorPostId,
                                'matched_post_id' => $matchedPostId,
                            ],
                        ]);
                    }
                }
            });
    }
}
