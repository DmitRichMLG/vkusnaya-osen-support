<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Participant extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    // Формат со смещением: московское время не превратится в UTC со сдвигом.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function openTicket(): HasOne
    {
        return $this->hasOne(Ticket::class)->whereNull('closed_at');
    }
}
