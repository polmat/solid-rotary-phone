<?php

namespace App\Http\Middleware;

use App\Models\Feature;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $feature = Feature::where('key', $featureKey)->first();

        abort_unless($feature, 404, 'Feature not found.');
        abort_unless($feature->isEnabled(), 403, 'Feature is globally disabled.');

        $userId = $request->query('user_id');

        abort_unless($userId, 403, 'No user provided.');
        abort_unless($feature->isEnabledFor(User::find($userId)), 403, 'Feature not enabled for this user.');

        return $next($request);
    }
}
