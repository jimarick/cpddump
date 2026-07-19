<?php

use App\Ai\InboxAnalystAgent;
use App\Enums\EvidenceSource;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Mail\RecurrenceReminder;
use App\Models\InboxItem;
use App\Models\Recurrence;
use App\Services\AiGateway;
use App\Services\StatsService;
use Database\Factories\InboxItemFactory;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Ai;

test('a scheduled recurrence creates a ready template draft and advances', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->create([
        'title' => 'Lung MDT',
        'next_due_on' => today()->toDateString(),
    ]);

    $this->artisan('cpd:generate-recurring')->assertSuccessful();

    $item = $user->inboxItems()->firstOrFail();

    expect($item->source)->toBe(EvidenceSource::Recurring)
        ->and($item->status)->toBe(InboxItemStatus::Ready)
        ->and($item->recurrence_id)->toBe($recurrence->id)
        ->and($item->ai_analysis['title'])->toBe('Lung MDT')
        ->and($item->ai_analysis['starts_on'])->toBe(today()->toDateString())
        ->and((float) $item->ai_analysis['cpd_points'])->toBe(0.5)
        ->and($recurrence->fresh()->next_due_on->toDateString())
        ->toBe(today()->addWeek()->toDateString());

    // Running again creates nothing new — next occurrence is in the future.
    $this->artisan('cpd:generate-recurring')->assertSuccessful();

    expect($user->inboxItems()->count())->toBe(1);
});

test('same-day reminders are emailed when the draft is created', function () {
    Mail::fake();

    $user = ukDoctor();
    Recurrence::factory()->for($user)->create(['reminder' => 'same_day']);

    $this->artisan('cpd:generate-recurring')->assertSuccessful();

    Mail::assertQueued(RecurrenceReminder::class);
});

test('an overdue expectation drops a prompt draft in the inbox once', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->expectation(4)->create();
    $recurrence->forceFill(['created_at' => now()->subDays(120)])->save();

    $this->artisan('cpd:generate-recurring')->assertSuccessful();

    $item = $user->inboxItems()->firstOrFail();

    expect($item->status)->toBe(InboxItemStatus::Ready)
        ->and($item->ai_analysis['starts_on'])->toBeNull()
        ->and($item->ai_analysis['summary'])->toContain('Did a')
        ->and($recurrence->fresh()->last_prompted_on->toDateString())->toBe(today()->toDateString());

    // While the prompt sits unresolved, no second prompt is created.
    $this->artisan('cpd:generate-recurring')->assertSuccessful();

    expect($user->inboxItems()->count())->toBe(1);
});

test('real evidence matched by the AI supersedes the waiting prompt', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->expectation(4)->create(['title' => 'Audit meeting']);

    $prompt = InboxItem::factory()->for($user)->create([
        'source' => EvidenceSource::Recurring,
        'status' => InboxItemStatus::Ready,
        'recurrence_id' => $recurrence->id,
    ]);

    $email = InboxItem::factory()->for($user)->fromEmail()->create();

    Ai::fakeAgent(InboxAnalystAgent::class, [
        array_merge((new InboxItemFactory)->exampleAnalysis(), [
            'matched_recurrence_id' => $recurrence->id,
        ]),
    ]);

    (new AnalyzeInboxItem($email))->handle(app(AiGateway::class));

    expect($email->fresh()->recurrence_id)->toBe($recurrence->id)
        ->and(InboxItem::find($prompt->id))->toBeNull();
});

test('approving a matched item counts toward the expectation', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->expectation(4)->create();

    $item = InboxItem::factory()->for($user)->ready()->create([
        'recurrence_id' => $recurrence->id,
    ]);

    $activity = $item->approve([
        'title' => 'Audit meeting — Q1',
        'activity_type_slug' => 'course',
        'starts_on' => today()->toDateString(),
        'cpd_points' => 1,
        'reflection_draft' => [],
        'category_slugs' => [],
        'domain_codes' => [],
        'attribute_codes' => [],
    ]);

    expect($activity->recurrence_id)->toBe($recurrence->id)
        ->and($recurrence->fresh()->last_matched_on)->not->toBeNull();

    $gaps = app(StatsService::class)->gaps($user, $user->currentAppraisalPeriod());

    expect($gaps['expectations'])->toHaveCount(1)
        ->and($gaps['expectations'][0]['captured'])->toBe(1)
        ->and($gaps['expectations'][0]['expected'])->toBe(4);
});

test('regulars can be created, paused and removed from the UI endpoints', function () {
    $user = ukDoctor();

    $this->actingAs($user)->post('/recurrences', [
        'kind' => 'expectation',
        'title' => 'Journal club',
        'activity_type_slug' => 'course',
        'cpd_points' => 1,
        'expected_per_year' => 6,
        'reminder' => 'weekly',
    ])->assertRedirect();

    $recurrence = $user->recurrences()->firstOrFail();

    expect($recurrence->kind)->toBe('expectation')
        ->and($recurrence->expected_per_year)->toBe(6);

    $this->actingAs($user)
        ->patch("/recurrences/{$recurrence->id}", ['is_active' => false])
        ->assertRedirect();

    expect($recurrence->fresh()->is_active)->toBeFalse();

    // A waiting draft is binned when the regular is removed.
    $draft = InboxItem::factory()->for($user)->create([
        'source' => EvidenceSource::Recurring,
        'status' => InboxItemStatus::Ready,
        'recurrence_id' => $recurrence->id,
    ]);

    $this->actingAs($user)->delete("/recurrences/{$recurrence->id}")->assertRedirect();

    expect(Recurrence::find($recurrence->id))->toBeNull()
        ->and(InboxItem::find($draft->id))->toBeNull();
});

test('a regular can drop an occurrence draft for today on demand', function () {
    $user = ukDoctor();
    $recurrence = Recurrence::factory()->for($user)->create(['title' => 'Lung MDT']);

    $this->actingAs($user)
        ->post("/recurrences/{$recurrence->id}/occurrence")
        ->assertRedirect();

    $item = $user->inboxItems()->firstOrFail();

    expect($item->status)->toBe(InboxItemStatus::Ready)
        ->and($item->recurrence_id)->toBe($recurrence->id)
        ->and($item->ai_analysis['starts_on'])->toBe(today()->toDateString());
});

test('strangers cannot touch someone else\'s recurrence', function () {
    $owner = ukDoctor();
    $recurrence = Recurrence::factory()->for($owner)->create();

    $this->actingAs(ukDoctor())
        ->delete("/recurrences/{$recurrence->id}")
        ->assertForbidden();
});
