<?php

namespace App\Enums;

enum AiPurpose: string
{
    case InboxAnalysis = 'inbox_analysis';
    case TextAssist = 'text_assist';
    case ReflectionDraft = 'reflection_draft';
    case ReviewCompose = 'review_compose';
    case MergeReflection = 'merge_reflection';
    case QuestionAnswer = 'question_answer';
    case Report = 'report';
    case WeeklyDigest = 'weekly_digest';
}
