<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Фото участников и оценки решений оператором: docs/db-schema.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // file_id самого большого размера фото: панель подгружает картинку из Telegram по запросу, на диск не копирует.
            $table->string('telegram_file_id')->nullable();
        });

        Schema::table('bot_decisions', function (Blueprint $table) {
            // Оценка оператора по шкале из ТЗ: correct, wrong, debatable. Одна оценка на решение, последняя побеждает.
            $table->string('rating', 16)->nullable();
            $table->text('rating_comment')->nullable();
            $table->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rated_by');
            $table->dropColumn(['rating', 'rating_comment', 'rated_at']);
        });
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('telegram_file_id'));
    }
};
