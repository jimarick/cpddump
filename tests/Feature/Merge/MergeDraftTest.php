<?php

use App\Ai\MergeDraftAgent;
use App\Models\Activity;
use App\Models\AiGeneration;
use Laravel\Ai\Ai;

function fakeDraft(array $overrides = []): array
{
    return array_merge([
        'title' => 'MDT meetings — combined',
        'activity_type_slug' => 'course',
        'organisation' => 'The Trust',
        'details' => 'One paragraph covering everything that happened across both meetings.',
        'reflection' => [
            'why_selected' => 'Both halves, woven together.',
            'learning_need' => '',
            'practice_change' => '',
        ],
    ], $overrides);
}

test('the draft endpoint returns the AI-combined entry: title, type, details and reflections', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'reflection' => ['why_selected' => 'First half.'],
    ]);
    $b = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'reflection' => ['why_selected' => 'Second half.'],
    ]);

    Ai::fakeAgent(MergeDraftAgent::class, [fakeDraft()]);

    $this->actingAs($user)
        ->postJson('/merges/draft', ['activity_ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJson(['draft' => [
            'title' => 'MDT meetings — combined',
            'activity_type_slug' => 'course',
            'organisation' => 'The Trust',
            'details' => 'One paragraph covering everything that happened across both meetings.',
            'reflection' => ['why_selected' => 'Both halves, woven together.'],
        ]])
        ->assertJsonMissingPath('draft.reflection.learning_need');

    expect(AiGeneration::where('user_id', $user->id)->where('purpose', 'merge_reflection')->count())->toBe(1);
});

test('the AI receives every source in full, labelled by origin', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Radiopaedia conference',
        'organisation' => 'Radiopaedia',
        'details' => 'Five days of virtual radiology lectures.',
        'reflection' => [
            'why_selected' => 'Keeping my imaging knowledge current.',
            'practice_change' => 'I will apply the new grading system.',
        ],
    ]);
    $b = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Gynaecology MDT',
        'details' => 'Discussed complex endometriosis cases.',
        'reflection' => [
            'why_selected' => 'Complex endometriosis cases at the MDT.',
            'learning_need' => 'I wanted to understand the staging discussion.',
        ],
    ]);

    $captured = null;
    Ai::fakeAgent(MergeDraftAgent::class, function (string $prompt) use (&$captured) {
        $captured = $prompt;

        return fakeDraft();
    });

    $this->actingAs($user)
        ->postJson('/merges/draft', ['activity_ids' => [$a->id, $b->id]])
        ->assertOk();

    // Every source's title, summary and answers reach the model, attributed.
    expect($captured)
        ->toContain('Radiopaedia conference')
        ->toContain('Five days of virtual radiology lectures.')
        ->toContain('Keeping my imaging knowledge current.')
        ->toContain('I will apply the new grading system.')
        ->toContain('Gynaecology MDT')
        ->toContain('Discussed complex endometriosis cases.')
        ->toContain('Complex endometriosis cases at the MDT.')
        ->toContain('I wanted to understand the staging discussion.')
        ->toContain('written by the user');
});

test('the draft endpoint refuses politely once the daily budget is spent', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);
    $b = Activity::factory()->for($user)->create(['appraisal_period_id' => $user->currentAppraisalPeriod()->id]);

    AiGeneration::create([
        'user_id' => $user->id,
        'purpose' => 'inbox_analysis',
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-5',
        'input_tokens' => 0,
        'output_tokens' => config('cpd.ai.daily_token_budget'),
        'used_user_key' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/merges/draft', ['activity_ids' => [$a->id, $b->id]])
        ->assertStatus(429);
});
