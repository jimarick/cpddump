<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Open = 'open';
    case Achieved = 'achieved';
    case CarriedOver = 'carried_over';
}
