<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Схема и решения описаны в docs/db-schema.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // У участника не больше одного открытого обращения.
        DB::statement('CREATE UNIQUE INDEX tickets_one_open_per_participant ON tickets (participant_id) WHERE closed_at IS NULL');

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author', 16); // participant, bot, operator
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('content_type', 16)->default('text'); // text, photo, other
            $table->text('text')->nullable();
            $table->timestampTz('created_at');
            // Telegram иногда присылает одно сообщение дважды.
            $table->unique(['participant_id', 'telegram_message_id']);
        });

        Schema::create('bot_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('reply_message_id')->nullable()->unique()->constrained('messages')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 16); // answer, operator, refuse, smalltalk
            $table->string('reason', 32); // model, invalid_refs, llm_error, no_text, media_to_ticket, start
            $table->jsonb('rule_refs')->nullable();
            $table->text('operator_summary')->nullable();
            $table->string('model')->nullable();
            $table->jsonb('model_output')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_decisions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('participants');
    }
};
