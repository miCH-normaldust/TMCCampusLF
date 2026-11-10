<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function moderatePost(Request $request, Post $post): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:report,delete,warn'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $admin = $request->user('admin');

        if ($data['action'] === 'report') {
            DB::transaction(function () use ($admin, $post, $data, $request): void {
                $report = Report::query()->firstOrNew([
                    'post_id' => $post->id,
                    'reporter_id' => $admin->id,
                ]);
                $report->fill([
                    'reason' => $data['reason'],
                    'status' => 'pending',
                ])->save();
                DB::table('reports')->where('id', $report->id)->update([
                    'action' => null,
                    'admin_reason' => null,
                ]);
                $this->recordModerationAudit($request, $post, 'post_report', $data['reason']);
            });

            return back()->with('status', 'Post report added to the moderation queue.');
        }

        DB::transaction(function () use ($post, $data, $request): void {
            $post->loadMissing(['author', 'images']);
            $author = $post->author;
            abort_unless($author, 404, 'The post author could not be found.');

            if ($data['action'] === 'delete') {
                Report::where('post_id', $post->id)->update([
                    'status' => 'actioned',
                    'action' => 'delete',
                    'admin_reason' => $data['reason'],
                ]);
                foreach ($post->images as $image) {
                    Storage::disk('public')->delete($image->path);
                }
                $post->delete();
            }

            $author->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'moderation_action',
                'data' => [
                    'title' => $data['action'] === 'delete'
                        ? 'A TMC administrator removed your post'
                        : 'A TMC administrator reviewed your post',
                    'body' => $data['reason'],
                    'post_id' => $post->id,
                ],
            ]);

            $this->recordModerationAudit(
                $request,
                $post,
                'post_'.$data['action'],
                $data['reason'],
            );
        });

        return back()->with(
            'status',
            $data['action'] === 'delete'
                ? 'Post deleted and author notified.'
                : 'Author warned and notified.',
        );
    }

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,dismissed,actioned,all'],
        ]);
        $status = $filters['status'] ?? 'pending';

        return view('admin.reports', [
            'reports' => Report::with(['post.author', 'reporter:id,first_name,last_name'])
                ->when($status !== 'all', fn ($query) => $query->where('status', $status))
                ->latest()
                ->paginate(15),
            'status' => $status,
        ]);
    }

    public function decide(Request $request, Report $report): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:dismiss,hide,delete,warn,suspend'],
            'reason' => ['required_if:action,hide,delete,warn,suspend', 'nullable', 'string', 'max:1000'],
        ]);
        abort_unless($report->status === 'pending' && $report->post, 409, 'This report is no longer available for review.');

        DB::transaction(function () use ($request, $report, $data): void {
            $post = $report->post()->with('author')->firstOrFail();
            $author = $post->author;
            $reason = $data['reason'] ?? null;
            switch ($data['action']) {
                case 'dismiss':
                    $report->update(['status' => 'dismissed', 'action' => 'dismiss']);
                    break;
                case 'hide':
                    $post->update(['status' => 'hidden', 'hidden_reason' => $reason]);
                    $report->update(['status' => 'actioned', 'action' => 'hide', 'admin_reason' => $reason]);
                    break;
                case 'delete':
                    Report::where('post_id', $post->id)->update([
                        'status' => 'actioned',
                        'action' => 'delete',
                        'admin_reason' => $reason,
                    ]);
                    foreach ($post->images as $image) {
                        Storage::disk('public')->delete($image->path);
                    }
                    $post->delete();
                    break;
                case 'warn':
                    $report->update(['status' => 'actioned', 'action' => 'warn', 'admin_reason' => $reason]);
                    break;
                case 'suspend':
                    $author->update(['status' => 'suspended']);
                    $report->update(['status' => 'actioned', 'action' => 'suspend', 'admin_reason' => $reason]);
                    break;
            }

            if ($data['action'] !== 'dismiss') {
                $author->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'moderation_action',
                    'data' => [
                        'title' => 'A TMC administrator reviewed your post',
                        'body' => $reason,
                    ],
                ]);
            }
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'report_'.$data['action'],
                'target_type' => Post::class,
                'target_id' => $post->id,
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Report review saved.');
    }

    private function recordModerationAudit(Request $request, Post $post, string $action, string $reason): void
    {
        DB::table('audit_logs')->insert([
            'admin_id' => $request->user('admin')->id,
            'action' => $action,
            'target_type' => Post::class,
            'target_id' => $post->id,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
