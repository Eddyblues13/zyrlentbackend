<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a user can get, generate, and revoke their API key', function () {
    $user = User::factory()->create();

    // 1. Get initial key (should be null)
    Sanctum::actingAs($user);
    $response = $this->getJson('/api/user/api-key');
    $response->assertStatus(200)->assertJson(['api_key' => null]);

    // 2. Generate a key
    $response = $this->postJson('/api/user/api-key/generate');
    $response->assertStatus(200);
    $key = $response->json('api_key');
    expect($key)->toStartWith('zyr_api_');

    // 3. Verify user model updated
    $user->refresh();
    expect($user->api_key)->toBe($key);

    // 4. Get key again
    $response = $this->getJson('/api/user/api-key');
    $response->assertStatus(200)->assertJson(['api_key' => $key]);

    // 5. Revoke key
    $response = $this->deleteJson('/api/user/api-key/revoke');
    $response->assertStatus(200);

    // 6. Verify key is null
    $user->refresh();
    expect($user->api_key)->toBeNull();
});

test('a developer cannot access endpoints without a valid API key', function () {
    $response = $this->getJson('/api/v1/user/profile');
    $response->assertStatus(401);

    $response = $this->getJson('/api/v1/user/profile?api_key=invalid_key');
    $response->assertStatus(401);
});

test('a developer can access the profile endpoint with a valid API key in header', function () {
    $user = User::factory()->create(['api_key' => 'zyr_api_test123']);

    $response = $this->getJson('/api/v1/user/profile', [
        'Authorization' => 'Bearer zyr_api_test123'
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'email' => $user->email,
            'rating' => 5,
        ])
        ->assertJsonStructure(['email', 'balance', 'rating']);
});

test('a developer can access the profile endpoint with a valid API key in query string', function () {
    $user = User::factory()->create(['api_key' => 'zyr_api_test456']);

    $response = $this->getJson('/api/v1/user/profile?api_key=zyr_api_test456');

    $response->assertStatus(200)
        ->assertJson([
            'email' => $user->email,
        ]);
});
