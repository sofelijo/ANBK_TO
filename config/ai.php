<?php

return [
    'driver' => env('AI_DRIVER', 'fake'),

    'daily_question_limit' => (int) env('AI_DAILY_QUESTION_LIMIT', 50),
    'daily_story_limit' => (int) env('AI_DAILY_STORY_LIMIT', 10),
    'daily_image_limit' => (int) env('AI_DAILY_IMAGE_LIMIT', 20),

    'image' => [
        'model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-lite-image'),
        'disk' => env('AI_IMAGE_DISK', 'public'),
        'batch_cost_microusd' => (int) env('GEMINI_IMAGE_BATCH_COST_MICROUSD', 16800),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'input_usd_per_million' => (float) env('GEMINI_INPUT_USD_PER_MILLION', 0.25),
        'output_usd_per_million' => (float) env('GEMINI_OUTPUT_USD_PER_MILLION', 1.50),
    ],
];
