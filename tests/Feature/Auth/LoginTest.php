<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_sign_in_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('a-very-secure-password'),
        ]);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'a-very-secure-password')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_in_fails_with_the_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('a-very-secure-password'),
        ]);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
