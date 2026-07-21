<?php

namespace Database\Factories;

use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxItem>
 */
class InboxItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => EvidenceSource::Manual,
            'status' => InboxItemStatus::Pending,
            'raw_payload' => ['title' => fake()->sentence(4), 'details' => fake()->paragraph()],
            'content_hash' => hash('sha256', fake()->uuid()),
        ];
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => InboxItemStatus::Ready,
            'ai_analysis' => $this->exampleAnalysis(),
            'ai_warnings' => ['pii_flags' => [], 'missing_evidence' => [], 'possible_duplicate_activity_ids' => []],
            'analysed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => InboxItemStatus::Failed,
            'failure_reason' => 'Analysis failed. You can retry, or fill the details in manually.',
        ]);
    }

    public function fromEmail(): static
    {
        return $this->state(fn () => [
            'source' => EvidenceSource::Email,
            'raw_payload' => [
                'subject' => 'FW: Your ALS recertification certificate',
                'from' => 'no-reply@resus.org.uk',
                'body' => 'Please find attached your Advanced Life Support recertification certificate.',
            ],
        ]);
    }

    /**
     * A realistic analysis payload following the InboxAnalystAgent contract.
     *
     * @return array<string, mixed>
     */
    public function exampleAnalysis(): array
    {
        return [
            'title' => 'Advanced Life Support — recertification',
            'activity_type_slug' => 'course',
            'starts_on' => now()->subDays(10)->toDateString(),
            'ends_on' => null,
            'organisation' => 'Resuscitation Council UK',
            'cpd_points' => 6,
            'summary' => 'Completed the Advanced Life Support recertification course, including simulated cardiac arrest scenarios.',
            // Post-job shape: AnalyzeInboxItem wraps the model's plain
            // strings with ids before persisting.
            'nuggets' => [
                ['id' => '01hexamplenugget0000000001', 'text' => 'Updated ALS algorithm changes', 'done' => false],
                ['id' => '01hexamplenugget0000000002', 'text' => 'Refreshed team-leadership in arrest scenarios', 'done' => false],
            ],
            'actions' => [
                ['id' => '01hexampleaction0000000001', 'text' => 'Book the paediatric variant next year', 'done' => false],
            ],
            'reflection_draft' => [
                'why_selected' => 'This recertification keeps my resuscitation skills current, which is core to safe practice.',
                'learning_need' => 'My previous ALS certificate was due to expire and the algorithms had been updated.',
                'practice_change' => 'I will apply the updated algorithm and brief the team differently at the next arrest call.',
            ],
            'category_slugs' => ['cpd'],
            'domain_codes' => ['D1'],
            'attribute_codes' => ['1.1', '1.2'],
            'suggested_project_ids' => [],
            'possible_duplicate_activity_ids' => [],
            'confidence' => 0.92,
            'pii_flags' => [],
            'missing_evidence' => [],
        ];
    }
}
