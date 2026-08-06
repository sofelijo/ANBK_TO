<?php

namespace App\Models;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'school_id', 'author_id', 'parent_id', 'revision_of_id', 'version', 'superseded_by_id',
    'story_generation_id', 'competency_id', 'type', 'status',
    'title', 'stimulus', 'prompt', 'explanation', 'difficulty', 'grade_level',
    'cognitive_level', 'metadata', 'approved_by', 'approved_at',
])]
class Question extends Model
{
    use HasFactory;

    protected $appends = ['illustration_url'];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'status' => QuestionStatus::class,
            'metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    protected function illustrationUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $disk = data_get($this->metadata, 'illustration.disk');
            $path = data_get($this->metadata, 'illustration.path');

            if (! is_string($disk) || $disk === '' || ! is_string($path) || $path === '') {
                return null;
            }

            return Storage::disk($disk)->url($path);
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revision_of_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revision_of_id');
    }

    public function storyGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'story_generation_id');
    }

    public function bundleQuestions(): HasMany
    {
        return $this->hasMany(self::class, 'story_generation_id', 'story_generation_id')
            ->whereNull('superseded_by_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(QuestionReview::class)->latest('reviewed_at');
    }

    public function assessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class)
            ->withPivot(['position', 'points', 'snapshot']);
    }
}
