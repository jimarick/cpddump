<?php

namespace App\Enums;

enum ReportKind: string
{
    case Question = 'question';
    case Report = 'report';
    case EvidenceZip = 'evidence_zip';
}
