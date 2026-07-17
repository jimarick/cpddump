<?php

use App\Ai\QuestionAnswerAgent;
use App\Ai\ReportWriterAgent;
use App\Models\Activity;
use App\Models\GeneratedReport;
use App\Services\PortfolioDigest;
use Laravel\Ai\Ai;

test('asking a question generates a grounded answer', function () {
    $user = ukDoctor();

    Ai::fakeAgent(QuestionAnswerAgent::class, [
        ['answer' => 'This year I completed ALS recertification and grew my teaching commitment.'],
    ]);

    $this->actingAs($user)
        ->post('/reports', [
            'kind' => 'question',
            'question' => 'What have been your greatest achievements this year?',
            'notes' => 'proud of the teaching',
        ])
        ->assertRedirect();

    $report = $user->generatedReports()->firstOrFail();

    expect($report->status)->toBe('ready')
        ->and($report->content)->toContain('ALS recertification')
        ->and($report->params['notes'])->toBe('proud of the teaching');
});

test('the full report is generated as markdown', function () {
    $user = ukDoctor();

    Ai::fakeAgent(ReportWriterAgent::class, [
        ['markdown' => "# CPD summary\n\nA solid year of learning."],
    ]);

    $this->actingAs($user)
        ->post('/reports', ['kind' => 'report'])
        ->assertRedirect();

    $report = $user->generatedReports()->firstOrFail();

    expect($report->kind->value)->toBe('report')
        ->and($report->status)->toBe('ready')
        ->and($report->content)->toStartWith('# CPD summary');
});

test('the portfolio digest contains activities, reflections and projects', function () {
    $user = ukDoctor();
    $period = $user->currentAppraisalPeriod();

    Activity::factory()->for($user)->create([
        'appraisal_period_id' => $period->id,
        'title' => 'Journal club on incidental findings',
        'reflection' => ['learning_need' => 'Sharpened my approach to incidentalomas.'],
    ]);
    $user->projects()->create(['kind' => 'objective', 'title' => 'Improve teaching', 'status' => 'open']);

    $digest = app(PortfolioDigest::class)->build($user, $period);

    expect($digest)
        ->toContain('Journal club on incidental findings')
        ->toContain('Sharpened my approach to incidentalomas.')
        ->toContain('Improve teaching')
        ->toContain($period->label);
});

test('questions require text and reports are user-scoped', function () {
    $this->actingAs(ukDoctor())
        ->post('/reports', ['kind' => 'question', 'question' => ''])
        ->assertSessionHasErrors('question');

    $owner = ukDoctor();
    $report = GeneratedReport::factory()->for($owner)->create();

    $this->actingAs(ukDoctor())
        ->delete("/reports/{$report->id}")
        ->assertForbidden();
});
