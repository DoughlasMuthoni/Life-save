<?php

namespace Tests\Feature\Calendar;

use App\Domain\Calendar\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_event_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('calendar')
            ->call('startCreating')
            ->set('title', 'Dentist appointment')
            ->set('eventDate', today()->addDays(3)->toDateString())
            ->set('eventTime', '14:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Dentist appointment')
            ->assertSee('2:30 PM');

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'title' => 'Dentist appointment',
            'event_time' => '14:30:00',
        ]);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('calendar')
            ->call('startCreating')
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title']);
    }

    public function test_past_events_are_separated_from_upcoming(): void
    {
        $user = User::factory()->create();
        CalendarEvent::create(['user_id' => $user->id, 'title' => 'Old event', 'event_date' => today()->subWeek()]);
        CalendarEvent::create(['user_id' => $user->id, 'title' => 'Future event', 'event_date' => today()->addWeek()]);

        $component = Livewire::actingAs($user)->test('calendar');

        $component->assertSee('Future event')->assertSee('Old event')->assertSee('Past events');
        $this->assertCount(1, $component->get('upcoming'));
        $this->assertCount(1, $component->get('past'));
    }

    public function test_an_event_can_be_edited(): void
    {
        $user = User::factory()->create();
        $event = CalendarEvent::create(['user_id' => $user->id, 'title' => 'Old title', 'event_date' => today()->addDay()]);

        Livewire::actingAs($user)
            ->test('calendar')
            ->call('startEditing', $event->id)
            ->set('title', 'New title')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('New title');

        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'title' => 'New title']);
    }

    public function test_an_event_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $event = CalendarEvent::create(['user_id' => $user->id, 'title' => 'Delete me', 'event_date' => today()->addDay()]);

        Livewire::actingAs($user)
            ->test('calendar')
            ->call('delete', $event->id)
            ->assertDontSee('Delete me');

        $this->assertDatabaseMissing('calendar_events', ['id' => $event->id]);
    }

    public function test_a_user_cannot_edit_another_users_event(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = CalendarEvent::create(['user_id' => $owner->id, 'title' => 'Private', 'event_date' => today()->addDay()]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('calendar')->call('startEditing', $event->id);
    }
}
