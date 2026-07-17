<?php

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
