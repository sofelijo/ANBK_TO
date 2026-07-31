<?php

namespace App\Models;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'school_id', 'requested_by', 'source_question_id', 'attempt_id', 'type',
    'status', 'provider', 'model', 'input_hash', 'request_payload',
    'result_payload', 'input_tokens', 'output_tokens', 'cost_microusd', 'error',
])]
class AiGeneration extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AiGenerationType::class,
            'status' => AiGenerationStatus::class,
            'request_payload' => 'array',
            'result_payload' => 'array',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'source_question_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }
}
