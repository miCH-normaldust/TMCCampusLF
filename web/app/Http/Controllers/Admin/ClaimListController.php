<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Post;
use App\Models\PostRetrievalRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClaimListController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
        ]);
        $postIds = ClaimRequest::query()
            ->select('post_id')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->groupBy('post_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->paginate(15, ['post_id'])
            ->withQueryString();
        $posts = Post::with([
            'author:id,first_name,last_name',
            'claimRequests' => fn ($query) => $query
                ->when($filters['status'] ?? null, fn ($claimQuery, $status) => $claimQuery->where('status', $status))
                ->with('claimant:id,first_name,last_name,tmc_id_no,email')
                ->latest(),
        ])
            ->whereIn('id', $postIds->pluck('post_id'))
            ->get()
            ->keyBy('id');
        $postIds->setCollection($postIds->getCollection()->map(
            fn ($entry) => $posts->get($entry->post_id),
        ));

        return view('admin.claims', [
            'claimPosts' => $postIds,
            'retrievalRequests' => PostRetrievalRequest::with([
                'post:id,item_name,type,status,user_id',
                'user:id,first_name,last_name,email',
            ])
                ->where('status', 'pending')
                ->whereHas('post', fn ($query) => $query->where('type', 'found'))
                ->latest()
                ->get(),
        ]);
    }
}
