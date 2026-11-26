<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login as FilamentLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Filament admin login page renders successfully.
     */
    public function test_filament_admin_login_page_renders(): void
    {
        $response = $this->get('/admin/login');
        $response->assertSuccessful();
    }

    /**
     * Test Filament admin login succeeds with valid credentials and redirects to /admin.
     */
    public function test_filament_admin_login_succeeds_for_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'testadmin@dismac.com.bo',
            'password' => bcrypt('1q2w3e4r'),
            'role' => 'admin',
        ]);

        Livewire::test(FilamentLogin::class)
            ->set('data.email', 'testadmin@dismac.com.bo')
            ->set('data.password', '1q2w3e4r')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test Filament admin login succeeds for subadmin and grants access to /admin.
     */
    public function test_filament_admin_login_succeeds_for_subadmin(): void
    {
        $user = User::factory()->create([
            'email' => 'subadmin@dismac.com.bo',
            'password' => bcrypt('1q2w3e4r'),
            'role' => 'subadmin',
        ]);

        Livewire::test(FilamentLogin::class)
            ->set('data.email', 'subadmin@dismac.com.bo')
            ->set('data.password', '1q2w3e4r')
            ->call('authenticate')
            ->assertHasNoErrors()
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    /**
     * Test Filament admin login fails with invalid credentials.
     */
    public function test_filament_admin_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'testadmin@dismac.com.bo',
            'password' => bcrypt('1q2w3e4r'),
            'role' => 'admin',
        ]);

        Livewire::test(FilamentLogin::class)
            ->set('data.email', 'testadmin@dismac.com.bo')
            ->set('data.password', 'wrong-pass')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    /**
     * Test /login redirects to /admin/login cleanly.
     */
    public function test_web_login_redirects_to_filament_login(): void
    {
        $response = $this->get('/login');
        $response->assertRedirect('/admin/login');
    }
}
