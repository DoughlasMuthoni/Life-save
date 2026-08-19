<?php

use App\Domain\AI\Services\FinancialAssistantService;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public string $question = '';

    /** @var array<int, array{question: string, answer: string}> */
    public array $history = [];

    public bool $asking = false;

    public function ask(FinancialAssistantService $assistant): void
    {
        $this->validate(['question' => ['required', 'string', 'max:500']]);

        $question = $this->question;
        $answer = $assistant->answerQuestion(auth()->user(), $question);

        $this->history[] = ['question' => $question, 'answer' => $answer];
        $this->question = '';
    }

    public function askSuggested(string $suggestion, FinancialAssistantService $assistant): void
    {
        $this->question = $suggestion;
        $this->ask($assistant);
    }
};
?>

<div>
    <x-ui.page-header title="AI Assistant" subtitle="Read-only — it can explain your finances but never changes anything." />
    <p class="mt-1 flex items-center gap-1.5 text-xs text-slate-400">
        <x-icon name="lock" class="h-3.5 w-3.5" />
        Every figure it gives you comes from your actual ledger, calculated by the app, not guessed by the model.
    </p>

    @if (empty($history))
        <div class="mt-8 flex flex-col items-center rounded-2xl border border-dashed border-slate-200 px-6 py-10 text-center">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-500">
                <x-icon name="sparkles" class="h-6 w-6" />
            </span>
            <p class="mt-3 text-sm font-medium text-slate-900">Ask me anything about your finances</p>
            <p class="mt-1 text-sm text-slate-500">Try one of these to get started</p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                @foreach ([
                    'Where did most of my money go this month?',
                    'How does this month compare to last month?',
                    'How am I doing on my savings goals?',
                    'What are my account balances?',
                ] as $suggestion)
                    <button wire:click="askSuggested(@js($suggestion))" class="rounded-full border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                        {{ $suggestion }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="mt-6 space-y-4">
        @foreach ($history as $exchange)
            <div class="flex justify-end">
                <div class="max-w-lg rounded-2xl rounded-br-sm bg-blue-600 px-4 py-2 text-sm text-white">
                    {{ $exchange['question'] }}
                </div>
            </div>
            <div class="flex items-start justify-start gap-2">
                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-500">
                    <x-icon name="sparkles" class="h-3.5 w-3.5" />
                </span>
                <div class="max-w-lg rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-4 py-2 text-sm whitespace-pre-wrap text-slate-700">
                    {{ $exchange['answer'] }}
                </div>
            </div>
        @endforeach
    </div>

    <form wire:submit="ask" class="mt-6 flex gap-3">
        <input
            wire:model="question"
            type="text"
            placeholder="Ask anything about your finances&hellip;"
            class="block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
            wire:loading.attr="disabled"
        >
        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled">
            <span wire:loading.remove class="flex items-center gap-1.5"><x-icon name="chat" class="h-4 w-4" /> Ask</span>
            <span wire:loading>Thinking&hellip;</span>
        </x-ui.button>
    </form>
    @error('question') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
