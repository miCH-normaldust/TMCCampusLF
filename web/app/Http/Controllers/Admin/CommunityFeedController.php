<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommunityFeedController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:lost,found'],
            'status' => ['nullable', 'in:active,claim_pending,approved_for_meetup,retrieved,hidden,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $posts = Post::with(['author:id,first_name,last_name', 'category:id,name', 'location:id,name', 'images'])
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $term = '%'.$search.'%';
                $query->where(fn ($builder) => $builder
                    ->where('item_name', 'like', $term)
                    ->orWhere('description', 'like', $term));
            })
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.community-feed', [
            'posts' => $posts,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
