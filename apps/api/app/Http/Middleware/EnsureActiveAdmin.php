<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        $isActiveAdministrator = $user instanceof User
            && User::query()
                ->whereKey($user->id)
                ->where('is_active', true)
                ->where('is_admin', true)
                ->exists();

        if (! $isActiveAdministrator) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
