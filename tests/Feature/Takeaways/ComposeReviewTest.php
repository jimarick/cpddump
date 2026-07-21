<?php

use App\Ai\ReviewComposerAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Ai;
use Laravel\Sanctum\Sanctum;

function fakeComposition(): array
{
    return [
        'details' => 'I attended the RCR liver MRI masterclass webinar. It covered LI-RADS, hepatobiliary contrast and treatment response.',
        'reflection' => [
            'why_selected' => 'Liver MRI is a growing part of my workload.',
            'learning_need' => 'The hepatobiliary phase changes my work-up of equivocal lesions.',
            'practice_change' => null,
        ],
        'nuggets' => ['Gadoxetate HBP: no uptake rules out FNH', 'mRECIST for TACE response'],
        'actions' => ['Ask the MRI lead about gadoxetate lists'],
        'category_slugs' => ['cpd'],
        'domain_codes' => ['D1'],
        'attribute_codes' => ['1.1'],
    ];
}

test('the compose endpoint turns notes into details, reflections, wrapped takeaways and categories', function () {
    $user = ukDoctor();

    Ai::fakeAgent(ReviewComposerAgent::class, [fakeComposition()]);

    $response = $this->actingAs($user)
        ->postJson('/ai/compose-review', [
            'notes' => 'really useful webinar. HBP changes my work-up. TODO ask about gadoxetate lists.',
            'title' => 'Liver MRI masterclass',
            'activity_type_slug' => 'course',
            'cpd_points' => 1.5,
        ])
        ->assertOk()
        ->assertJsonPath('details', fakeComposition()['details'])
        ->assertJsonPath('reflection.practice_change', null)
        ->assertJsonPath('nuggets.0.text', 'Gadoxetate HBP: no uptake rules out FNH')
        ->assertJsonPath('nuggets.0.done', false)
        ->assertJsonPath('actions.0.text', 'Ask the MRI lead about gadoxetate lists')
        ->assertJsonPath('category_slugs.0', 'cpd')
        ->assertJsonPath('domain_codes.0', 'D1');

    // Takeaways arrive id-wrapped, ready for the per-item endpoints.
    expect($response->json('nuggets.0.id'))->toBeString()->not->toBeEmpty();
});

test('the compose endpoint requires notes', function () {
    $this->actingAs(ukDoctor())
        ->postJson('/ai/compose-review', ['title' => 'No notes here'])
        ->assertStatus(422);
});

test('the compose endpoint works over the companion API too', function () {
    $user = ukDoctor();
    Sanctum::actingAs($user);

    Ai::fakeAgent(ReviewComposerAgent::class, [fakeComposition()]);

    $this->postJson('/api/v1/ai/compose-review', ['notes' => 'quick ramble about the webinar'])
        ->assertOk()
        ->assertJsonPath('actions.0.text', 'Ask the MRI lead about gadoxetate lists');
});

test('the composer agent prompt carries the two-sentence and emphasis rules', function () {
    $agent = ReviewComposerAgent::for(ukDoctor());
    $instructions = (string) $agent->instructions();

    expect($instructions)
        ->toContain('TWO SENTENCES')
        ->toContain('emphasised')
        ->toContain('nuggets');

    $schema = $agent->schema(app(JsonSchemaTypeFactory::class));

    expect($schema)->toHaveKeys(['details', 'reflection', 'nuggets', 'actions', 'category_slugs', 'domain_codes', 'attribute_codes']);
});
