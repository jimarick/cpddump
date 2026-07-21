<?php

use App\Models\Activity;
use App\Models\PushToken;
use App\Models\User;
use App\Notifications\MorningGem;
use Illuminate\Support\Facades\Notification;

function gemUser(): User
{
    $user = ukDoctor();
    PushToken::factory()->for($user)->create();

    return $user;
}

function gemActivity(User $user, array $nuggets): Activity
{
    return Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => $nuggets,
        'actions' => [],
    ]);
}

test('one open nugget from the period goes out as the morning gem', function () {
    Notification::fake();

    $user = gemUser();
    gemActivity($user, [
        ['id' => 'n1', 'text' => 'Gadoxetate HBP: no uptake, almost never FNH', 'done' => false],
    ]);

    $this->artisan('cpd:send-morning-gems')->assertSuccessful();

    Notification::assertSentTo($user, MorningGem::class, function (MorningGem $gem) {
        return $gem->nuggetId === 'n1' && str_contains($gem->text, 'Gadoxetate');
    });
});

test('no nuggets, done nuggets, no tokens or opted out all earn silence', function () {
    Notification::fake();

    $noNuggets = gemUser();

    $allDone = gemUser();
    gemActivity($allDone, [['id' => 'n1', 'text' => 'Done already', 'done' => true]]);

    $noTokens = ukDoctor();
    gemActivity($noTokens, [['id' => 'n2', 'text' => 'Unreachable', 'done' => false]]);

    $optedOut = gemUser();
    $optedOut->update(['push_morning_gem_enabled' => false]);
    gemActivity($optedOut, [['id' => 'n3', 'text' => 'Muted', 'done' => false]]);

    $this->artisan('cpd:send-morning-gems')->assertSuccessful();

    Notification::assertNothingSent();
});

test('re-running the command the same day picks the same gem', function () {
    Notification::fake();

    $user = gemUser();
    gemActivity($user, [
        ['id' => 'n1', 'text' => 'First', 'done' => false],
        ['id' => 'n2', 'text' => 'Second', 'done' => false],
        ['id' => 'n3', 'text' => 'Third', 'done' => false],
    ]);

    $this->artisan('cpd:send-morning-gems');
    $this->artisan('cpd:send-morning-gems');

    $ids = [];
    Notification::assertSentTo($user, MorningGem::class, function (MorningGem $gem) use (&$ids) {
        $ids[] = $gem->nuggetId;

        return true;
    });

    expect(array_unique($ids))->toHaveCount(1);
});

test('the gem payload deep-links the activity and carries the mute category', function () {
    $user = gemUser();
    $activity = gemActivity($user, [['id' => 'n1', 'text' => 'A nugget', 'done' => false]]);

    $payload = (new MorningGem($activity->id, 'n1', 'A nugget', $activity->title))->toApns($user);

    expect($payload['activity_id'])->toBe($activity->id)
        ->and($payload['nugget_id'])->toBe('n1')
        ->and($payload['aps']['category'])->toBe('MORNING_GEM')
        ->and($payload['aps']['alert']['title'])->toBe('Morning gem');
});
