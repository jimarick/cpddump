<?php

namespace App\Enums;

enum IgnoreRuleField: string
{
    case Title = 'title';
    case Organiser = 'organiser';
    case Sender = 'sender';
    case SenderDomain = 'sender_domain';
}
