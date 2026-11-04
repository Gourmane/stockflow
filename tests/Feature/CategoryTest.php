<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_categories(): void
    {
        $response = $this->getJson('/api/categories');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data',
                 ]);
    }

    public function test_can_create_category(): void
{
    $response = $this->postJson('/api/categories', [
        'name' => 'Serveurs',
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('data.name', 'Serveurs');

    $this->assertDatabaseHas('categories', [
        'name' => 'Serveurs',
    ]);
}
}

