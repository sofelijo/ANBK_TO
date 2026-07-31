<?php

namespace App\Console\Commands;

use App\Enums\AiGenerationStatus;
use App\Enums\AiGenerationType;
use App\Models\AiGeneration;
use App\Services\AI\StoryIllustrationService;
use Illuminate\Console\Command;

class PollAiImageBatches extends Command
{
    protected $signature = 'ai:poll-image-batches';

    protected $description = 'Memeriksa batch ilustrasi Gemini yang masih diproses';

    public function handle(StoryIllustrationService $service): int
    {
        AiGeneration::query()
            ->where('type', AiGenerationType::StoryIllustration)
            ->where('status', AiGenerationStatus::Processing)
            ->where('updated_at', '>=', now()->subDays(2))
            ->each(fn (AiGeneration $generation) => $service->refresh($generation));

        return self::SUCCESS;
    }
}
