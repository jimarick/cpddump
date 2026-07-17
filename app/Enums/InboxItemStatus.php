<?php

namespace App\Enums;

enum InboxItemStatus: string
{
    case Pending = 'pending';
    case Analysing = 'analysing';
    case Ready = 'ready';
    case Approved = 'approved';
    case Dismissed = 'dismissed';
    case Failed = 'failed';

    /**
     * Statuses still awaiting a user decision (shown in the inbox).
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Analysing, self::Ready, self::Failed];
    }
}
