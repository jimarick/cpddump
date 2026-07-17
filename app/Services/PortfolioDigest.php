<?php

namespace App\Services;

use App\Models\AppraisalPeriod;
use App\Models\User;

/**
 * Compiles a user's period portfolio into a compact text digest that the
 * question-answering and report-writing agents consume.
 */
class PortfolioDigest
{
    public function build(User $user, AppraisalPeriod $period, int $charLimit = 50_000): string
    {
        $activities = $user->activities()
            ->where('appraisal_period_id', $period->id)
            ->with(['type:id,name', 'categories:id,name', 'frameworkDomains:id,code', 'projects:id,title'])
            ->orderBy('starts_on')
            ->get();

        $projects = $user->projects()->withCount('activities')->get();

        $lines = [
            "Appraisal period: {$period->label} ({$period->starts_on->toDateString()} to {$period->ends_on->toDateString()})",
            'Total activities: '.$activities->count(),
            'Total CPD points: '.(float) $activities->sum('cpd_points'),
            '',
            '## Projects and PDP objectives',
        ];

        foreach ($projects as $project) {
            $lines[] = "- [{$project->kind->value}] {$project->title} ({$project->status->value}, {$project->activities_count} linked activities)"
                .(filled($project->description) ? " — {$project->description}" : '');
        }

        $lines[] = '';
        $lines[] = '## Activities';

        foreach ($activities as $activity) {
            $lines[] = '';
            $lines[] = "### {$activity->title}";
            $lines[] = collect([
                $activity->starts_on?->toDateString(),
                $activity->type->name,
                $activity->cpd_points.' CPD points',
                $activity->organisation,
                $activity->frameworkDomains->pluck('code')->implode(', ') ?: null,
                $activity->categories->pluck('name')->implode(', ') ?: null,
                $activity->projects->pluck('title')->whenNotEmpty(fn ($p) => 'projects: '.$p->implode(', '), fn () => null),
            ])->filter()->implode(' · ');

            if (filled($activity->details)) {
                $lines[] = $activity->details;
            }

            foreach ($activity->reflection as $key => $text) {
                if (filled($text)) {
                    $lines[] = "Reflection ({$key}): {$text}";
                }
            }
        }

        return mb_substr(implode("\n", $lines), 0, $charLimit);
    }
}
