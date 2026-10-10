<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClinicPermission
{
    public function handle(Request $request, Closure $next, string $permission = 'access'): Response
    {
        if (! ($request->user()?->clinicPermissions()[$permission] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden. You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
