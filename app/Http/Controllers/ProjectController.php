<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = $request->user()->projects()
            ->withCount('activities')
            ->withSum('activities as points_sum', 'cpd_points')
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'kind' => $p->kind->value,
                'title' => $p->title,
                'description' => $p->description,
                'status' => $p->status->value,
                'due_on' => $p->due_on?->toDateString(),
                'activities_count' => $p->activities_count,
                'points' => (float) ($p->points_sum ?? 0),
            ]);

        return Inertia::render('projects/index', ['projects' => $projects]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $request->user()->projects()->create($validated);

        return back()->with('success', 'Added.');
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->update($this->validated($request));

        return back()->with('success', 'Updated.');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $project->delete();

        return back()->with('success', 'Deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'in:project,objective'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'in:open,achieved,carried_over'],
            'due_on' => ['nullable', 'date'],
        ]);
    }
}
