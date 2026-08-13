<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_id', 'code', 'name', 'description', 'ai_question_format'])]
class Subject extends Model
{
    use HasFactory;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(Competency::class);
    }

    public function questionBlueprints(): HasMany
    {
        return $this->hasMany(QuestionBlueprint::class);
    }
}
