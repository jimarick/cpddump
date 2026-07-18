<?php

use App\Jobs\ExtractAttachmentText;
use App\Jobs\ProcessSesInboundEmail;
use App\Mail\ForwardedInboundEmail;
use App\Models\InboxItem;
use App\Services\EvidenceIngestor;
use App\Services\SesObjectStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.ses_inbound.verify_signature' => false]);
});

function fakeSesStore(array $objects = []): SesObjectStore
{
    return new class($objects) extends SesObjectStore
    {
        /** @var array<int, string> */
        public array $deleted = [];

        /** @param array<string, string> $objects */
        public function __construct(public array $objects) {}

        public function get(string $bucket, string $key): ?string
        {
            return $this->objects["{$bucket}/{$key}"] ?? null;
        }

        public function delete(string $bucket, string $key): void
        {
            $this->deleted[] = "{$bucket}/{$key}";
            unset($this->objects["{$bucket}/{$key}"]);
        }
    };
}

function sesNotification(array $overrides = []): string
{
    $message = array_replace_recursive([
        'notificationType' => 'Received',
        'mail' => ['messageId' => 'mid-1'],
        'receipt' => [
            'action' => ['type' => 'S3', 'bucketName' => 'landing', 'objectKey' => 'inbound/mid-1'],
            'recipients' => ['u_testtoken@cpddump.com'],
            'spamVerdict' => ['status' => 'PASS'],
            'virusVerdict' => ['status' => 'PASS'],
        ],
    ], $overrides);

    return json_encode([
        'Type' => 'Notification',
        'Message' => json_encode($message),
    ]);
}

function rawEmail(string $to, bool $withPdf = true, bool $withExe = false): string
{
    $pdf = base64_encode('%PDF-1.4 fake certificate');
    $exe = base64_encode('MZ fake binary');

    $mime = "From: CPD Conference <noreply@conf.example>\r\n";
    $mime .= "To: {$to}\r\n";
    $mime .= "Subject: Your ALS certificate\r\n";
    $mime .= "MIME-Version: 1.0\r\n";
    $mime .= "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n";
    $mime .= "--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n";
    $mime .= "Congratulations on completing your recertification.\r\n";

    if ($withPdf) {
        $mime .= "--b1\r\nContent-Type: application/pdf; name=\"certificate.pdf\"\r\n";
        $mime .= "Content-Disposition: attachment; filename=\"certificate.pdf\"\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n";
    }

    if ($withExe) {
        $mime .= "--b1\r\nContent-Type: application/octet-stream; name=\"certificate.pdf.exe\"\r\n";
        $mime .= "Content-Disposition: attachment; filename=\"certificate.pdf.exe\"\r\n";
        $mime .= "Content-Transfer-Encoding: base64\r\n\r\n{$exe}\r\n";
    }

    return $mime."--b1--\r\n";
}

test('the SNS subscription handshake is confirmed automatically', function () {
    Http::fake(['sns.example/confirm*' => Http::response('ok')]);

    $this->call('POST', '/webhooks/ses-inbound', content: json_encode([
        'Type' => 'SubscriptionConfirmation',
        'SubscribeURL' => 'https://sns.example/confirm?token=abc',
    ]))->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sns.example/confirm'));
});

test('a received notification queues processing with the S3 location', function () {
    Queue::fake();

    $this->call('POST', '/webhooks/ses-inbound', content: sesNotification())->assertOk();

    Queue::assertPushed(ProcessSesInboundEmail::class, fn (ProcessSesInboundEmail $job) => $job->bucket === 'landing'
        && $job->key === 'inbound/mid-1'
        && $job->messageId === 'mid-1'
        && $job->recipients === ['u_testtoken@cpddump.com']);
});

test('spam and virus failures are deleted, never processed', function () {
    Queue::fake();
    $store = fakeSesStore();
    app()->instance(SesObjectStore::class, $store);

    $this->call('POST', '/webhooks/ses-inbound', content: sesNotification([
        'receipt' => ['spamVerdict' => ['status' => 'FAIL']],
    ]))->assertOk();

    Queue::assertNothingPushed();
    expect($store->deleted)->toBe(['landing/inbound/mid-1']);
});

test('a forwarded email becomes an inbox item and the raw copy is deleted', function () {
    Queue::fake([ExtractAttachmentText::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $user->forceFill(['inbound_email_token' => 'u_testtoken'])->save();

    $store = fakeSesStore(['landing/inbound/mid-1' => rawEmail('u_testtoken@cpddump.com')]);

    (new ProcessSesInboundEmail('landing', 'inbound/mid-1', ['u_testtoken@cpddump.com'], 'mid-1'))
        ->handle($store, app(EvidenceIngestor::class));

    $item = $user->inboxItems()->firstOrFail();

    expect($item->raw_payload['subject'])->toBe('Your ALS certificate')
        ->and($item->raw_payload['from'])->toContain('conf.example')
        ->and($item->raw_payload['body'])->toContain('Congratulations')
        ->and($item->external_id)->toBe('ses:mid-1')
        ->and($item->attachments()->count())->toBe(1)
        ->and($item->attachments()->first()->original_filename)->toBe('certificate.pdf');

    Storage::disk('local')->assertExists($item->attachments()->first()->path);

    // The raw email does not outlive ingestion.
    expect($store->deleted)->toBe(['landing/inbound/mid-1']);

    Queue::assertPushed(ExtractAttachmentText::class);
});

test('disallowed attachment types are never stored from SES email', function () {
    Queue::fake();
    Storage::fake('local');

    $user = ukDoctor();
    $user->forceFill(['inbound_email_token' => 'u_testtoken'])->save();

    $store = fakeSesStore([
        'landing/inbound/mid-2' => rawEmail('u_testtoken@cpddump.com', withPdf: true, withExe: true),
    ]);

    (new ProcessSesInboundEmail('landing', 'inbound/mid-2', ['u_testtoken@cpddump.com'], 'mid-2'))
        ->handle($store, app(EvidenceIngestor::class));

    $item = $user->inboxItems()->firstOrFail();

    expect($item->attachments()->count())->toBe(1)
        ->and($item->attachments()->first()->original_filename)->toBe('certificate.pdf');
});

test('unknown recipients are dropped silently and the object still deleted', function () {
    Queue::fake();

    $store = fakeSesStore(['landing/inbound/mid-3' => rawEmail('u_nobody@cpddump.com')]);

    (new ProcessSesInboundEmail('landing', 'inbound/mid-3', ['u_nobody@cpddump.com'], 'mid-3'))
        ->handle($store, app(EvidenceIngestor::class));

    expect(InboxItem::count())->toBe(0)
        ->and($store->deleted)->toBe(['landing/inbound/mid-3']);
});

test('mail to a human alias is relayed to the contact address, not ingested', function () {
    Queue::fake();
    Mail::fake();

    $store = fakeSesStore(['landing/inbound/mid-4' => rawEmail('hello@cpddump.com')]);

    (new ProcessSesInboundEmail('landing', 'inbound/mid-4', ['hello@cpddump.com'], 'mid-4'))
        ->handle($store, app(EvidenceIngestor::class));

    Mail::assertQueued(ForwardedInboundEmail::class, function (ForwardedInboundEmail $mail) {
        return $mail->originalSubject === 'Your ALS certificate'
            && count($mail->forwardedAttachments) === 1
            && $mail->hasTo(config('cpd.contact_email'));
    });

    expect(InboxItem::count())->toBe(0)
        ->and($store->deleted)->toBe(['landing/inbound/mid-4']);
});
