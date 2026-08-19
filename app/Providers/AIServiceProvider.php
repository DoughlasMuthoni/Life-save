<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Domain\AI\Contracts\AIProviderInterface;
use App\Domain\AI\Providers\ClaudeProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Binds AIProviderInterface to a concrete provider based on config
 * ('services.ai_provider'). This is the only place that decides which
 * vendor is behind the interface — domain code always depends on
 * AIProviderInterface, never on ClaudeProvider directly (CLAUDE.md §8).
 */
class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIProviderInterface::class, function ($app) {
            return match (config('services.ai_provider')) {
                'claude' => new ClaudeProvider(
                    client: new AnthropicClient(apiKey: config('services.anthropic.api_key')),
                    model: config('services.anthropic.model'),
                ),
                default => throw new RuntimeException(
                    'Unknown AI_PROVIDER ['.config('services.ai_provider').']. Supported: claude.'
                ),
            };
        });
    }
}
