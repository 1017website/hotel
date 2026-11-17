<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaasLaunchSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_publishes_the_initial_catalog_without_demo_data_and_preserves_edits(): void
    {
        $this->artisan('saas:setup')->assertSuccessful();

        $this->assertSame(3, DB::table('subscription_plans')->where('published', true)->count());
        $this->assertDatabaseHas('subscription_plans', [
            'slug' => 'starter',
            'monthly_price' => 299_000,
            'yearly_price' => 2_990_000,
            'room_limit' => 25,
            'user_limit' => 8,
            'published' => true,
        ]);

        $this->get('/register')->assertOk()
            ->assertSee('Rp 299.000')
            ->assertSee('Pendaftaran belum dibuka')
            ->assertDontSee('Buat akun &amp; tagihan', false);

        DB::table('subscription_plans')->where('slug', 'starter')->update(['monthly_price' => 325_000]);
        $this->artisan('saas:setup')->assertSuccessful();

        $this->assertDatabaseEmpty('hotels');
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseHas('subscription_plans', ['slug' => 'starter', 'monthly_price' => 325_000]);
        $this->assertSame(3, DB::table('subscription_plans')->where('published', true)->count());
    }

    public function test_setup_can_create_the_first_platform_super_admin(): void
    {
        $email = 'launch-admin@example.test';
        $this->artisan('saas:setup', ['email' => $email])
            ->expectsQuestion('Nama Super Admin SaaS', 'Admin Launch')
            ->expectsQuestion('Kata sandi Super Admin (minimal 12 karakter)', 'LaunchAdmin123!')
            ->assertSuccessful();

        $user = User::where('email', $email)->firstOrFail();
        $this->assertDatabaseHas('platform_admins', [
            'user_id' => $user->id,
            'is_super' => true,
            'active' => true,
        ]);
        $this->assertDatabaseEmpty('hotel_user');
    }

    public function test_super_admin_sets_payment_instructions_and_activates_a_signup(): void
    {
        $this->artisan('saas:setup')->assertSuccessful();
        $admin = User::factory()->create();
        DB::table('platform_admins')->insert(['user_id' => $admin->id, 'is_super' => true, 'active' => true, 'permissions' => '[]']);

        $this->actingAs($admin)->putJson('/saas/api/settings', ['signup_enabled' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_instructions');

        $this->putJson('/saas/api/settings', [
            'signup_enabled' => true,
            'payment_instructions' => 'Hubungi bagian tagihan untuk memperoleh rincian pembayaran.',
        ])->assertOk();

        $this->assertDatabaseHas('saas_settings', ['id' => 1, 'signup_enabled' => true]);

        $this->post('/logout');
        $plan = DB::table('subscription_plans')->where('slug', 'bisnis')->first();
        $this->post('/register', [
            'hotel_name' => 'Hotel Uji Launch',
            'name' => 'Pemilik Uji Launch',
            'email' => 'owner-launch@example.test',
            'password' => 'LaunchTest123!',
            'password_confirmation' => 'LaunchTest123!',
            'plan_id' => $plan->id,
            'months' => 12,
        ])->assertRedirect('/subscription');

        $invoice = DB::table('subscription_invoices')->first();
        $this->assertSame(5_990_000, (int) $invoice->amount);
        $this->assertDatabaseHas('saas_accounts', ['hotel_id' => $invoice->hotel_id, 'status' => 'pending']);

        $this->actingAs($admin)->postJson("/saas/api/invoices/{$invoice->id}", [
            'action' => 'paid',
            'reference' => 'LAUNCH-QA',
            'method' => 'Transfer',
        ])->assertOk();

        $this->assertDatabaseHas('saas_accounts', [
            'hotel_id' => $invoice->hotel_id,
            'status' => 'active',
            'room_limit' => 75,
            'user_limit' => 25,
        ]);
    }
}
