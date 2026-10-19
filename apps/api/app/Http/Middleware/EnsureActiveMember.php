<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        $isActive = $user instanceof User
            && User::query()->whereKey($user->id)->where('is_active', true)->exists();

        if (! $isActive) {
            if ($user instanceof User) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
