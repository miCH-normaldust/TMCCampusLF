<?php

namespace App\Providers;

use App\Models\ClaimRequest;
use App\Models\LostSighting;
use App\Models\PostRetrievalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('admin.layout', function ($view): void {
            $admin = Auth::guard('admin')->user();
            if (! $admin) {
                return;
            }

            $view->with('adminNavCounts', [
                'notifications' => $admin->unreadNotifications()->count(),
                'claims' => ClaimRequest::where('status', 'pending')->count()
                    + PostRetrievalRequest::where('status', 'pending')
                        ->whereHas('post', fn ($query) => $query->where('type', 'found'))
                        ->count(),
                'sightings' => LostSighting::where('answer', 'yes')
                    ->where('author_decision', 'approved')
                    ->where('admin_decision', 'pending')
                    ->count()
                    + PostRetrievalRequest::where('status', 'pending')
                        ->whereHas('post', fn ($query) => $query->where('type', 'lost'))
                        ->count(),
                'handovers' => DB::table('handovers')
                    ->where(function ($query): void {
                        $query->whereNull('owner_confirmed_at')
                            ->orWhereNull('finder_confirmed_at');
                    })
                    ->count(),
                'reports' => DB::table('reports')->where('status', 'pending')->count(),
                'feedback' => DB::table('feedback')->whereIn('status', ['open', 'reviewing'])->count(),
            ]);
        });
    }
}
