<?php

namespace App\Domain\Notes\Services;

use App\Domain\Notes\Models\Note;
use App\Models\User;

class NoteService
{
    public function createNote(User $user, string $body, ?string $title = null): Note
    {
        return Note::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    public function updateNote(Note $note, string $body, ?string $title = null): Note
    {
        $note->update([
            'title' => $title,
            'body' => $body,
        ]);

        return $note;
    }

    public function deleteNote(Note $note): void
    {
        $note->delete();
    }
}
