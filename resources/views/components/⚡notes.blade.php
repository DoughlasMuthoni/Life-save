<?php

use App\Domain\Notes\Models\Note;
use App\Domain\Notes\Services\NoteService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.authenticated')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingNoteId = null;

    public string $title = '';

    public string $body = '';

    public function getNotesProperty()
    {
        return Note::query()->where('user_id', auth()->id())->latest('updated_at')->get();
    }

    public function startCreating(): void
    {
        $this->reset(['title', 'body', 'editingNoteId']);
        $this->showForm = true;
    }

    public function startEditing(int $noteId): void
    {
        $note = Note::where('user_id', auth()->id())->findOrFail($noteId);

        $this->editingNoteId = $note->id;
        $this->title = (string) $note->title;
        $this->body = $note->body;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->reset(['title', 'body', 'editingNoteId']);
    }

    public function save(NoteService $notes): void
    {
        $this->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        if ($this->editingNoteId !== null) {
            $note = Note::where('user_id', auth()->id())->findOrFail($this->editingNoteId);
            $notes->updateNote($note, $this->body, $this->title ?: null);
        } else {
            $notes->createNote(auth()->user(), $this->body, $this->title ?: null);
        }

        $this->cancel();
    }

    public function delete(int $noteId, NoteService $notes): void
    {
        $note = Note::where('user_id', auth()->id())->findOrFail($noteId);
        $notes->deleteNote($note);
    }
};
?>

<div>
    <x-ui.page-header title="Notes" subtitle="Quick freeform notes — nothing fancy, just a place to jot things down.">
        <x-slot:actions>
            <x-ui.button wire:click="startCreating" variant="primary">
                <x-icon name="plus" class="h-4 w-4" /> New note
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
            <div>
                <label class="block text-sm font-medium text-slate-700">Title <span class="text-slate-400">(optional)</span></label>
                <input wire:model="title" type="text" placeholder="Untitled" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Note</label>
                <textarea wire:model="body" rows="6" placeholder="Write something…" class="mt-1 block w-full resize-y rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm leading-relaxed text-slate-800 placeholder:text-slate-400 shadow-sm transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none"></textarea>
                @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-3">
                <x-ui.button type="submit" variant="primary">Save</x-ui.button>
                <x-ui.button type="button" wire:click="cancel" variant="secondary">Cancel</x-ui.button>
            </div>
        </form>
    @endif

    @if ($this->notes->isEmpty())
        <x-ui.empty-state icon="document-text" title="No notes yet" description="Jot something down to get started." class="mt-6" />
    @else
        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->notes as $note)
                <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 flex-1 break-words font-medium text-slate-900">{{ $note->title ?: 'Untitled' }}</p>
                        <div class="flex shrink-0 gap-1">
                            <button wire:click="startEditing({{ $note->id }})" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Edit note">
                                <x-icon name="pencil" class="h-4 w-4" />
                            </button>
                            <button wire:click="delete({{ $note->id }})" wire:confirm="Delete this note?" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Delete note">
                                <x-icon name="x-mark" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                    <p class="mt-2 flex-1 break-words whitespace-pre-wrap text-sm text-slate-600">{{ Str::limit($note->body, 240) }}</p>
                    <p class="mt-3 text-xs text-slate-400">{{ $note->updated_at->diffForHumans() }}</p>
                </div>
            @endforeach
        </div>
    @endif
</div>
