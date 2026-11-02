<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['name', 'key', 'enabled', 'description'];

    protected $casts = ['enabled' => 'boolean'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isEnabledFor(?User $user): bool
    {
        if (! $user || ! $this->isEnabled()) {
            return false;
        }

        return $this->users()->where('user_id', $user->id)->exists();
    }
}
