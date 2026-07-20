<?php

use App\Ai\InboxAnalystAgent;
use App\Jobs\AnalyzeInboxItem;
use App\Models\InboxItem;
use App\Services\AiGateway;
use Database\Factories\InboxItemFactory;
use Laravel\Ai\Ai;

test('related ids from the analyst land in warnings and reciprocate onto the other item', function () {
    $user = ukDoctor();

    $earlier = InboxItem::factory()->for($user)->ready()->create();
    $incoming = InboxItem::factory()->for($user)->fromEmail()->create();

    Ai::fakeAgent(InboxAnalystAgent::class, [array_merge(
        (new InboxItemFactory)->exampleAnalysis(),
        [
            'possible_related_inbox_item_ids' => [$earlier->id],
            'possible_related_activity_ids' => [],
            'related_reason' => 'Same ALS recertification course.',
        ],
    )]);

    (new AnalyzeInboxItem($incoming))->handle(app(AiGateway::class));

    $incoming->refresh();
    expect($incoming->ai_warnings['possible_related_inbox_item_ids'])->toBe([$earlier->id])
        ->and($incoming->ai_warnings['related_reason'])->toBe('Same ALS recertification course.');

    // The earlier item now points back — both cards badge, one AI call.
    expect($earlier->fresh()->ai_warnings['possible_related_inbox_item_ids'])->toBe([$incoming->id]);
});

test('the analyst context lists waiting inbox items compactly, excluding the item under analysis', function () {
    $user = ukDoctor();

    $waiting = InboxItem::factory()->for($user)->ready()->count(3)->create();
    $subject = InboxItem::factory()->for($user)->create();

    $agent = InboxAnalystAgent::for($user, $subject->id);

    expect(collect($agent->openInboxItems)->pluck('id')->sort()->values()->all())
        ->toBe($waiting->pluck('id')->sort()->values()->all())
        ->and(collect($agent->openInboxItems)->pluck('id'))->not->toContain($subject->id)
        ->and($agent->openInboxItems[0])->toHaveKeys(['id', 'title', 'date', 'source', 'type']);

    expect((string) $agent->instructions())
        ->toContain('Other evidence currently waiting')
        ->toContain('possible_related_inbox_item_ids');
});

test('the waiting-items context caps at 25', function () {
    $user = ukDoctor();
    InboxItem::factory()->for($user)->ready()->count(30)->create();

    expect(InboxAnalystAgent::for($user)->openInboxItems)->toHaveCount(25);
});
