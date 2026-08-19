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
    <h1 class="text-xl font-semibold text-slate-900">AI Assistant</h1>
    <p class="mt-1 text-sm text-slate-500">
        Read-only — it can explain your finances but never changes anything. Every figure it gives you comes from
        your actual ledger, calculated by the app, not guessed by the model.
    </p>

    @if (empty($history))
        <div class="mt-6 flex flex-wrap gap-2">
            @foreach ([
                'Where did most of my money go this month?',
                'How does this month compare to last month?',
                'How am I doing on my savings goals?',
                'What are my account balances?',
            ] as $suggestion)
                <button wire:click="askSuggested(@js($suggestion))" class="rounded-full border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="mt-6 space-y-4">
        @foreach ($history as $exchange)
            <div class="flex justify-end">
                <div class="max-w-lg rounded-2xl rounded-br-sm bg-blue-600 px-4 py-2 text-sm text-white">
                    {{ $exchange['question'] }}
                </div>
            </div>
            <div class="flex justify-start">
                <div class="max-w-lg rounded-2xl rounded-bl-sm bg-white border border-slate-200 px-4 py-2 text-sm text-slate-700 whitespace-pre-wrap">
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
        <button type="submit" class="shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700" wire:loading.attr="disabled">
            <span wire:loading.remove>Ask</span>
            <span wire:loading>Thinking&hellip;</span>
        </button>
    </form>
    @error('question') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
</div>
