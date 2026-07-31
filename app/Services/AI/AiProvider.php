<?php

namespace App\Services\AI;

interface AiProvider
{
    public function name(): string;

    public function model(): string;

    public function generateJson(string $prompt, array $context = []): AiResponse;
}
