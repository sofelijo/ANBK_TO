<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attempt_id', 'competency_id', 'correct_count', 'question_count', 'percentage'])]
class CompetencyResult extends Model
{
    use HasFactory;

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }
}
