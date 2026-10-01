<?php

namespace App\Providers;

use App\Models\AiGeneration;
use App\Observers\AiGenerationObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AiGeneration::observe(AiGenerationObserver::class);
        Vite::prefetch(concurrency: 3);
    }
}
