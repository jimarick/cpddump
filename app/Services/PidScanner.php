<?php

namespace App\Services;

/**
 * Deterministic patient-identifiable-data detection — no AI involved, so it
 * runs on every item even when analysis fails, and never has an off day.
 * Precision over recall: the AI analyst handles fuzzy identifiers; this
 * catches the hard, checksummable ones.
 */
class PidScanner
{
    /**
     * @return array<int, array{type: string, excerpt: string, severity: string, detected_by: string}>
     */
    public function scan(string $text): array
    {
        $flags = [];

        foreach ($this->nhsNumbers($text) as $match) {
            $flags[] = [
                'type' => 'nhs_number',
                'excerpt' => $match,
                'severity' => 'high',
                'detected_by' => 'scanner',
            ];
        }

        if (preg_match_all(
            '/\b(?:dob|d\.o\.b\.?|date of birth|born(?:\son)?)\b[:\s]{0,5}(\d{1,2}[\/\-. ]\d{1,2}[\/\-. ]\d{2,4})/i',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $flags[] = [
                    'type' => 'date_of_birth',
                    'excerpt' => $match[0],
                    'severity' => 'high',
                    'detected_by' => 'scanner',
                ];
            }
        }

        return $flags;
    }

    /**
     * Replace verified NHS numbers in a piece of text. Used to keep hard
     * identifiers out of AI drafts and to scrub user-authored text on
     * "remove patient info".
     *
     * @return array{text: string, found: bool}
     */
    public function scrubNhsNumbers(string $text): array
    {
        $found = false;

        foreach ($this->nhsNumbers($text) as $number) {
            $text = str_replace($number, '[NHS number removed]', $text);
            $found = true;
        }

        return ['text' => $text, 'found' => $found];
    }

    /**
     * Candidate 10-digit groups that pass the NHS mod-11 checksum — the
     * checksum makes false positives (phone numbers, reference codes) rare.
     *
     * @return array<int, string>
     */
    private function nhsNumbers(string $text): array
    {
        if (! preg_match_all('/\b\d{3}[ -]?\d{3}[ -]?\d{4}\b/', $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $matches[0],
            fn (string $candidate) => $this->validNhsChecksum(preg_replace('/\D/', '', $candidate) ?? ''),
        )));
    }

    private function validNhsChecksum(string $digits): bool
    {
        if (strlen($digits) !== 10) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $digits[$i] * (10 - $i);
        }

        $check = 11 - ($sum % 11);

        if ($check === 11) {
            $check = 0;
        }

        return $check !== 10 && $check === (int) $digits[9];
    }
}
