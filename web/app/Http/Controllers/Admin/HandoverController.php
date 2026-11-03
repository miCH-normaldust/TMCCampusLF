<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Handover;
use Illuminate\View\View;

class HandoverController extends Controller
{
    public function index(): View
    {
        return view('admin.handovers', [
            'handovers' => Handover::with([
                'claimRequest.post:id,item_name,user_id,type',
                'claimRequest.post.author:id,first_name,last_name',
                'claimRequest.claimant:id,first_name,last_name',
                'sighting.post:id,item_name,user_id,type',
                'sighting.post.author:id,first_name,last_name',
                'sighting.user:id,first_name,last_name',
            ])->latest()->paginate(25),
        ]);
    }

    public function history(): View
    {
        return view('admin.handover-history', [
            'handovers' => Handover::with([
                'claimRequest.post:id,item_name,user_id,type',
                'claimRequest.post.author:id,first_name,last_name',
                'claimRequest.claimant:id,first_name,last_name',
                'sighting.post:id,item_name,user_id,type',
                'sighting.post.author:id,first_name,last_name',
                'sighting.user:id,first_name,last_name',
            ])
                ->whereNotNull('owner_confirmed_at')
                ->whereNotNull('finder_confirmed_at')
                ->latest('updated_at')
                ->paginate(20),
        ]);
    }

    public function historyShow(Handover $handover): View
    {
        abort_if($handover->owner_confirmed_at === null || $handover->finder_confirmed_at === null, 404);

        $handover->load([
            'claimRequest.post.author:id,first_name,last_name',
            'claimRequest.post.category:id,name',
            'claimRequest.post.location:id,name',
            'claimRequest.post.images',
            'claimRequest.claimant:id,first_name,last_name',
            'sighting.post.author:id,first_name,last_name',
            'sighting.post.category:id,name',
            'sighting.post.location:id,name',
            'sighting.post.images',
            'sighting.user:id,first_name,last_name',
        ]);

        return view('admin.handover-history-detail', compact('handover'));
    }
}
