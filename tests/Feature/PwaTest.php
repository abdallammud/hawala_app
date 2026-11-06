<?php

use App\Models\User;

it('ships a manifest named Bluestar with icons that exist', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest['name'])->toBe('Bluestar')
        ->and($manifest['short_name'])->toBe('Bluestar')
        ->and($manifest['display'])->toBe('standalone');

    $sizes = collect($manifest['icons'])->pluck('sizes')->all();
    expect($sizes)->toContain('192x192', '512x512');

    foreach ($manifest['icons'] as $icon) {
        expect(public_path($icon['src']))->toBeFile();
    }
});

it('has a service worker that never caches', function () {
    $worker = file_get_contents(public_path('sw.js'));

    expect($worker)->not->toContain('cache.put')
        ->not->toContain('cache.add')
        ->not->toContain('respondWith');
});

it('links the manifest on the login page and offers the install button', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('apple-mobile-web-app-title', false)
        ->assertSee('installApp()', false);
});

it('links the manifest and shows the install button inside the app', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('manifest.webmanifest', false)
        ->assertSee('installApp()', false);
});
