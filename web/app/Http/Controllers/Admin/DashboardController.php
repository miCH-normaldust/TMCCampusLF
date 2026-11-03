<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ClaimRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $admin = Auth::guard('admin')->user();
        $pendingClaims = ClaimRequest::where('status', 'pending')->count();
        $pendingSightings = DB::table('lost_sightings')->where('answer', 'yes')
            ->where('author_decision', 'approved')->where('admin_decision', 'pending')->count();
        $pendingHandovers = DB::table('handovers')
            ->where(fn ($query) => $query->whereNull('owner_confirmed_at')->orWhereNull('finder_confirmed_at'))
            ->count();
        $pendingReports = DB::table('reports')->where('status', 'pending')->count();
        $pendingFeedback = DB::table('feedback')->whereIn('status', ['open', 'reviewing'])->count();
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
            'pendingSightings' => $pendingSightings,
            'pendingHandovers' => $pendingHandovers,
            'pendingReports' => $pendingReports,
            'pendingFeedback' => $pendingFeedback,
            'retrievedThisMonth' => Post::where('status', 'retrieved')
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'newUsers' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'activePosts' => Post::whereIn('status', ['active', 'claim_pending', 'handover_pending'])->count(),
            'foundPosts' => Post::where('type', 'found')->count(),
            'adminNotifications' => $admin->unreadNotifications()->latest()->limit(8)->get(),
            'shortcuts' => $shortcuts,
        ]);
    }

    public function communityFeed(Request $request): View
    {
        $filters = $request->validate([
            'view' => ['nullable', 'in:posts,people'],
            'type' => ['nullable', 'in:lost,found'],
            'status' => ['nullable', 'in:active,claim_pending,handover_pending,retrieved,hidden,archived'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'in:latest,oldest'],
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

        $posts = Post::with(['author:id,first_name,last_name', 'category:id,name', 'location:id,name', 'images'])
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
            ->orderBy('created_at', ($filters['sort'] ?? 'latest') === 'oldest' ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('admin.community-feed', [
            'view' => $view,
            'posts' => $posts,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
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
