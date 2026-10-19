<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LostSighting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SightingReviewController extends Controller
{
    public function index(): View
    {
        return view('admin.sightings', [
            'sightings' => LostSighting::with([
                'post.author:id,first_name,last_name',
                'user:id,first_name,last_name',
            ])
                ->where('answer', 'yes')
                ->where('author_decision', 'approved')
                ->where('admin_decision', 'pending')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function decide(Request $request, LostSighting $sighting): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        abort_unless(
            $sighting->answer === 'yes'
                && $sighting->author_decision === 'approved'
                && $sighting->admin_decision === 'pending',
            409,
            'This evidence has already been reviewed.',
        );

        DB::transaction(function () use ($request, $sighting, $data): void {
            $approved = $data['decision'] === 'approve';
            $sighting->update([
                'admin_decision' => $approved ? 'approved' : 'rejected',
                'admin_reason' => $data['reason'],
            ]);
            $sighting->post->update([
                'status' => $approved ? 'approved_for_meetup' : 'active',
            ]);
            foreach ([$sighting->post->author, $sighting->user] as $recipient) {
                $recipient->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => $approved ? 'sighting_approved' : 'sighting_rejected',
                    'data' => [
                        'title' => $approved ? 'Sighting approved' : 'Sighting not approved',
                        'body' => $data['reason'],
                    ],
                ]);
            }
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'sighting_'.$data['decision'],
                'target_type' => LostSighting::class,
                'target_id' => $sighting->id,
                'reason' => $data['reason'],
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Sighting review saved.');
    }
}
