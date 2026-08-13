<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_id', 'subject_id', 'code', 'name', 'description'])]
class QuestionBlueprint extends Model
{
    use HasFactory;

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function competencies(): BelongsToMany
    {
        return $this->belongsToMany(Competency::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
