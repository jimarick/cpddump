<?php

use App\Ai\ReflectionMergerAgent;
use App\Models\Activity;
use App\Models\AiGeneration;
use Laravel\Ai\Ai;

test('the reflection endpoint returns one combined answer per prompt key', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'reflection' => ['why_selected' => 'First half.'],
    ]);
    $b = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'reflection' => ['why_selected' => 'Second half.'],
    ]);

    Ai::fakeAgent(ReflectionMergerAgent::class, [[
        'reflection' => [
            'why_selected' => 'Both halves, woven together.',
            'learning_need' => '',
            'practice_change' => '',
        ],
    ]]);

    $this->actingAs($user)
        ->postJson('/merges/reflection', ['activity_ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJson(['reflection' => ['why_selected' => 'Both halves, woven together.']])
        ->assertJsonMissingPath('reflection.learning_need');

    expect(AiGeneration::where('user_id', $user->id)->where('purpose', 'merge_reflection')->count())->toBe(1);
});

test('the AI receives every source\'s reflections, labelled by origin', function () {
    $user = ukDoctor();
    $a = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Radiopaedia conference',
        'reflection' => [
            'why_selected' => 'Keeping my imaging knowledge current.',
            'practice_change' => 'I will apply the new grading system.',
        ],
    ]);
    $b = Activity::factory()->for($user)->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'title' => 'Gynaecology MDT',
        'reflection' => [
            'why_selected' => 'Complex endometriosis cases at the MDT.',
            'learning_need' => 'I wanted to understand the staging discussion.',
        ],
    ]);

    $captured = null;
    Ai::fakeAgent(ReflectionMergerAgent::class, function (string $prompt) use (&$captured) {
        $captured = $prompt;

        return ['reflection' => ['why_selected' => 'Both, woven.', 'learning_need' => '', 'practice_change' => '']];
    });

    $this->actingAs($user)
        ->postJson('/merges/reflection', ['activity_ids' => [$a->id, $b->id]])
        ->assertOk();

    // Every source's answers reach the model, attributed to their entry.
    expect($captured)
        ->toContain('Radiopaedia conference')
        ->toContain('Keeping my imaging knowledge current.')
        ->toContain('I will apply the new grading system.')
        ->toContain('Gynaecology MDT')
        ->toContain('Complex endometriosis cases at the MDT.')
        ->toContain('I wanted to understand the staging discussion.')
        ->toContain('written by the user');
});

test('the reflection endpoint refuses politely once the daily budget is spent', function () {
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
        ->postJson('/merges/reflection', ['activity_ids' => [$a->id, $b->id]])
        ->assertStatus(429);
});
