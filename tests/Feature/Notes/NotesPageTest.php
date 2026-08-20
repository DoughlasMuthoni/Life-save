<?php

namespace Tests\Feature\Notes;

use App\Domain\Notes\Models\Note;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_note_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('notes')
            ->call('startCreating')
            ->set('title', 'Grocery list')
            ->set('body', 'Milk, eggs, bread')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Grocery list')
            ->assertSee('Milk, eggs, bread');

        $this->assertDatabaseHas('notes', [
            'user_id' => $user->id,
            'title' => 'Grocery list',
            'body' => 'Milk, eggs, bread',
        ]);
    }

    public function test_a_note_without_a_title_is_shown_as_untitled(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('notes')
            ->call('startCreating')
            ->set('body', 'Just a thought.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Untitled');
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('notes')
            ->call('startCreating')
            ->set('body', '')
            ->call('save')
            ->assertHasErrors(['body']);
    }

    public function test_a_note_can_be_edited(): void
    {
        $user = User::factory()->create();
        $note = Note::create(['user_id' => $user->id, 'title' => 'Old title', 'body' => 'Old body']);

        Livewire::actingAs($user)
            ->test('notes')
            ->call('startEditing', $note->id)
            ->set('title', 'New title')
            ->set('body', 'New body')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('New title')
            ->assertSee('New body');

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'title' => 'New title', 'body' => 'New body']);
    }

    public function test_a_note_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $note = Note::create(['user_id' => $user->id, 'title' => 'Delete me', 'body' => 'Body']);

        Livewire::actingAs($user)
            ->test('notes')
            ->call('delete', $note->id)
            ->assertDontSee('Delete me');

        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
    }

    public function test_a_user_cannot_edit_another_users_note(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $note = Note::create(['user_id' => $owner->id, 'title' => 'Private', 'body' => 'Body']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('notes')->call('startEditing', $note->id);
    }
}
