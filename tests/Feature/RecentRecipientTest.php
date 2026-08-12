<?php

namespace Tests\Feature;

use App\Models\RecentRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentRecipientTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_save_a_valid_recent_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/recent-recipients', ['phone' => '07017844031'])
            ->assertSuccessful()
            ->assertJsonPath('data.phone', '07017844031');

        $this->assertDatabaseHas('recent_recipients', ['user_id' => $user->id, 'phone' => '07017844031']);
    }

    public function test_duplicate_number_is_not_created_and_is_moved_to_the_top(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/recent-recipients', ['phone' => '07017844031']);
        $this->travel(1)->second();
        $this->postJson('/api/recent-recipients', ['phone' => '08052551516']);
        $this->travel(1)->second();
        $this->postJson('/api/recent-recipients', ['phone' => '07017844031']);

        $this->assertSame(2, RecentRecipient::where('user_id', $user->id)->count());
        $this->getJson('/api/recent-recipients')
            ->assertSuccessful()
            ->assertJsonPath('data.0.phone', '07017844031')
            ->assertJsonPath('data.1.phone', '08052551516');
    }

    public function test_history_is_limited_to_ten_numbers(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        foreach (range(0, 10) as $index) {
            $this->postJson('/api/recent-recipients', ['phone' => '080000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
            $this->travel(1)->second();
        }

        $this->assertSame(10, RecentRecipient::where('user_id', $user->id)->count());
        $this->assertDatabaseMissing('recent_recipients', ['user_id' => $user->id, 'phone' => '08000000000']);
    }

    public function test_users_cannot_read_or_remove_another_users_numbers(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recipient = RecentRecipient::create([
            'user_id' => $owner->id,
            'phone' => '07017844031',
            'last_used_at' => now(),
        ]);

        $this->actingAs($other)->getJson('/api/recent-recipients')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
        $this->deleteJson('/api/recent-recipients/'.$recipient->id)->assertNotFound();
        $this->assertDatabaseHas('recent_recipients', ['id' => $recipient->id]);
    }

    public function test_invalid_or_partial_numbers_are_not_saved(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/recent-recipients', ['phone' => '0701'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('recent_recipients', 0);
    }
}
