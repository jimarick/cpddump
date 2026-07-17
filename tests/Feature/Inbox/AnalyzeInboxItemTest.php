<?php

use App\Ai\InboxAnalystAgent;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Models\AiGeneration;
use App\Models\InboxItem;
use App\Services\AiGateway;
use Database\Factories\InboxItemFactory;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Ai;

function fakeAnalysis(): array
{
    return (new InboxItemFactory)->exampleAnalysis();
}

test('analysis stores the structured extraction and marks the item ready', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->fromEmail()->create();

    Ai::fakeAgent(InboxAnalystAgent::class, [fakeAnalysis()]);

    (new AnalyzeInboxItem($item))->handle(app(AiGateway::class));

    $item->refresh();

    expect($item->status)->toBe(InboxItemStatus::Ready)
        ->and($item->ai_analysis['title'])->toBe('Advanced Life Support — recertification')
        ->and($item->ai_analysis['activity_type_slug'])->toBe('course')
        ->and($item->ai_warnings)->toHaveKeys(['pii_flags', 'missing_evidence', 'possible_duplicate_activity_ids'])
        ->and($item->analysed_at)->not->toBeNull();

    // Every call is recorded for cost tracking.
    expect(AiGeneration::where('user_id', $user->id)->where('purpose', 'inbox_analysis')->count())->toBe(1);
});

test('identical content reuses the previous analysis without calling the model', function () {
    $user = ukDoctor();

    $original = InboxItem::factory()->for($user)->ready()->create(['content_hash' => hash('sha256', 'same')]);
    $duplicate = InboxItem::factory()->for($user)->create(['content_hash' => hash('sha256', 'same')]);

    $gateway = Ai::fakeAgent(InboxAnalystAgent::class, []);
    $gateway->preventStrayPrompts();

    (new AnalyzeInboxItem($duplicate))->handle(app(AiGateway::class));

    $duplicate->refresh();

    expect($duplicate->status)->toBe(InboxItemStatus::Ready)
        ->and($duplicate->ai_analysis['title'])->toBe($original->ai_analysis['title'])
        ->and($duplicate->ai_warnings['possible_duplicate_inbox_item_ids'])->toBe([$original->id]);
});

test('resolved items are never re-analysed', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->ready()->create();
    $item->approve($item->ai_analysis);

    $gateway = Ai::fakeAgent(InboxAnalystAgent::class, []);
    $gateway->preventStrayPrompts();

    (new AnalyzeInboxItem($item))->handle(app(AiGateway::class));

    expect($item->fresh()->status)->toBe(InboxItemStatus::Approved);
});

test('a failed analysis surfaces a readable reason and a retryable status', function () {
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    (new AnalyzeInboxItem($item))->failed(new RuntimeException('provider exploded'));

    $item->refresh();

    expect($item->status)->toBe(InboxItemStatus::Failed)
        ->and($item->failure_reason)->toContain('retry');
});

test('the analyst agent builds a profession-aware prompt and schema', function () {
    $user = ukDoctor();

    $agent = InboxAnalystAgent::for($user);
    $instructions = (string) $agent->instructions();

    expect($instructions)
        ->toContain('UK Doctor')
        ->toContain('GMC Good Medical Practice')
        ->toContain('D2: Patients, partnership and communication')
        ->toContain('3.7: Keeping patients safe');

    $schema = $agent->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys([
        'title', 'activity_type_slug', 'starts_on', 'cpd_points', 'summary',
        'reflection_draft', 'category_slugs', 'domain_codes', 'attribute_codes',
        'confidence', 'pii_flags', 'missing_evidence',
    ]);
});
