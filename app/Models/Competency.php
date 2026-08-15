<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['school_id', 'subject_id', 'parent_id', 'code', 'domain', 'name', 'description', 'grade_level'])]
class Competency extends Model
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function subcompetencyQuestions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Question::class,
            self::class,
            'parent_id',
            'competency_id',
        );
    }

    public function questionBlueprints(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBlueprint::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
