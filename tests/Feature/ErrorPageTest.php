<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/_test/error/{status}', function (int $status): never {
        if ($status === 500) {
            throw new RuntimeException('Boom');
        }

        abort($status);
    });
});

it('renders the branded error page for each handled status', function (int $status) {
    $this->get("/_test/error/{$status}")
        ->assertStatus($status)
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->component('ErrorPage')
            ->where('status', $status));
})->with([403, 404, 419, 500, 503]);

it('renders it for an unknown URL', function () {
    $this->get('/tidak-ada-halaman-ini')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->component('ErrorPage')->where('status', 404));
});

it('renders it when a user lacks the permission for a page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.departments.index'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->component('ErrorPage')->where('status', 403));
});

it('leaves the debug error page alone while debugging', function () {
    config(['app.debug' => true]);

    $response = $this->get('/_test/error/500')->assertInternalServerError();

    expect($response->headers->get('X-Inertia'))->toBeNull()
        ->and($response->getContent())->not->toContain('"component":"ErrorPage"');
});

it('keeps JSON errors for JSON requests', function () {
    $this->getJson('/_test/error/404')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});
