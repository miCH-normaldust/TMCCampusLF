<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackAdminRouteUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $admin = $request->user('admin');
        $routeName = $request->route()?->getName();
        $usageRoute = $this->usageRouteName($routeName);

        if ($admin && $usageRoute && $routeName !== 'admin.logout' && $response->getStatusCode() < 400) {
            $counter = $request->isMethod('GET') ? 'visits' : 'interactions';
            $now = now();

            DB::table('admin_route_usage')->insertOrIgnore([
                'admin_id' => $admin->id,
                'route_name' => $usageRoute,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('admin_route_usage')
                ->where('admin_id', $admin->id)
                ->where('route_name', $usageRoute)
                ->increment($counter, 1, ['updated_at' => $now]);
        }

        return $response;
    }

    private function usageRouteName(?string $routeName): ?string
    {
        if (! $routeName || ! str_starts_with($routeName, 'admin.')) {
            return null;
        }

        foreach ([
            'admin.handovers.history' => 'admin.handovers.history',
            'admin.reports.' => 'admin.reports.index',
            'admin.claims.' => 'admin.claims.index',
            'admin.sightings.' => 'admin.sightings.index',
            'admin.handovers.' => 'admin.handovers.index',
            'admin.feedback.' => 'admin.feedback.index',
            'admin.users.' => 'admin.users.index',
            'admin.activity.' => 'admin.activity.index',
            'admin.audit.' => 'admin.audit.index',
            'admin.settings.' => 'admin.settings.index',
        ] as $prefix => $usageRoute) {
            if (str_starts_with($routeName, $prefix)) {
                return $usageRoute;
            }
        }

        return $routeName;
    }
}
