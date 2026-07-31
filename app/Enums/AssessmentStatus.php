<?php

namespace App\Enums;

enum AssessmentStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
}
