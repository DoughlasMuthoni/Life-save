<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The floating help button lives in the shared authenticated layout, not
 * any one page's component — this just confirms it actually renders (and
 * doesn't break page rendering) rather than re-testing every page it
 * appears on. Uses a real HTTP request rather than Livewire::test(),
 * since the component-only test harness never wraps the response in its
 * #[Layout(...)] — only an actual page request does.
 */
class HelpPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_help_panel_renders_on_an_authenticated_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('How everything works')
            ->assertSee('AI Assistant')
            ->assertSee('Fuliza');
    }

    public function test_the_help_panel_does_not_appear_on_the_guest_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('How everything works');
    }
}
