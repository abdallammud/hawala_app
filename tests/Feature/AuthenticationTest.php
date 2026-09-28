<?php

use App\Models\User;

it('redirects guests to login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Log in');
});

it('logs in with email', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('logs in with phone', function () {
    $user = User::factory()->create(['phone' => '+252611234567', 'password' => bcrypt('password')]);

    $this->post('/login', [
        'login' => $user->phone,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('blocks deactivated users', function () {
    $user = User::factory()->create(['is_active' => false, 'password' => bcrypt('password')]);

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('rejects wrong credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);

    $this->post('/login', [
        'login' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('lets an authenticated user reach the dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Dashboard');
});

it('logs out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $this->assertGuest();
});
