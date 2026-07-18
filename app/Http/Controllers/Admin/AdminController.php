<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AiGeneration;
use App\Models\GeneratedReport;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        $thirtyDays = now()->subDays(30);

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'users' => User::count(),
                'onboarded' => User::whereNotNull('onboarded_at')->count(),
                'new_users_30d' => User::where('created_at', '>=', $thirtyDays)->count(),
                'activities' => Activity::count(),
                'inbox_items_30d' => InboxItem::where('created_at', '>=', $thirtyDays)->count(),
                'ai_calls_30d' => AiGeneration::where('created_at', '>=', $thirtyDays)->count(),
                'platform_output_tokens_30d' => (int) AiGeneration::where('created_at', '>=', $thirtyDays)
                    ->where('used_user_key', false)
                    ->sum('output_tokens'),
                'reports_30d' => GeneratedReport::where('created_at', '>=', $thirtyDays)->count(),
            ],
        ]);
    }

    public function users(Request $request): Response
    {
        $users = User::query()
            ->withCount(['activities', 'inboxItems'])
            ->latest()
            ->paginate(50)
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profession' => $user->profession?->name,
                'onboarded' => $user->onboarded_at !== null,
                'is_admin' => $user->is_admin,
                'own_key' => $user->ai_api_key !== null,
                'activities_count' => $user->activities_count,
                'inbox_items_count' => $user->inbox_items_count,
                'created_at' => $user->created_at->toDateString(),
            ]);

        return Inertia::render('admin/users', ['users' => $users]);
    }

    public function usage(Request $request): Response
    {
        $daily = AiGeneration::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw("date_trunc('day', created_at)::date as day")
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(input_tokens) as input_tokens')
            ->selectRaw('sum(output_tokens) as output_tokens')
            ->selectRaw('sum(case when used_user_key then 0 else output_tokens end) as platform_output_tokens')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get();

        $byPurpose = AiGeneration::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->select('purpose')
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(output_tokens) as output_tokens')
            ->groupBy('purpose')
            ->orderByDesc(DB::raw('count(*)'))
            ->get();

        $topUsers = AiGeneration::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->where('used_user_key', false)
            ->select('user_id')
            ->selectRaw('count(*) as calls')
            ->selectRaw('sum(output_tokens) as output_tokens')
            ->groupBy('user_id')
            ->orderByDesc(DB::raw('sum(output_tokens)'))
            ->limit(20)
            ->with('user:id,name,email')
            ->get();

        return Inertia::render('admin/usage', [
            'daily' => $daily,
            'byPurpose' => $byPurpose,
            'topUsers' => $topUsers,
        ]);
    }
}
