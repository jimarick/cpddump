<?php

namespace App\Enums;

enum AiProvider: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI',
            self::Anthropic => 'Anthropic',
        };
    }
}
