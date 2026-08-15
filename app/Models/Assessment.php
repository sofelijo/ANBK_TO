<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'school_id', 'subject_id', 'created_by', 'title', 'description', 'grade_level',
    'duration_minutes', 'status', 'starts_at', 'ends_at', 'settings', 'competency_slots',
])]
class Assessment extends Model
{
    use HasFactory;

    public const TYPE_REGULAR = 'regular';

    public const TYPE_TOGETHER = 'together';

    protected function casts(): array
    {
        return [
            'status' => AssessmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'settings' => 'array',
            'competency_slots' => 'array',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class)
            ->withPivot(['position', 'points', 'snapshot'])
            ->orderByPivot('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(AssessmentSchedule::class);
    }

    public function assessmentType(): string
    {
        return data_get($this->settings, 'type') === self::TYPE_TOGETHER
            ? self::TYPE_TOGETHER
            : self::TYPE_REGULAR;
    }

    public function requiresSchoolSchedule(): bool
    {
        return $this->assessmentType() === self::TYPE_TOGETHER;
    }
}
