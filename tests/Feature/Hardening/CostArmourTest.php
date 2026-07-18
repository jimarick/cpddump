<?php

use App\Ai\InboxAnalystAgent;
use App\Enums\InboxItemStatus;
use App\Jobs\AnalyzeInboxItem;
use App\Jobs\GenerateReport;
use App\Models\AiGeneration;
use App\Models\InboxItem;
use App\Models\User;
use App\Services\AiGateway;
use App\Services\PortfolioDigest;
use Database\Factories\InboxItemFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;

function logGeneration(User $user, int $input = 0, int $output = 0, bool $ownKey = false): void
{
    AiGeneration::create([
        'user_id' => $user->id,
        'purpose' => 'inbox_analysis',
        'provider' => 'openai',
        'model' => 'gpt-5.4',
        'input_tokens' => $input,
        'output_tokens' => $output,
        'used_user_key' => $ownKey,
    ]);
}

/** A minimal image-only PDF with the given number of page objects. */
function scannedPdf(int $pages): string
{
    $body = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
    $kids = collect(range(3, 2 + $pages))->map(fn ($n) => "{$n} 0 R")->implode(' ');
    $body .= "2 0 obj << /Type /Pages /Kids [{$kids}] /Count {$pages} >> endobj\n";

    foreach (range(3, 2 + $pages) as $n) {
        $body .= "{$n} 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >> endobj\n";
    }

    return $body."trailer << /Root 1 0 R >>\n%%EOF";
}

test('the input-token budget trips the daily allowance on its own', function () {
    $user = ukDoctor();

    // Barely any output, but a giant input spend (scanned-PDF pattern).
    logGeneration($user, input: (int) config('cpd.ai.daily_input_token_budget'), output: 100);

    expect(app(AiGateway::class)->overDailyBudget($user))->toBeTrue();
});

test('own-key usage never counts toward budgets', function () {
    $user = ukDoctor();

    logGeneration($user, input: 99_999_999, output: 99_999_999, ownKey: true);

    expect(app(AiGateway::class)->overDailyBudget($user))->toBeFalse();
});

test('the platform-wide ceiling holds everyone on the platform key', function () {
    $spender = ukDoctor();
    $innocent = ukDoctor();

    logGeneration($spender, input: (int) config('cpd.ai.platform_daily_token_budget'));

    $gateway = app(AiGateway::class);

    expect($gateway->platformOverDailyBudget())->toBeTrue()
        ->and($gateway->overDailyBudget($innocent))->toBeTrue();

    // A user on their own key sails through.
    $byo = ukDoctor();
    $byo->forceFill(['ai_provider' => 'openai', 'ai_api_key' => 'sk-own'])->save();

    expect($gateway->overDailyBudget($byo))->toBeFalse();
});

test('report generation is held with a readable reason when over budget', function () {
    $user = ukDoctor();
    logGeneration($user, output: (int) config('cpd.ai.daily_token_budget'));

    $report = $user->generatedReports()->create([
        'appraisal_period_id' => $user->currentAppraisalPeriod()->id,
        'kind' => 'report',
        'params' => [],
        'status' => 'pending',
    ]);

    (new GenerateReport($report))->handle(app(AiGateway::class), app(PortfolioDigest::class));

    $report->refresh();

    expect($report->status)->toBe('failed')
        ->and($report->params['failure_reason'])->toContain('Daily AI allowance');
});

test('report creation is rate limited', function () {
    $user = ukDoctor();

    foreach (range(1, 6) as $i) {
        $this->actingAs($user)->post('/reports', ['kind' => 'report']);
    }

    $this->actingAs($user)
        ->post('/reports', ['kind' => 'report'])
        ->assertStatus(429);
});

test('an oversized scanned PDF is gated instead of sent to the model', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    $pages = (int) config('cpd.ai.max_scanned_pdf_pages') + 1;
    Storage::disk('local')->put("evidence/{$user->id}/scan.pdf", scannedPdf($pages));

    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/scan.pdf",
        'original_filename' => 'scan.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1000,
    ]);

    (new AnalyzeInboxItem($item))->handle(app(AiGateway::class));

    $item->refresh();

    expect($item->status)->toBe(InboxItemStatus::Failed)
        ->and($item->failure_reason)->toContain('scanned document');
});

test('a scanned PDF within the page gate is not blocked', function () {
    Storage::fake('local');

    $user = ukDoctor();
    $item = InboxItem::factory()->for($user)->create();

    Storage::disk('local')->put("evidence/{$user->id}/small.pdf", scannedPdf(3));

    $item->attachments()->create([
        'user_id' => $user->id,
        'disk' => 'local',
        'path' => "evidence/{$user->id}/small.pdf",
        'original_filename' => 'small.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1000,
    ]);

    Ai::fakeAgent(InboxAnalystAgent::class, [
        (new InboxItemFactory)->exampleAnalysis(),
    ]);

    (new AnalyzeInboxItem($item))->handle(app(AiGateway::class));

    expect($item->refresh()->status)->toBe(InboxItemStatus::Ready);
});
