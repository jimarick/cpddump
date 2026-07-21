<?php

test('the notifications page shows every toggle', function () {
    // Refresh: boolean defaults come from the database, not the factory.
    $user = ukDoctor()->refresh();

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertInertia(fn ($page) => $page
            ->component('settings/notifications')
            ->where('weeklyEmailEnabled', true)
            ->where('weeklyLearningRecapEnabled', true)
            ->where('monthlyDigestEmailEnabled', true)
            ->where('pushMorningGemEnabled', true)
            ->where('pushWeeklyNudgeEnabled', false)
            ->where('hasPushTokens', false));
});

test('each preference can be toggled independently', function () {
    $user = ukDoctor();

    $this->actingAs($user)
        ->patch('/settings/notifications', ['monthly_digest_email_enabled' => false])
        ->assertRedirect();

    $user->refresh();

    expect($user->monthly_digest_email_enabled)->toBeFalse()
        ->and($user->weekly_email_enabled)->toBeTrue()
        ->and($user->push_morning_gem_enabled)->toBeTrue();

    $this->actingAs($user)
        ->patch('/settings/notifications', ['push_morning_gem_enabled' => false, 'weekly_learning_recap_enabled' => false])
        ->assertRedirect();

    $user->refresh();

    expect($user->push_morning_gem_enabled)->toBeFalse()
        ->and($user->weekly_learning_recap_enabled)->toBeFalse();
});

test('guests are redirected to login', function () {
    $this->get('/settings/notifications')->assertRedirect('/login');
});
