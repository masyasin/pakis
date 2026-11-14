<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/confirm-password'),
        );
    }

    /**
     * Regresi T-013: field login Fortify bernama `login` (bukan kolom DB). Konfirmasi password
     * harus mencocokkan hash user yang sedang login, bukan mencari user lewat kolom `login`.
     */
    public function test_password_can_be_confirmed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_wrong_password_is_not_confirmed()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'salah-sekali'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_password_confirmation_requires_authentication()
    {
        $response = $this->get(route('password.confirm'));

        $response->assertRedirect(route('login'));
    }
}
