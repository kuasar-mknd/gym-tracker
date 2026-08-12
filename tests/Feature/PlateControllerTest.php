<?php

declare(strict_types=1);

use App\Models\Plate;
use App\Models\User;
use PHPUnit\Framework\Assert;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

describe('PlateController Index', function (): void {
    it('allows an authenticated user to view their plates', function (): void {
        $user = $this->user;
        Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

        Plate::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('plates.index'));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tools/PlateCalculator')
                ->has('plates')
            );
    });

    it('prevents guests from viewing plates', function (): void {
        $response = $this->get(route('plates.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('PlateController Store', function (): void {
    it('allows a user to create a plate', function (): void {
        $user = $this->user;
        Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

        $plateData = [
            'weight' => 20,
            'quantity' => 2,
        ];

        $response = $this->actingAs($user)->post(route('plates.store'), $plateData);

        $response->assertRedirect();

        $this->assertDatabaseHas('plates', [
            'user_id' => $user->id,
            'weight' => 20,
            'quantity' => 2,
        ]);
    });

    it('returns validation error if weight is missing', function (): void {
        $plateData = [
            'quantity' => 2,
        ];

        $response = $this->actingAs($this->user)->post(route('plates.store'), $plateData);

        $response->assertInvalid(['weight']);
        $this->assertDatabaseCount('plates', 0);
    });

    it('returns validation error if weight is invalid', function (): void {
        $plateData = [
            'weight' => 150, // Over max 100
            'quantity' => 2,
        ];

        $response = $this->actingAs($this->user)->post(route('plates.store'), $plateData);

        $response->assertInvalid(['weight']);
        $this->assertDatabaseCount('plates', 0);
    });

    it('returns validation error if quantity is invalid', function (): void {
        $plateData = [
            'weight' => 20,
            'quantity' => 0, // Under min 1
        ];

        $response = $this->actingAs($this->user)->post(route('plates.store'), $plateData);

        $response->assertInvalid(['quantity']);
        $this->assertDatabaseCount('plates', 0);
    });
});

describe('PlateController Update', function (): void {
    it('allows a user to update their own plate', function (): void {
        $user = $this->user;
        Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

        $plate = Plate::factory()->create(['user_id' => $user->id]);

        $updateData = [
            'weight' => 25,
            'quantity' => 4,
        ];

        $response = $this->actingAs($user)->put(route('plates.update', $plate), $updateData);

        $response->assertRedirect();

        $this->assertDatabaseHas('plates', [
            'id' => $plate->id,
            'weight' => 25,
            'quantity' => 4,
        ]);
    });

    it('returns validation error if updated quantity is invalid', function (): void {
        $user = $this->user;
        Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

        $plate = Plate::factory()->create(['user_id' => $user->id]);

        $updateData = [
            'weight' => 25,
            'quantity' => 150, // Over max 100
        ];

        $response = $this->actingAs($user)->put(route('plates.update', $plate), $updateData);

        $response->assertInvalid(['quantity']);
    });

    it('prevents a user from updating another user\'s plate', function (): void {
        $otherUser = User::factory()->create();
        $plate = Plate::factory()->create([
            'user_id' => $otherUser->id,
            'weight' => 10,
            'quantity' => 2,
        ]);

        $updateData = [
            'weight' => 25,
            'quantity' => 4,
        ];

        $response = $this->actingAs($this->user)->put(route('plates.update', $plate), $updateData);

        $response->assertForbidden();

        $this->assertDatabaseMissing('plates', [
            'id' => $plate->id,
            'weight' => 25,
            'quantity' => 4,
        ]);
    });
});

describe('PlateController Destroy', function (): void {
    it('allows a user to delete their own plate', function (): void {
        $user = $this->user;
        Assert::assertInstanceOf(User::class, $user, 'the beforeEach user fixture is missing');

        $plate = Plate::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->delete(route('plates.destroy', $plate));

        $response->assertRedirect();

        $this->assertDatabaseMissing('plates', [
            'id' => $plate->id,
        ]);
    });

    it('prevents a user from deleting another user\'s plate', function (): void {
        $otherUser = User::factory()->create();
        $plate = Plate::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->delete(route('plates.destroy', $plate));

        $response->assertForbidden();

        $this->assertDatabaseHas('plates', [
            'id' => $plate->id,
        ]);
    });
});
