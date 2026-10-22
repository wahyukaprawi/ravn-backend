<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'activated_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function pendingActivation(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
            'status' => User::STATUS_PENDING_ACTIVATION,
            'activated_at' => null,
            'invitation_expires_at' => now()->addDays(7),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_INACTIVE,
            'deactivated_at' => now(),
        ]);
    }

    public function superAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('super_admin'));
    }

    public function hrAdmin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole('hr_admin'));
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
