<?php

namespace Database\Factories;

use App\Enums\ReportKind;
use App\Models\GeneratedReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedReport>
 */
class GeneratedReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => ReportKind::Question,
            'question' => 'What have been your greatest achievements this year?',
            'params' => [],
            'status' => 'pending',
        ];
    }
}
