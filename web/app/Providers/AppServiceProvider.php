<?php

namespace App\Providers;

use App\Models\ClaimRequest;
use App\Models\LostSighting;
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
                'claims' => ClaimRequest::where('status', 'pending')->count(),
                'sightings' => LostSighting::where('answer', 'yes')
                    ->where('author_decision', 'approved')
                    ->where('admin_decision', 'pending')
                    ->count(),
                'handovers' => DB::table('handovers')
                    ->whereNull('verified_by')
                    ->where('expires_at', '>', now())
                    ->count(),
                'reports' => DB::table('reports')->where('status', 'pending')->count(),
                'feedback' => DB::table('feedback')->whereIn('status', ['open', 'reviewing'])->count(),
            ]);
        });
    }
}
