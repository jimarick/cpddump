<?php

use App\Mail\WeeklyReview;
use App\Models\Recurrence;
use Illuminate\Support\Facades\URL;

test('the signed weekly unsubscribe link works without login', function () {
    $user = ukDoctor();

    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'type' => 'weekly']);

    $this->get($url)->assertOk()->assertSee('no more');

    expect($user->fresh()->weekly_email_enabled)->toBeFalse();
});

test('one-click POST unsubscribe works too (RFC 8058)', function () {
    $user = ukDoctor();

    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'type' => 'weekly']);

    $this->post($url)->assertOk();

    expect($user->fresh()->weekly_email_enabled)->toBeFalse();
});

test('the reminders variant silences every recurrence', function () {
    $user = ukDoctor();
    Recurrence::factory()->for($user)->create(['reminder' => 'same_day']);
    Recurrence::factory()->for($user)->expectation()->create(['reminder' => 'weekly']);

    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'type' => 'reminders']);

    $this->get($url)->assertOk();

    expect($user->recurrences()->where('reminder', '!=', 'none')->count())->toBe(0)
        ->and($user->fresh()->weekly_email_enabled)->toBeTrue();
});

test('the monthly variant flips only the monthly digest', function () {
    $user = ukDoctor();

    $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id, 'type' => 'monthly']);

    $this->get($url)->assertOk()->assertSee('monthly digests');

    expect($user->fresh()->monthly_digest_email_enabled)->toBeFalse()
        ->and($user->fresh()->weekly_email_enabled)->toBeTrue();
});

test('an unsigned unsubscribe link is rejected', function () {
    $user = ukDoctor();

    $this->get("/email/unsubscribe/{$user->id}?type=weekly")->assertForbidden();

    expect($user->fresh()->weekly_email_enabled)->toBeTrue();
});

test('the weekly review carries one-click unsubscribe headers', function () {
    $user = ukDoctor();

    $mail = new WeeklyReview($user, [
        'captured_this_week' => 1,
        'points_this_week' => 2.0,
        'awaiting' => 0,
        'total_activities' => 1,
        'total_points' => 2.0,
        'thin_areas' => [],
        'regulars_waiting' => 0,
        'behind_expectations' => [],
        'dump_address' => 'u_x@cpddump.com',
        'inbox_url' => 'https://cpddump.com/inbox',
    ]);

    $headers = $mail->headers();

    expect($headers->text['List-Unsubscribe'])->toContain('/email/unsubscribe/'.$user->id)
        ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');

    // And the rendered body includes the footer link.
    expect($mail->render())->toContain('Unsubscribe from weekly emails');
});
