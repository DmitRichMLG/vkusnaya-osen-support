<?php

namespace App\Providers;

use App\Bot\GeminiClient;
use App\Bot\PromptBuilder;
use App\Bot\RulesRepository;
use App\Telegram\TelegramClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RulesRepository::class, fn () => new RulesRepository(base_path('docs/assignment/promo-rules.md')));

        $this->app->singleton(PromptBuilder::class, fn ($app) => new PromptBuilder(
            $app->make(RulesRepository::class),
            resource_path('prompts'),
        ));

        $this->app->singleton(TelegramClient::class, fn () => new TelegramClient((string) config('promo.telegram.token')));

        $this->app->singleton(GeminiClient::class, fn () => new GeminiClient(
            (string) config('promo.gemini.key'),
            config('promo.gemini.models'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
