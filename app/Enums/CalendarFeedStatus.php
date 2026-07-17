<?php

namespace App\Enums;

enum CalendarFeedStatus: string
{
    case Active = 'active';
    case Failing = 'failing';
    case Disabled = 'disabled';
}
