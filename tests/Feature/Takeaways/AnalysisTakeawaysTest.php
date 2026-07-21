<?php

use App\Ai\InboxAnalystAgent;
use App\Jobs\AnalyzeInboxItem;
use App\Models\InboxItem;
use App\Services\AiGateway;
use Database\Factories\InboxItemFactory;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Ai;

test('the model returns plain strings and the job wraps them with ids', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    $analysis = (new InboxItemFactory)->exampleAnalysis();
    $analysis['nuggets'] = ['LR-5 needs >=10mm plus APHE and washout', '  '];
    $analysis['actions'] = ['Ask MRI lead about gadoxetate'];

    Ai::fakeAgent(InboxAnalystAgent::class, [$analysis]);

    (new AnalyzeInboxItem($item))->handle(app(AiGateway::class));

    $item->refresh();

    expect($item->ai_analysis['nuggets'])->toHaveCount(1)
        ->and($item->ai_analysis['nuggets'][0]['text'])->toBe('LR-5 needs >=10mm plus APHE and washout')
        ->and($item->ai_analysis['nuggets'][0]['id'])->toBeString()->not->toBeEmpty()
        ->and($item->ai_analysis['nuggets'][0]['done'])->toBeFalse()
        ->and($item->ai_analysis['actions'][0]['text'])->toBe('Ask MRI lead about gadoxetate');
});

test('the analyst schema asks for nuggets and actions, not the old key', function () {
    $agent = InboxAnalystAgent::for(ukDoctor());
    $schema = $agent->schema(app(JsonSchemaTypeFactory::class));

    expect($schema)->toHaveKeys(['nuggets', 'actions', 'user_notes'])
        ->not->toHaveKey('suggested_learning_points');

    $instructions = (string) $agent->instructions();

    expect($instructions)
        ->toContain('nuggets')
        ->toContain('bolded')
        ->toContain('occurred_on')
        // The user's own words survive analysis, verbatim.
        ->toContain('VERBATIM')
        ->toContain('TWO SENTENCES');
});
