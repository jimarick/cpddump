<?php

use App\Enums\EvidenceSource;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\ExtractAttachmentText;
use App\Jobs\TranscribeVoiceNote;
use App\Models\InboxItem;
use App\Services\AttachmentStore;
use App\Services\EvidenceIngestor;
use App\Services\PdfRasterizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

function itemWithFile(string $filename, string $mime, string $contents): InboxItem
{
    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/{$filename}", $contents);
    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/{$filename}",
        'original_filename' => $filename,
        'mime_type' => $mime,
        'size' => strlen($contents),
    ]);

    return $item;
}

function runExtraction(InboxItem $item): void
{
    (new ExtractAttachmentText($item))->handle(app(PdfRasterizer::class), app(AttachmentStore::class));
}

beforeEach(function () {
    Queue::fake([AnalyzeInboxItem::class, TranscribeVoiceNote::class]);
    Storage::fake('local');
});

test('csv uploads become capped text and the file is never kept', function () {
    $csv = "date,activity,points\n2026-01-05,Audit meeting,2\n2026-02-10,Journal club,1";
    $item = itemWithFile('audit-log.csv', 'text/csv', $csv);

    runExtraction($item);

    $attachment = $item->attachments()->sole();

    expect($attachment->extracted_text)->toContain('Audit meeting')
        ->and($attachment->isPurged())->toBeTrue();

    Storage::disk('local')->assertMissing($attachment->path);
});

test('xlsx spreadsheets extract sheet rows as text and drop the file', function () {
    $workbook = new Spreadsheet;
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Q1 audit');
    $sheet->fromArray([['Case', 'Outcome'], ['CXR turnaround', 'Improved']]);

    $tmp = tempnam(sys_get_temp_dir(), 'cpd-xlsx-');
    (new XlsxWriter($workbook))->save($tmp);
    $contents = (string) file_get_contents($tmp);
    @unlink($tmp);

    $item = itemWithFile('q1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $contents);

    runExtraction($item);

    $attachment = $item->attachments()->sole();

    expect($attachment->extracted_text)->toContain('CXR turnaround')
        ->and($attachment->extracted_text)->toContain('Q1 audit')
        ->and($attachment->isPurged())->toBeTrue();
});

test('rtf strips to readable prose', function () {
    $rtf = '{\rtf1\ansi{\fonttbl{\f0 Arial;}}\f0\fs24 Reflection on teaching session.}';
    $item = itemWithFile('notes.rtf', 'application/rtf', $rtf);

    runExtraction($item);

    expect($item->attachments()->sole()->extracted_text)
        ->toContain('Reflection on teaching session');
});

test('a dragged-in eml is parsed like inbound mail: body kept, attachments recursed, raw file gone', function () {
    $pdf = base64_encode('%PDF-1.4 attached certificate');
    $eml = "From: Course Admin <admin@course.example>\r\n"
        ."To: doctor@nhs.example\r\n"
        ."Subject: Certificate attached\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: multipart/mixed; boundary=\"b9\"\r\n\r\n"
        ."--b9\r\nContent-Type: text/plain\r\n\r\nWell done on completing the course.\r\n"
        ."--b9\r\nContent-Type: application/pdf; name=\"cert.pdf\"\r\n"
        ."Content-Disposition: attachment; filename=\"cert.pdf\"\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n{$pdf}\r\n--b9--\r\n";

    $item = itemWithFile('forwarded.eml', 'message/rfc822', $eml);

    runExtraction($item);

    $emlAttachment = $item->attachments()->where('original_filename', 'forwarded.eml')->sole();
    $derived = $item->attachments()->where('original_filename', 'like', 'cert.pdf%')->sole();

    expect($emlAttachment->extracted_text)->toContain('Well done on completing')
        ->and($emlAttachment->extracted_text)->toContain('Subject: Certificate attached')
        ->and($emlAttachment->isPurged())->toBeTrue()
        ->and($derived->mime_type)->toBe('application/pdf');

    Storage::disk('local')->assertMissing($emlAttachment->path);
    Storage::disk('local')->assertExists($derived->path);
});

test('renamed executables are rejected by content sniffing', function () {
    // Mach-O magic bytes dressed as a PDF.
    $fakePdf = "\xCF\xFA\xED\xFE".str_repeat("\x00", 100);

    $stored = app(AttachmentStore::class)->store(
        item: InboxItem::factory()->for(ukDoctor())->create(),
        contents: $fakePdf,
        originalFilename: 'certificate.pdf',
        extension: 'pdf',
        fallbackMime: 'application/pdf',
    );

    expect($stored)->toBeNull();
});

test('an uploaded mp3 routes through transcription', function () {
    $user = ukDoctor();

    $tmp = tempnam(sys_get_temp_dir(), 'cpd-mp3-');
    file_put_contents($tmp, "ID3\x03\x00\x00\x00\x00\x00\x00fake audio");
    $file = new UploadedFile($tmp, 'dictation.mp3', 'audio/mpeg', null, true);

    app(EvidenceIngestor::class)->ingest(
        user: $user,
        source: EvidenceSource::Upload,
        rawPayload: ['title' => 'Voice memo'],
        files: [$file],
    );

    Queue::assertPushed(TranscribeVoiceNote::class);
});

test('inbox props never include raw source text', function () {
    $user = ukDoctor();

    InboxItem::factory()->for($user)->create([
        'raw_payload' => [
            'subject' => 'MDT summary',
            'body' => 'Patient John Smith, NHS 943 476 5919',
        ],
    ]);

    $this->actingAs($user)
        ->get('/inbox')
        ->assertInertia(fn ($page) => $page
            ->where('items.0.raw_payload.subject', 'MDT summary')
            ->missing('items.0.raw_payload.body'));
});
