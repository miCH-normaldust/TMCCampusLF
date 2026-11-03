<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PeopleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $people = User::query()
            ->where('role', 'user')
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->when(isset($filters['search']), function ($query) use ($filters): void {
                $term = '%'.$filters['search'].'%';
                $query->where(fn ($builder) => $builder
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('department', 'like', $term));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(30, [
                'id',
                'first_name',
                'last_name',
                'department',
                'profile_picture_path',
            ]);

        return response()->json([
            'data' => $people->getCollection()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'department' => $user->department,
                'profile_picture_url' => $user->profile_picture_path
                    ? PublicMediaUrl::forPath($user->profile_picture_path)
                    : null,
            ]),
            'meta' => [
                'current_page' => $people->currentPage(),
                'last_page' => $people->lastPage(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        abort_unless(
            $user->role === 'user' && $user->status === 'active' && $user->email_verified_at !== null,
            404,
        );

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'department' => $user->department,
            'profile_status' => $user->profile_status,
            'bio' => $user->bio,
            'profile_picture_url' => $user->profile_picture_path
                ? PublicMediaUrl::forPath($user->profile_picture_path)
                : null,
            'current_address' => $user->current_address,
            'permanent_address' => $user->permanent_address,
            'contact_number' => $user->contact_number,
            'contact_email' => $user->contact_email,
        ]);
    }

    public function posts(Request $request, User $user)
    {
        abort_unless(
            $user->role === 'user' && $user->status === 'active' && $user->email_verified_at !== null,
            404,
        );

        $posts = Post::query()
            ->with([
                'author:id,first_name,last_name,profile_picture_path',
                'category:id,name',
                'location:id,name',
                'images',
                'verificationQuestions',
                'reactions' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->withCount(['comments', 'reactions'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'claim_pending', 'handover_pending'])
            ->latest()
            ->paginate(15);

        return PostResource::collection($posts);
    }
}
