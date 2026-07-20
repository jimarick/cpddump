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
