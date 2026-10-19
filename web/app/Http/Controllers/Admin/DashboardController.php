<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaimRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $types = Post::query()
            ->select('type', DB::raw('count(*) as total'))
            ->whereIn('status', ['active', 'claim_pending', 'approved_for_meetup'])
            ->groupBy('type')
            ->pluck('total', 'type');
        $lostCount = (int) ($types['lost'] ?? 0);
        $foundCount = (int) ($types['found'] ?? 0);
        $total = $lostCount + $foundCount;
        $admin = Auth::guard('admin')->user();

        return view('admin.dashboard', [
            'activePosts' => Post::where('status', 'active')->count(),
            'pendingClaims' => ClaimRequest::where('status', 'pending')->count(),
            'pendingSightings' => DB::table('lost_sightings')->where('answer', 'yes')
                ->where('author_decision', 'approved')->where('admin_decision', 'pending')->count(),
            'pendingHandovers' => DB::table('handovers')->whereNull('verified_by')->where('expires_at', '>', now())->count(),
            'pendingReports' => DB::table('reports')->where('status', 'pending')->count(),
            'pendingFeedback' => DB::table('feedback')->whereIn('status', ['open', 'reviewing'])->count(),
            'retrievedThisMonth' => Post::where('status', 'retrieved')->whereMonth('updated_at', now()->month)->count(),
            'newUsers' => User::where('created_at', '>=', now()->subDays(30))->count(),
            'lostCount' => $lostCount,
            'foundCount' => $foundCount,
            'lostPercent' => $total > 0 ? round($lostCount / $total * 100, 1) : 0,
            'foundPercent' => $total > 0 ? round($foundCount / $total * 100, 1) : 0,
            'totalActivePosts' => $total,
            'adminNotifications' => $admin->unreadNotifications()->latest()->limit(8)->get(),
        ]);
    }
}
