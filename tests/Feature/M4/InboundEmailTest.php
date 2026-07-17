<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ProcessInboundEmail;
use App\Models\IgnoreRule;
use App\Services\EvidenceIngestor;
use App\Services\ResendInbound;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function inboundWebhookPayload(string $to, array $overrides = []): array
{
    return array_merge([
        'type' => 'email.received',
        'created_at' => now()->toIso8601String(),
        'data' => [
            'email_id' => 'em_'.fake()->uuid(),
            'from' => 'course-provider@rcr.ac.uk',
            'to' => [$to],
            'received_for' => [],
            'message_id' => '<abc@mail.example>',
            'subject' => 'Your course certificate',
            'attachments' => [],
        ],
    ], $overrides);
}

test('a webhook for a known dump address queues processing', function () {
    Queue::fake();
    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    $this->postJson(route('webhooks.resend-inbound'), inboundWebhookPayload($user->inboundEmailAddress()))
        ->assertNoContent();

    Queue::assertPushed(ProcessInboundEmail::class, fn ($job) => $job->userId === $user->id);
});

test('unknown dump addresses are silently dropped', function () {
    Queue::fake();

    $this->postJson(route('webhooks.resend-inbound'), inboundWebhookPayload('u_nobody@in.cpddump.com'))
        ->assertNoContent();

    Queue::assertNothingPushed();
});

test('processing fetches the email and attachments and creates an analysed inbox item', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    Http::fake([
        'api.resend.com/emails/receiving/em_1' => Http::response([
            'id' => 'em_1',
            'from' => 'no-reply@resus.org.uk',
            'subject' => 'ALS recertification certificate',
            'text' => 'Congratulations, your certificate is attached.',
            'html' => null,
            'created_at' => now()->toIso8601String(),
        ]),
        'api.resend.com/emails/receiving/em_1/attachments' => Http::response([
            'data' => [[
                'id' => 'att_1',
                'filename' => 'certificate.pdf',
                'content_type' => 'application/pdf',
                'size' => 1000,
                'download_url' => 'https://files.resend.example/att_1',
            ]],
        ]),
        'files.resend.example/*' => Http::response('%PDF-1.4 fake'),
    ]);

    (new ProcessInboundEmail($user->id, 'em_1', ['subject' => 'ALS recertification certificate', 'from' => 'no-reply@resus.org.uk', 'message_id' => 'x']))
        ->handle(app(ResendInbound::class), app(EvidenceIngestor::class));

    $item = $user->inboxItems()->firstOrFail();

    expect($item->source)->toBe(EvidenceSource::Email)
        ->and($item->external_id)->toBe('em_1')
        ->and($item->raw_payload['subject'])->toBe('ALS recertification certificate')
        ->and($item->raw_payload['body'])->toContain('Congratulations')
        ->and($item->attachments()->count())->toBe(1);

    Storage::disk('local')->assertExists($item->attachments->first()->path);
});

test('ignore rules by sender domain drop inbound email before analysis', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    IgnoreRule::factory()->for($user)->create([
        'source' => EvidenceSource::Email,
        'field' => 'sender_domain',
        'operator' => 'contains',
        'value' => 'spamcorp.com',
    ]);

    Http::fake([
        'api.resend.com/emails/receiving/em_2' => Http::response([
            'id' => 'em_2', 'from' => 'noise@spamcorp.com', 'subject' => 'Buy CPD points!', 'text' => 'noise',
        ]),
    ]);

    (new ProcessInboundEmail($user->id, 'em_2', []))
        ->handle(app(ResendInbound::class), app(EvidenceIngestor::class));

    expect($user->inboxItems()->count())->toBe(0);
});

test('a bad svix signature is rejected in production-like config', function () {
    config(['services.resend.inbound_webhook_secret' => 'whsec_'.base64_encode('supersecret')]);

    $this->postJson(route('webhooks.resend-inbound'), inboundWebhookPayload('u_x@in.cpddump.com'), [
        'svix-id' => 'msg_1',
        'svix-timestamp' => (string) now()->timestamp,
        'svix-signature' => 'v1,definitely-wrong',
    ])->assertUnauthorized();
});

test('a valid svix signature is accepted', function () {
    Queue::fake();
    $secretRaw = 'supersecret';
    config(['services.resend.inbound_webhook_secret' => 'whsec_'.base64_encode($secretRaw)]);

    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    $payload = inboundWebhookPayload($user->inboundEmailAddress());
    $body = json_encode($payload);
    $timestamp = (string) now()->timestamp;
    $signature = base64_encode(hash_hmac('sha256', "msg_1.{$timestamp}.{$body}", $secretRaw, true));

    $this->call('POST', route('webhooks.resend-inbound'), [], [], [], [
        'HTTP_svix-id' => 'msg_1',
        'HTTP_svix-timestamp' => $timestamp,
        'HTTP_svix-signature' => "v1,{$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    Queue::assertPushed(ProcessInboundEmail::class);
});
