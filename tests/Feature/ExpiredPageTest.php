<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

/**
 * A form whose page outlived the session fails the CSRF check (a 419). The
 * stock "Page Expired" screen has no link, and an installed app has no reload
 * button, so people could not get back in. These guard the way out.
 */
beforeEach(function () {
    Route::post('/_expired-form', fn () => throw new TokenMismatchException)->middleware('web');
});

it('sends someone with no session to a fresh login page, not a dead end', function () {
    $response = $this->post('/_expired-form');

    $response->assertRedirect(route('login'))
        ->assertSessionHas('error', 'Your session timed out. Please log in again.');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Your session timed out. Please log in again.')
        ->assertDontSee('Page Expired');
});

it('sends a signed-in person back to the page they were on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/profile')
        ->post('/_expired-form', ['name' => 'Kept'])
        ->assertRedirect('/profile')
        ->assertSessionHas('error')
        ->assertSessionHas('_old_input.name', 'Kept');
});

it('never flashes a typed password back after an expired form', function () {
    $this->post('/_expired-form', ['login' => 'a@b.test', 'password' => 'secret-pass'])
        ->assertSessionMissing('_old_input.password');
});

it('shows the message on the page they land on inside the app', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['error' => 'That page was open for too long and nothing was saved. Please try again.'])
        ->get('/dashboard')
        ->assertSee('nothing was saved');
});

it('keeps answering API-style requests with a plain 419', function () {
    $this->postJson('/_expired-form')->assertStatus(419);
});

it('makes a login page left open reload itself for a fresh token', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('visibilitychange', false)
        ->assertSee('window.location.reload()', false);
});
