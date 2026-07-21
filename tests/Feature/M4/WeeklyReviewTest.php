<?php

use App\Mail\WeeklyReview;
use App\Models\Activity;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Mail;

test('weekly reviews go to opted-in onboarded users with activity', function () {
    Mail::fake();

    $active = ukDoctor();
    InboxItem::factory()->for($active)->ready()->create();

    $optedOut = ukDoctor();
    $optedOut->update(['weekly_email_enabled' => false]);
    InboxItem::factory()->for($optedOut)->ready()->create();

    $this->artisan('cpd:send-weekly-reviews')->assertSuccessful();

    Mail::assertQueued(WeeklyReview::class, fn ($mail) => $mail->user->is($active));
    Mail::assertNotQueued(WeeklyReview::class, fn ($mail) => $mail->user->is($optedOut));
});

test('the weekly review summarises the week and the standing totals', function () {
    Mail::fake();

    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();
    Activity::factory()->for($user)->count(2)->create([
        'appraisal_period_id' => $period->id,
        'starts_on' => $period->starts_on->copy()->addMonth(),
    ]);
    InboxItem::factory()->for($user)->ready()->create();

    $this->artisan('cpd:send-weekly-reviews');

    Mail::assertQueued(WeeklyReview::class, function (WeeklyReview $mail) use ($user) {
        return $mail->user->is($user)
            && $mail->summary['captured_this_week'] === 1
            && $mail->summary['awaiting'] === 1
            && $mail->summary['total_activities'] === 2
            && $mail->summary['dump_address'] === $user->inboundEmailAddress();
    });
});

test('the weekly review carries the learning section and a learning-only week still sends', function () {
    Mail::fake();

    $user = ukDoctor();
    // No inbox items at all — learning is the only signal this week.
    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [['id' => 'n1', 'text' => 'A weekly nugget', 'done' => false]],
        'actions' => [['id' => 'a1', 'text' => 'Done already', 'done' => true]],
    ]);

    $this->artisan('cpd:send-weekly-reviews');

    Mail::assertQueued(WeeklyReview::class, function (WeeklyReview $mail) use ($user) {
        return $mail->user->is($user)
            && $mail->summary['learning'][0]['nuggets'][0]['text'] === 'A weekly nugget'
            // Ticked-done actions never resurface.
            && $mail->summary['learning'][0]['actions'] === [];
    });
});

test('the learning section respects its sub-toggle and hidden takeaways', function () {
    Mail::fake();

    $subToggledOff = ukDoctor();
    $subToggledOff->update(['weekly_learning_recap_enabled' => false]);
    Activity::factory()->for($subToggledOff)->create([
        'appraisal_period_id' => $subToggledOff->currentAppraisalPeriod()->id,
        'nuggets' => [['id' => 'n1', 'text' => 'Hidden', 'done' => false]],
    ]);

    $excludedActivity = ukDoctor();
    Activity::factory()->for($excludedActivity)->create([
        'appraisal_period_id' => $excludedActivity->currentAppraisalPeriod()->id,
        // "Hidden from notifications" at review: arrived done.
        'nuggets' => [['id' => 'n2', 'text' => 'Held back', 'done' => true]],
    ]);

    $this->artisan('cpd:send-weekly-reviews');

    Mail::assertQueued(WeeklyReview::class, fn (WeeklyReview $mail) => $mail->user->is($subToggledOff)
        ? $mail->summary['learning'] === []
        : true);
    Mail::assertQueued(WeeklyReview::class, fn (WeeklyReview $mail) => $mail->user->is($excludedActivity)
        ? $mail->summary['learning'] === []
        : true);
});

test('the weekly review email renders', function () {
    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    $html = (new WeeklyReview($user, [
        'captured_this_week' => 3,
        'points_this_week' => 7.5,
        'awaiting' => 2,
        'total_activities' => 12,
        'total_points' => 34.0,
        'thin_areas' => ['Domain 2'],
        'dump_address' => $user->inboundEmailAddress(),
        'inbox_url' => 'https://cpd-dump.test/inbox',
    ]))->render();

    expect($html)
        ->toContain('3')
        ->toContain('Domain 2')
        ->toContain($user->inboundEmailAddress())
        // Current brand: the wordmark with orange stop and Bricolage
        // Grotesque, not the retired stamp logo.
        ->toContain('cpd dump')
        ->toContain('Bricolage Grotesque')
        ->not->toContain('text-transform: uppercase');
});
