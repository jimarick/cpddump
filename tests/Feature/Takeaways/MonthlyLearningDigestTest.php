<?php

use App\Mail\MonthlyLearningDigest;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function monthlyActivity(User $user, array $overrides = []): Activity
{
    return Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'nuggets' => [['id' => 'n1', 'text' => 'Last month nugget', 'done' => false]],
        'actions' => [['id' => 'a1', 'text' => 'Last month action', 'done' => false]],
        ...$overrides,
    ]);
}

test('the monthly digest covers exactly the previous calendar month', function () {
    Mail::fake();
    $this->travelTo(now()->startOfMonth()->addHours(8));

    $user = ukDoctor();
    $inWindow = monthlyActivity($user);
    $inWindow->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)])->save();

    $tooOld = monthlyActivity($user);
    $tooOld->forceFill(['created_at' => now()->subMonths(2)])->save();

    $this->artisan('cpd:send-monthly-learning-digests')->assertSuccessful();

    Mail::assertQueued(MonthlyLearningDigest::class, function (MonthlyLearningDigest $mail) use ($user) {
        return $mail->user->is($user)
            && count($mail->groups) === 1
            && $mail->groups[0]['nuggets'][0]['text'] === 'Last month nugget';
    });
});

test('done items, excluded activities, opted-out and empty months stay silent', function () {
    Mail::fake();
    $this->travelTo(now()->startOfMonth()->addHours(8));

    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

    // Everything ticked done: nothing to say.
    $allDone = ukDoctor();
    monthlyActivity($allDone, [
        'nuggets' => [['id' => 'n1', 'text' => 'Done', 'done' => true]],
        'actions' => [],
    ])->forceFill(['created_at' => $lastMonth])->save();

    // "Hidden from notifications" at review: everything arrived done.
    $excluded = ukDoctor();
    monthlyActivity($excluded, [
        'nuggets' => [['id' => 'n9', 'text' => 'Filed away', 'done' => true]],
        'actions' => [['id' => 'a9', 'text' => 'Filed away too', 'done' => true]],
    ])->forceFill(['created_at' => $lastMonth])->save();

    // Opted out in settings.
    $optedOut = ukDoctor();
    $optedOut->update(['monthly_digest_email_enabled' => false]);
    monthlyActivity($optedOut)->forceFill(['created_at' => $lastMonth])->save();

    // Nothing recorded at all.
    ukDoctor();

    $this->artisan('cpd:send-monthly-learning-digests')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('the monthly digest renders with brand, mark-done link and unsubscribe', function () {
    $user = ukDoctor();

    $html = (new MonthlyLearningDigest($user, 'July', [
        [
            'title' => 'Liver MRI masterclass',
            'nuggets' => [['id' => 'n1', 'text' => 'Gadoxetate HBP nugget', 'done' => false]],
            'actions' => [['id' => 'a1', 'text' => 'Ask the MRI lead', 'done' => false]],
        ],
    ]))->render();

    expect($html)
        ->toContain('Your July in learning')
        ->toContain('Gadoxetate HBP nugget')
        ->toContain('Ask the MRI lead')
        ->toContain('email/takeaways/done')
        ->toContain('email/unsubscribe')
        ->toContain('cpd dump')
        ->toContain('Bricolage Grotesque');
});
