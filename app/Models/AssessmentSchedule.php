<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'assessment_id', 'school_npsn', 'scheduled_date', 'session_number',
    'starts_at', 'ends_at', 'created_by',
])]
class AssessmentSchedule extends Model
{
    use HasFactory;

    public const SLOTS = [
        1 => ['start' => '06:30', 'end' => '09:00'],
        2 => ['start' => '09:00', 'end' => '11:30'],
        3 => ['start' => '11:30', 'end' => '14:00'],
        4 => ['start' => '14:00', 'end' => '16:30'],
    ];

    public static function bookingRequired(?Carbon $moment = null): bool
    {
        $moment ??= now();
        $minutes = ($moment->hour * 60) + $moment->minute;

        return $moment->isWeekday()
            && $minutes >= (6 * 60) + 30
            && $minutes < (16 * 60) + 30;
    }

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'session_number' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected function sessionLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $slot = self::SLOTS[$this->session_number] ?? null;

            return $slot ? "{$slot['start']}–{$slot['end']}" : '-';
        });
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
