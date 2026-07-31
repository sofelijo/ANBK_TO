<?php

namespace App\Enums;

enum QuestionType: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';
    case ShortAnswer = 'short_answer';
    case Matching = 'matching';
    case CategoryMatrix = 'category_matrix';
}
