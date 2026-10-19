<?php

namespace App\Console\Commands;

use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ArchiveExpiredPosts extends Command
{
    protected $signature = 'campuslf:archive-posts';

    protected $description = 'Send expiry reminders and archive expired CampusLF posts';

    public function handle(): int
    {
        $reminderDeadline = now()->addDays(3);
        Post::with('author')
            ->where('status', 'active')
            ->whereNull('reminder_sent_at')
            ->whereBetween('expires_at', [now(), $reminderDeadline])
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    $post->author->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => 'post_expiry_reminder',
                        'data' => [
                            'title' => 'Your post is about to expire',
                            'body' => $post->type === 'found' && $post->custody !== 'office'
                                ? 'Keep your post active or consider surrendering an unclaimed item to the TMC office.'
                                : 'Keep your post active if the item is still missing.',
                            'post_id' => $post->id,
                        ],
                    ]);
                    $post->update(['reminder_sent_at' => now()]);
                }
            });

        $archived = Post::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'archived', 'updated_at' => now()]);

        $this->info("Archived {$archived} expired post(s).");

        return self::SUCCESS;
    }
}
