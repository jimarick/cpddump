<?php

namespace App\Enums;

enum IgnoreRuleOperator: string
{
    case Equals = 'equals';
    case Contains = 'contains';

    public function matches(string $value, string $candidate): bool
    {
        return match ($this) {
            self::Equals => mb_strtolower(trim($candidate)) === mb_strtolower(trim($value)),
            self::Contains => str_contains(mb_strtolower($candidate), mb_strtolower($value)),
        };
    }
}
