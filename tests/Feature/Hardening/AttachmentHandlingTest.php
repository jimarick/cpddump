<?php

use App\Ai\InboxAnalystAgent;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\ProcessInboundEmail;
use App\Models\InboxItem;
use App\Services\AttachmentStore;
use App\Services\EvidenceIngestor;
use App\Services\PdfRasterizer;
use App\Services\ResendInbound;
use Database\Factories\InboxItemFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

function attachTo(InboxItem $item, string $path, string $filename, string $mime): void
{
    $item->attachments()->create([
        'user_id' => $item->user_id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => $filename,
        'mime_type' => $mime,
        'size' => Storage::disk('local')->size($path) ?: 1,
    ]);
}

function officeFixture(string $extension): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cpd-fixture-').'.'.$extension;

    if ($extension === 'docx') {
        $word = new PhpWord;
        $word->addSection()->addText('Teaching plan: FRCR physics revision session on MRI safety.');
        WordIOFactory::createWriter($word, 'Word2007')->save($tmp);
    } else {
        $deck = new PhpPresentation;
        $shape = $deck->getActiveSlide()->createRichTextShape();
        $shape->createTextRun('Audit of chest X-ray reporting turnaround times.');
        PresentationIOFactory::createWriter($deck, 'PowerPoint2007')->save($tmp);
    }

    $contents = (string) file_get_contents($tmp);
    @unlink($tmp);

    return $contents;
}

test('docx attachments get their text extracted for analysis', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/plan.docx", officeFixture('docx'));
    attachTo($item, "evidence/{$user->id}/plan.docx", 'plan.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    Ai::fakeAgent(InboxAnalystAgent::class, [(new InboxItemFactory)->exampleAnalysis()]);

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    expect($item->attachments()->first()->extracted_text)
        ->toContain('FRCR physics revision session');
});

test('pptx attachments get their slide text extracted', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/slides.pptx", officeFixture('pptx'));
    attachTo($item, "evidence/{$user->id}/slides.pptx", 'slides.pptx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation');

    Ai::fakeAgent(InboxAnalystAgent::class, [(new InboxItemFactory)->exampleAnalysis()]);

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    expect($item->attachments()->first()->extracted_text)
        ->toContain('chest X-ray reporting turnaround');
});

test('txt attachments are read directly', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/notes.txt", 'Reflection: MDT taught me about nodule follow-up.');
    attachTo($item, "evidence/{$user->id}/notes.txt", 'notes.txt', 'text/plain');

    Ai::fakeAgent(InboxAnalystAgent::class, [(new InboxItemFactory)->exampleAnalysis()]);

    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));

    expect($item->attachments()->first()->extracted_text)
        ->toContain('nodule follow-up');
});

test('unreadable attachments are flagged to the analyst, not bluffed', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/old.doc", 'legacy binary blob');
    attachTo($item, "evidence/{$user->id}/old.doc", 'teaching-slides.doc', 'application/msword');

    $job = new AnalyzeInboxItem($item);
    $prompt = (fn () => $this->buildEvidencePrompt($this->item->fresh(['attachments'])))->call($job);

    expect($prompt)->toContain('could NOT be read')
        ->and($prompt)->toContain('teaching-slides.doc');
});

test('email attachments outside the allowlist are never stored', function () {
    Queue::fake([AnalyzeInboxItem::class]);
    Storage::fake('local');

    $user = ukDoctor();
    $user->ensureInboundEmailToken();

    Http::fake([
        'api.resend.com/emails/receiving/em_9' => Http::response([
            'id' => 'em_9',
            'from' => 'attacker@example.com',
            'subject' => 'Totally a certificate',
            'text' => 'Open the attachment.',
            'html' => null,
            'created_at' => now()->toIso8601String(),
        ]),
        'api.resend.com/emails/receiving/em_9/attachments' => Http::response([
            'data' => [
                [
                    'id' => 'att_bad',
                    'filename' => 'certificate.pdf.exe',
                    'content_type' => 'application/octet-stream',
                    'size' => 1000,
                    'download_url' => 'https://files.resend.example/att_bad',
                ],
                [
                    'id' => 'att_ok',
                    'filename' => 'certificate.pdf',
                    'content_type' => 'application/pdf',
                    'size' => 1000,
                    'download_url' => 'https://files.resend.example/att_ok',
                ],
            ],
        ]),
        'files.resend.example/*' => Http::response('%PDF-1.4 fake'),
    ]);

    (new ProcessInboundEmail($user->id, 'em_9', []))
        ->handle(app(ResendInbound::class), app(EvidenceIngestor::class));

    $item = $user->inboxItems()->firstOrFail();

    expect($item->attachments()->count())->toBe(1)
        ->and($item->attachments()->first()->original_filename)->toBe('certificate.pdf');
});
