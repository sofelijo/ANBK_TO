<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'assessment_id', 'school_npsn', 'scheduled_date', 'session_number', 'student_count',
    'starts_at', 'ends_at', 'created_by',
])]
class AssessmentSchedule extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'session_number' => 'integer',
            'student_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected function sessionLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->starts_at && $this->ends_at
            ? $this->starts_at->format('H:i').'–'.$this->ends_at->format('H:i')
            : '-');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'attempts', 'assessment_schedule_id', 'user_id')
            ->withPivot(['status', 'started_at'])
            ->orderByPivot('started_at')
            ->orderBy('users.id');
    }
}
