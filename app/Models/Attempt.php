<?php

namespace App\Models;

use App\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'assessment_id', 'user_id', 'status', 'started_at',
    'submitted_at', 'duration_seconds', 'score', 'max_score', 'summary',
])]
class Attempt extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'score' => 'decimal:2',
            'max_score' => 'decimal:2',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class);
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'attempt_question')
            ->withPivot(['position', 'points', 'snapshot'])
            ->orderByPivot('position');
    }

    public function competencyResults(): HasMany
    {
        return $this->hasMany(CompetencyResult::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttemptEvent::class);
    }

    public function isExpired(): bool
    {
        return $this->started_at
            ->copy()
            ->addMinutes($this->assessment->duration_minutes)
            ->isPast();
    }
}
