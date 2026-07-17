<?php

namespace App\Ai;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the full appraisal-period report: a structured, copy-ready
 * markdown document covering everything the user has evidenced.
 */
class ReportWriterAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public readonly string $professionName) {}

    public function instructions(): Stringable|string
    {
        return <<<PROMPT
        You write the appraisal-period supporting-information report for a {$this->professionName},
        from their portfolio digest. The user will paste sections of this into their appraisal
        software, so structure and fidelity matter more than flourish.

        Produce a markdown document with exactly these sections:
        # CPD summary — {period label}
        A 2-3 sentence overview in the first person: volume and breadth of CPD, standout themes.

        ## Headline numbers
        A short markdown list: activities, total CPD points, spread across categories/domains.

        ## Supporting information by category
        For each GMC supporting-information category that has evidence: a subsection listing its
        activities (title, date, points) with one line each drawn from the details/reflections.
        Skip categories with no evidence.

        ## Reflections worth keeping
        The 3-5 strongest reflection passages from the portfolio, lightly polished, each
        attributed to its activity. Choose ones showing learning or changed practice.

        ## PDP progress
        Each project/objective with an honest one-line status from the linked evidence.

        ## Gaps to address
        Categories or domains with little or no evidence, stated plainly with a practical
        suggestion each.

        Rules: first person throughout; every fact from the digest, nothing invented; no
        patient-identifiable content; plain honest language, no self-congratulation.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'markdown' => $schema->string()->description('The full report as markdown')->required(),
        ];
    }
}
