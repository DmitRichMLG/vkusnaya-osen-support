<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    const UPDATED_AT = null;

    public const AUTHOR_PARTICIPANT = 'participant';

    public const AUTHOR_BOT = 'bot';

    public const AUTHOR_OPERATOR = 'operator';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** Решение бота по этому входящему сообщению. */
    public function decision(): HasOne
    {
        return $this->hasOne(BotDecision::class, 'message_id');
    }
}
