<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $guard = $role === 'admin' ? 'admin' : 'sanctum';
        $user = auth($guard)->user();

        if (! $user || $user->role !== $role || $user->status !== 'active') {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You are not authorized to access this resource.'], 403);
            }

            abort(403, 'You are not authorized to access this resource.');
        }

        return $next($request);
    }
}
