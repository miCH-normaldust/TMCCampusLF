<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Handover;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $admin = Auth::guard('admin')->user();
        $pendingClaims = ClaimRequest::count();
        $lostItemProofs = DB::table('lost_sightings')
            ->where('answer', 'yes')
            ->count();
        $pendingHandovers = DB::table('handovers')
            ->whereNotIn('status', ['completed', 'unsuccessful'])
            ->count();
        $pendingReports = DB::table('reports')->where('status', 'pending')->count();
        $pendingFeedback = DB::table('feedback')->whereIn('status', ['open', 'reviewing'])->count();
        $activityStart = today()->subDays(29);
        $dailyActivity = collect(range(0, 29))
            ->map(fn (int $offset): Carbon => $activityStart->copy()->addDays($offset))
            ->mapWithKeys(fn (Carbon $date): array => [$date->toDateString() => [
                'label' => $date->format('M j'),
                'lost' => 0,
                'found' => 0,
            ]]);
        $postActivity = DB::table('posts')
            ->selectRaw('DATE(created_at) as activity_date, type, COUNT(*) as total')
            ->where('created_at', '>=', $activityStart)
            ->groupBy('activity_date', 'type')
            ->get();
        foreach ($postActivity as $entry) {
            if ($dailyActivity->has($entry->activity_date)) {
                $day = $dailyActivity->get($entry->activity_date);
                $day[$entry->type] = (int) $entry->total;
                $dailyActivity->put($entry->activity_date, $day);
            }
        }
        $engagement = [
            'reports' => $this->countByUserType('reports', 'reporter_id', $activityStart),
            'proofs' => $this->countByUserType('lost_sightings', 'user_id', $activityStart),
            'claims' => $this->countByUserType('claim_requests', 'claimant_id', $activityStart),
        ];
        $routeUsage = DB::table('admin_route_usage')
            ->where('admin_id', $admin->id)
            ->get()
            ->keyBy('route_name');
        $shortcuts = [
            ['label' => 'Users', 'icon' => '♙', 'route' => 'admin.users.index', 'pending' => null],
            ['label' => 'Reports', 'icon' => '⚑', 'route' => 'admin.reports.index', 'pending' => $pendingReports],
            ['label' => 'Activity', 'icon' => '◷', 'route' => 'admin.activity.index', 'pending' => null],
            ['label' => 'Feedback', 'icon' => '✉', 'route' => 'admin.feedback.index', 'pending' => $pendingFeedback],
        ];
        foreach ($shortcuts as &$shortcut) {
            $usage = $routeUsage->get($shortcut['route']);
            $shortcut['description'] = $this->usageDescription($usage);
        }
        unset($shortcut);

        return view('admin.dashboard', [
            'pendingClaims' => $pendingClaims,
            'lostItemProofs' => $lostItemProofs,
            'pendingHandovers' => $pendingHandovers,
            'pendingReports' => $pendingReports,
            'pendingFeedback' => $pendingFeedback,
            'usersCount' => User::where('role', 'user')->count(),
            'retrievedThisMonth' => Post::where('status', 'retrieved')
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'solvedPosts' => Post::where('status', 'retrieved')->count(),
            'newUsers' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'activePosts' => Post::whereIn('status', ['active', 'claim_pending', 'handover_pending'])->count(),
            'foundPosts' => Post::where('type', 'found')->count(),
            'lostPosts' => Post::where('type', 'lost')->count(),
            'adminNotifications' => $admin->unreadNotifications()->latest()->limit(8)->get(),
            'dailyActivity' => $dailyActivity,
            'engagement' => $engagement,
            'shortcuts' => $shortcuts,
        ]);
    }

    private function countByUserType(string $table, string $userColumn, Carbon $start): array
    {
        $counts = DB::table($table)
            ->join('users', 'users.id', '=', "{$table}.{$userColumn}")
            ->where("{$table}.created_at", '>=', $start)
            ->selectRaw('users.user_type, COUNT(*) as total')
            ->groupBy('users.user_type')
            ->pluck('total', 'users.user_type');

        return [
            'student' => (int) ($counts['student'] ?? 0),
            'staff' => (int) ($counts['staff'] ?? 0),
            'instructor' => (int) ($counts['instructor'] ?? 0),
        ];
    }

    public function communityFeed(Request $request): View
    {
        $filters = $request->validate([
            'view' => ['nullable', 'in:posts,people'],
            'type' => ['nullable', 'in:lost,found'],
            'status' => ['nullable', 'in:active,claim_pending,handover_pending,retrieved,hidden,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'post_id' => ['nullable', 'integer', 'exists:posts,id'],
        ]);
        $view = $filters['view'] ?? 'posts';
        if ($view === 'people') {
            $people = User::query()
                ->where('role', 'user')
                ->when($filters['search'] ?? null, function ($query, $search): void {
                    $term = '%'.$search.'%';
                    $query->where(fn ($builder) => $builder
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term));
                })
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate(30)
                ->withQueryString();

            return view('admin.community-feed', [
                'view' => $view,
                'people' => $people,
                'categories' => Category::orderBy('name')->get(['id', 'name']),
            ]);
        }

        $posts = Post::with(['author:id,first_name,last_name,profile_picture_path', 'category:id,name', 'location:id,name', 'images'])
            ->withCount([
                'claimRequests',
                'sightings' => fn ($query) => $query->where('answer', 'yes'),
            ])
            ->when($filters['post_id'] ?? null, fn ($query, $postId) => $query->whereKey($postId))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $term = '%'.$search.'%';
                $query->where(fn ($builder) => $builder
                    ->where('item_name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('author', fn ($author) => $author
                        ->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)));
            })
            ->latest()
            ->paginate(9)
            ->withQueryString();

        return view('admin.community-feed', [
            'view' => $view,
            'posts' => $posts,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function communityPost(Post $post): View
    {
        $post->load([
            'author:id,first_name,last_name',
            'category:id,name',
            'location:id,name',
            'images',
            'claimRequests' => fn ($query) => $query
                ->with(['claimant:id,first_name,last_name,tmc_id_no', 'evidenceImages'])
                ->latest(),
            'sightings' => fn ($query) => $query
                ->where('answer', 'yes')
                ->with('user:id,first_name,last_name')
                ->orderBy('created_at')
                ->orderBy('id'),
        ]);
        foreach ($post->sightings->groupBy('user_id') as $attempts) {
            foreach ($attempts->values() as $index => $sighting) {
                $sighting->attempt_number = $index + 1;
                $sighting->attempt_total = $attempts->count();
            }
        }

        $handovers = Handover::with([
            'claimRequest.post.author:id,first_name,last_name',
            'claimRequest.claimant:id,first_name,last_name',
            'sighting.post.author:id,first_name,last_name',
            'sighting.user:id,first_name,last_name',
            'location:id,name',
        ])
            ->where(fn ($query) => $query
                ->whereHas('claimRequest', fn ($claims) => $claims->where('post_id', $post->id))
                ->orWhereHas('sighting', fn ($sightings) => $sightings->where('post_id', $post->id)))
            ->latest()
            ->get();

        return view('admin.community-post', compact('post', 'handovers'));
    }

    private function usageDescription(?object $usage): string
    {
        if (! $usage) {
            return 'Not visited yet';
        }

        $visits = (int) $usage->visits;
        $interactions = (int) $usage->interactions;

        return number_format($visits).' '.($visits === 1 ? 'visit' : 'visits')
            .' · '.number_format($interactions).' '.($interactions === 1 ? 'action' : 'actions');
    }
}
