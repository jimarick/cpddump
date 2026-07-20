<?php

use App\Ai\ReflectionDraftAgent;
use App\Ai\TextAssistAgent;
use Laravel\Ai\Ai;

test('the sparkle endpoint returns AI-polished text', function () {
    $user = ukDoctor();

    Ai::fakeAgent(TextAssistAgent::class, [
        ['text' => 'I attended the lung MDT weekly and it sharpened my nodule follow-up decisions.'],
    ]);

    $this->actingAs($user)
        ->postJson('/ai/text-assist', [
            'field' => 'Reflection — what was learned',
            'text' => 'went to mdt, learned nodule stuff',
            'context' => 'Activity: Lung MDT',
        ])
        ->assertOk()
        ->assertJsonPath('text', 'I attended the lung MDT weekly and it sharpened my nodule follow-up decisions.');
});

test('the sparkle endpoint requires a field label', function () {
    $this->actingAs(ukDoctor())
        ->postJson('/ai/text-assist', ['text' => 'hello'])
        ->assertStatus(422);
});

test('the reflection-draft endpoint shapes a ramble into per-prompt answers', function () {
    $user = ukDoctor();

    Ai::fakeAgent(ReflectionDraftAgent::class, [
        ['reflection' => [
            'why_selected' => 'I picked this because a tricky airway case exposed a gap in my knowledge.',
            'learning_need' => 'The radiology correlation session sharpened how I plan airway management.',
            'practice_change' => null,
        ]],
    ]);

    $this->actingAs($user)
        ->postJson('/ai/reflection-draft', [
            'text' => 'so the reason i picked this was the tricky airway case, the radiology bit was the useful part',
            'context' => 'Activity: Regional airway MDT',
        ])
        ->assertOk()
        ->assertJsonPath('reflection.why_selected', 'I picked this because a tricky airway case exposed a gap in my knowledge.')
        ->assertJsonPath('reflection.practice_change', null);
});

test('the reflection-draft endpoint answers every prompt key even when the AI omits some', function () {
    $user = ukDoctor();

    Ai::fakeAgent(ReflectionDraftAgent::class, [
        ['reflection' => ['learning_need' => 'I learned about lactate clearance trends.']],
    ]);

    $this->actingAs($user)
        ->postJson('/ai/reflection-draft', ['text' => 'the lactate bit was new to me'])
        ->assertOk()
        ->assertJsonPath('reflection.learning_need', 'I learned about lactate clearance trends.')
        ->assertJsonPath('reflection.why_selected', null)
        ->assertJsonPath('reflection.practice_change', null);
});

test('the reflection-draft endpoint requires text', function () {
    $this->actingAs(ukDoctor())
        ->postJson('/ai/reflection-draft', [])
        ->assertStatus(422);
});
