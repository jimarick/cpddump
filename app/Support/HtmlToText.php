<?php

namespace App\Support;

class HtmlToText
{
    public static function convert(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html));
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }
}
