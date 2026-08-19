<?php

namespace App\Domain\AI\DataTransferObjects;

use Closure;

/**
 * A single whitelisted capability the AI assistant may call — the ONLY
 * way it ever touches data (CLAUDE.md §8: no database access, no SQL
 * tool, only named application services). Provider-agnostic on purpose:
 * this is a plain data/handler pair, not an Anthropic SDK type, so
 * ClaudeProvider adapts it to its own tool-calling mechanism and a future
 * OpenAIProvider could do the same without this shape changing.
 */
final readonly class AiTool
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema for the tool's input.
     * @param  Closure(array<string, mixed>): mixed  $handler  Already bound to the current user — never accepts a user id from the model.
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public Closure $handler,
    ) {}
}
