<?php

namespace Tests\Feature\AI;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_asking_a_question_shows_the_answer(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('ai-assistant')
            ->set('question', 'How am I doing this month?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSee('How am I doing this month?')
            ->assertSee('This is a fake AI response.');
    }

    public function test_a_blank_question_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('ai-assistant')
            ->set('question', '')
            ->call('ask')
            ->assertHasErrors(['question']);
    }

    public function test_a_suggested_question_can_be_asked_directly(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('ai-assistant')
            ->call('askSuggested', 'Where did most of my money go this month?')
            ->assertSee('Where did most of my money go this month?');
    }

    public function test_asking_too_many_questions_in_a_short_time_is_rate_limited(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('ai-assistant');

        for ($i = 0; $i < 20; $i++) {
            $component->set('question', "Question {$i}")->call('ask')->assertHasNoErrors();
        }

        $component->set('question', 'One question too many')->call('ask')->assertHasErrors(['question']);
    }
}
