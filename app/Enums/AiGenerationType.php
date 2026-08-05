<?php

namespace App\Enums;

enum AiGenerationType: string
{
    case QuestionVariants = 'question_variants';
    case StoryQuestions = 'story_questions';
    case StoryIllustration = 'story_illustration';
    case QuestionValidation = 'question_validation';
    case AttemptSummary = 'attempt_summary';
    case StudentChat = 'student_chat';
}
