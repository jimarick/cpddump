<?php

use App\Mail\WeeklyReview;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['services.ses_inbound.verify_signature' => false]);
});

function sesEvent(array $message): string
{
    return json_encode(['Type' => 'Notification', 'Message' => json_encode($message)]);
}

test('a permanent bounce suppresses the user', function () {
    $user = ukDoctor();

    $this->call('POST', '/webhooks/ses-events', content: sesEvent([
        'notificationType' => 'Bounce',
        'bounce' => [
            'bounceType' => 'Permanent',
            'bouncedRecipients' => [['emailAddress' => $user->email]],
        ],
    ]))->assertOk();

    $user->refresh();

    expect($user->email_suppressed_at)->not->toBeNull()
        ->and($user->email_suppression_reason)->toBe('bounce');
});

test('a transient bounce does not suppress', function () {
    $user = ukDoctor();

    $this->call('POST', '/webhooks/ses-events', content: sesEvent([
        'notificationType' => 'Bounce',
        'bounce' => [
            'bounceType' => 'Transient',
            'bouncedRecipients' => [['emailAddress' => $user->email]],
        ],
    ]))->assertOk();

    expect($user->fresh()->email_suppressed_at)->toBeNull();
});

test('a spam complaint suppresses immediately', function () {
    $user = ukDoctor();

    $this->call('POST', '/webhooks/ses-events', content: sesEvent([
        'notificationType' => 'Complaint',
        'complaint' => [
            'complainedRecipients' => [['emailAddress' => $user->email]],
        ],
    ]))->assertOk();

    expect($user->fresh()->email_suppression_reason)->toBe('complaint');
});

test('suppressed users are skipped by the weekly review', function () {
    Mail::fake();

    $user = ukDoctor();
    $user->forceFill([
        'email_suppressed_at' => now(),
        'email_suppression_reason' => 'bounce',
    ])->save();

    // Give them activity so they would otherwise receive the email.
    InboxItem::factory()->for($user)->ready()->create();

    $this->artisan('cpd:send-weekly-reviews')->assertSuccessful();

    Mail::assertNotQueued(WeeklyReview::class);
});

test('changing email address clears the suppression', function () {
    $user = ukDoctor();
    $user->forceFill([
        'email_suppressed_at' => now(),
        'email_suppression_reason' => 'bounce',
    ])->save();

    $user->update(['email' => 'fresh-address@example.com']);

    expect($user->fresh()->email_suppressed_at)->toBeNull()
        ->and($user->fresh()->email_suppression_reason)->toBeNull();
});
