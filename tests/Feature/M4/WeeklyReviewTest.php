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
        ->toContain($user->inboundEmailAddress());
});
