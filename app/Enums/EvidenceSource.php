<?php

namespace App\Enums;

enum EvidenceSource: string
{
    case Manual = 'manual';
    case Upload = 'upload';
    case Email = 'email';
    case EmailAttachment = 'email_attachment';
    case Link = 'link';
    case Calendar = 'calendar';
    case VoiceNote = 'voice_note';
    case Article = 'article';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Upload => 'Upload',
            self::Email => 'Email',
            self::EmailAttachment => 'Attachment',
            self::Link => 'Link',
            self::Calendar => 'Calendar',
            self::VoiceNote => 'Voice',
            self::Article => 'Article',
        };
    }
}
