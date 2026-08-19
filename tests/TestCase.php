<?php

namespace Tests;

use App\Domain\AI\Contracts\AIProviderInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeAIProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Automated tests must never call a real AI provider — no network
        // dependency, no cost, no non-determinism. Tests that specifically
        // exercise the AI fallback path rebind this with a canned response.
        $this->app->instance(AIProviderInterface::class, new FakeAIProvider);
    }
}
