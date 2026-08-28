<?php

use Illuminate\Support\Facades\Route;

uses(Tests\Feature\Programs\ProgramTestCase::class);

/**
 * Route-level regression guard: every endpoint declared by the Program module
 * must remain behind Sanctum. This deliberately derives the inventory from the
 * router instead of maintaining a fragile hand-written list.
 */
it('registers Sanctum authentication for every Program endpoint', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/programs'));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $this->assertContains(
            'auth:sanctum',
            $route->middleware(),
            "Route [{$route->uri()}] must require Sanctum authentication.",
        );
    }
});
