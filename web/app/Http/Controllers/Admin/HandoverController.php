<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class HandoverController extends Controller
{
    public function index(): View
    {
        return view('admin.handovers', [
            'handovers' => Handover::with([
                'claimRequest.post:id,item_name,user_id',
                'claimRequest.post.author:id,first_name,last_name',
                'claimRequest.claimant:id,first_name,last_name,tmc_id_no',
                'claimRequest.conversation:id,claim_id,closed_at',
                'sighting.post:id,item_name,user_id,type',
                'sighting.post.author:id,first_name,last_name',
                'sighting.user:id,first_name,last_name,tmc_id_no',
                'verifiedBy:id,first_name,last_name',
                'location:id,name',
            ])->latest()->paginate(25),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:8']]);
        $lookup = hash_hmac('sha256', $data['code'], (string) config('app.key'));
        $handover = Handover::where('code_lookup', $lookup)
            ->where('expires_at', '>', now())
            ->first();

        if (! $handover || ! Hash::check($data['code'], $handover->code_hash)) {
            return back()->withErrors(['code' => 'The handover code is invalid or expired.']);
        }
        if (! $handover->owner_confirmed_at || ! $handover->finder_confirmed_at) {
            return back()->withErrors(['code' => 'Both participants must confirm the exchange in the app before desk verification.']);
        }

        DB::transaction(function () use ($request, $handover): void {
            $handover->update(['verified_by' => $request->user('admin')->id]);
            $post = $handover->claimRequest?->post ?? $handover->sighting?->post;
            abort_if($post === null, 409, 'This handover is not linked to an item.');
            $post->update(['status' => 'retrieved']);
            $handover->claimRequest?->conversation?->update(['closed_at' => now()]);
            DB::table('audit_logs')->insert([
                'admin_id' => $request->user('admin')->id,
                'action' => 'handover_verified',
                'target_type' => Handover::class,
                'target_id' => $handover->id,
                'reason' => 'Verified at the TMC handover desk.',
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', 'Handover verified and recorded in the audit trail.');
    }
}
