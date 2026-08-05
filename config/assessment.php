<?php

return [
    'item_analysis' => [
        'minimum_responses' => (int) env('ITEM_ANALYSIS_MIN_RESPONSES', 30),
        'upper_lower_group_ratio' => (float) env('ITEM_ANALYSIS_GROUP_RATIO', 0.27),
        'distractor_minimum_rate' => (float) env('ITEM_ANALYSIS_DISTRACTOR_MIN_RATE', 0.05),
    ],

    'types' => [
        'tryout' => 'Try Out Reguler',
        'simulation' => 'Simulasi ANBK',
        'diagnostic' => 'Tes Diagnostik',
        'practice' => 'Latihan Harian',
        'remedial' => 'Remedial',
        'custom' => 'Jenis Custom',
    ],
];
