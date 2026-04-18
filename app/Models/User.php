<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Issue a new API token (plain string returned once). Stores SHA-256 hex in api_token.
     */
    public function issueApiToken(): string
    {
        $plain = Str::random(64);
        $this->forceFill([
            'api_token' => hash('sha256', $plain),
        ])->save();

        return $plain;
    }

    /**
     * Resolve user from plain Bearer token value.
     */
    public static function findByApiToken(?string $plain): ?self
    {
        if ($plain === null || $plain === '') {
            return null;
        }

        return static::where('api_token', hash('sha256', $plain))->first();
    }
}
