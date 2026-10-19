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
    public function index(): View
    {
        return view('admin.reports', [
            'reports' => Report::with(['post.author', 'reporter:id,first_name,last_name'])
                ->where('status', 'pending')
                ->latest()
                ->paginate(15),
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
                    $report->update(['status' => 'dismissed']);
                    break;
                case 'hide':
                    $post->update(['status' => 'hidden', 'hidden_reason' => $reason]);
                    $report->update(['status' => 'actioned']);
                    break;
                case 'delete':
                    foreach ($post->images as $image) {
                        Storage::disk('public')->delete($image->path);
                    }
                    $post->delete();
                    break;
                case 'warn':
                    $report->update(['status' => 'actioned']);
                    break;
                case 'suspend':
                    $author->update(['status' => 'suspended']);
                    $report->update(['status' => 'actioned']);
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
}
