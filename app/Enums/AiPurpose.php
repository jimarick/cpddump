<?php

namespace App\Enums;

enum AiPurpose: string
{
    case InboxAnalysis = 'inbox_analysis';
    case TextAssist = 'text_assist';
    case QuestionAnswer = 'question_answer';
    case Report = 'report';
    case WeeklyDigest = 'weekly_digest';
}
