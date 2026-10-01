<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotDecision extends Model
{
    const UPDATED_AT = null;

    public const ACTION_ANSWER = 'answer';

    public const ACTION_OPERATOR = 'operator';

    public const ACTION_REFUSE = 'refuse';

    public const ACTION_SMALLTALK = 'smalltalk';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'rule_refs' => 'array',
            'model_output' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_message_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
