<?php

use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Models\InboxItem;
use App\Models\Profession;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('guests are redirected to login from the inbox', function () {
    $this->get('/inbox')->assertRedirect('/login');
});

test('users who have not onboarded are sent to onboarding', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/inbox')->assertRedirect(route('onboarding.show'));
    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.show'));
});

test('onboarding stores profession, period and dump address', function () {
    $user = User::factory()->create();
    $profession = Profession::where('slug', 'uk-doctor')->firstOrFail();

    $this->actingAs($user)
        ->post('/onboarding', [
            'profession_id' => $profession->id,
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
        ])
        ->assertRedirect(route('inbox'));

    $user->refresh();

    expect($user->hasOnboarded())->toBeTrue()
        ->and($user->profession_id)->toBe($profession->id)
        ->and($user->currentAppraisalPeriod())->not->toBeNull()
        ->and($user->inboundEmailAddress())->toEndWith('@'.config('cpd.inbound_email_domain'));
});

test('the inbox page renders with items, stats and reference data', function () {
    $user = ukDoctor();
    InboxItem::factory()->for($user)->ready()->create();

    $this->actingAs($user)
        ->get('/inbox')
        ->assertInertia(fn ($page) => $page
            ->component('inbox')
            ->has('items', 1)
            ->has('reference.activityTypes')
            ->has('reference.domains', 4)
            ->has('reference.reflectionPrompts', 3)
        );
});

test('manual evidence can be dumped via the inbox endpoint', function () {
    Queue::fake();
    $user = ukDoctor();

    $this->actingAs($user)
        ->post('/inbox', ['title' => 'Taught the FRCR physics session'])
        ->assertRedirect();

    expect($user->inboxItems()->count())->toBe(1);
    Queue::assertPushed(AnalyzeInboxItem::class);
});

test('approving via http creates the activity and users cannot touch others\' items', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();

    $stranger = ukDoctor();

    $this->actingAs($stranger)
        ->post("/inbox/{$item->id}/approve", $item->ai_analysis)
        ->assertForbidden();

    $this->actingAs($user)
        ->post("/inbox/{$item->id}/approve", $item->ai_analysis)
        ->assertRedirect();

    expect($item->fresh()->status)->toBe(InboxItemStatus::Approved)
        ->and($user->activities()->count())->toBe(1);
});

test('dismissing with an ignore rule creates the rule', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create(['source' => 'calendar']);

    $this->actingAs($user)
        ->delete("/inbox/{$item->id}", [
            'ignore_rule' => ['field' => 'title', 'operator' => 'contains', 'value' => 'Lung MDT'],
        ])
        ->assertRedirect();

    expect($item->fresh()->status)->toBe(InboxItemStatus::Dismissed)
        ->and($user->ignoreRules()->count())->toBe(1)
        ->and($user->ignoreRules->first()->value)->toBe('Lung MDT');
});
