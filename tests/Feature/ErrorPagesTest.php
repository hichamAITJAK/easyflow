<?php

use Illuminate\Support\Facades\Route;

test('a missing route renders the custom 404 Inertia page instead of the default Laravel error page', function () {
    $response = $this->get('/this-route-does-not-exist');

    $response->assertStatus(404);
    $response->assertInertia(fn ($page) => $page
        ->component('errors/404')
        ->where('status', 404)
    );
});

test('a 403 abort renders the custom 403 Inertia page instead of the default Laravel error page', function () {
    Route::get('/__test-403', fn () => abort(403, 'Nope.'));

    $response = $this->get('/__test-403');

    $response->assertStatus(403);
    $response->assertInertia(fn ($page) => $page
        ->component('errors/403')
        ->where('status', 403)
        ->where('message', 'Nope.')
    );
});
