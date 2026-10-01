<?php

namespace App\Telegram;

use App\Bot\BotContext;
use App\Bot\CardMasker;
use App\Bot\Decision;
use App\Bot\DecisionEngine;
use App\Bot\ReplyComposer;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Participant;
use App\Models\Ticket;
use App\Support\PromoClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Один апдейт Telegram → одно входящее сообщение → одно решение → один ответ.
 * Дубли отсекает уникальный индекс, номера карт прячутся до записи в базу.
 */
final class UpdateHandler
{
    private const HISTORY_LIMIT = 6;

    public function __construct(
        private readonly DecisionEngine $engine,
        private readonly TelegramClient $telegram,
    ) {}

    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $from = $message['from'] ?? null;
        if (! $message || ! $from || ! empty($from['is_bot']) || ($message['chat']['type'] ?? '') !== 'private') {
            return;
        }

        $participant = Participant::firstOrCreate(['telegram_user_id' => $from['id']], [
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
        ]);

        $raw = $message['text'] ?? $message['caption'] ?? null;
        $cardHidden = $raw !== null && CardMasker::contains($raw);
        try {
            // Вложенная транзакция = savepoint: нарушение уникальности не портит внешнюю транзакцию (например, в тестах).
            $inbound = DB::transaction(fn () => Message::create([
                'participant_id' => $participant->id,
                'author' => Message::AUTHOR_PARTICIPANT,
                'telegram_message_id' => $message['message_id'],
                'content_type' => isset($message['text']) ? 'text' : (isset($message['photo']) ? 'photo' : 'other'),
                'text' => $raw === null ? null : CardMasker::mask($raw),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Telegram прислал это сообщение повторно. Если прошлый раз процесс упал до ответа
            // (решения нет), дообрабатываем; иначе молча пропускаем.
            $inbound = $participant->messages()->where('telegram_message_id', $message['message_id'])->first();
            if ($inbound === null || $inbound->decision()->exists()) {
                return;
            }
        }

        $this->process($participant, $inbound, $cardHidden);
    }

    public function process(Participant $participant, Message $inbound, bool $cardHidden = false): void
    {
        $now = PromoClock::now();
        $open = $participant->openTicket()->first();
        $text = trim((string) $inbound->text);

        if (str_starts_with($text, '/start')) {
            $this->reply($participant, $inbound, __('bot.start'), Decision::SMALLTALK, 'start');

            return;
        }

        if ($text === '') {
            if ($open !== null) {
                $inbound->update(['ticket_id' => $open->id]);
                $this->reply($participant, $inbound, __('bot.photo_saved'), Decision::SMALLTALK, 'media_to_ticket', $open);
            } else {
                $this->reply($participant, $inbound, __('bot.no_text'), Decision::SMALLTALK, 'no_text');
            }

            return;
        }

        $decision = $this->engine->decide(new BotContext(
            $text,
            $now,
            $this->history($participant, $inbound),
            $open?->decisions()->whereNotNull('operator_summary')->latest('id')->value('operator_summary'),
        ));

        // Обращение берём заново после ответа модели: пока она думала, оператор мог закрыть старое.
        $ticket = $decision->needsOperator() ? $this->openTicket($participant, $now) : null;
        if ($ticket !== null) {
            $inbound->update(['ticket_id' => $ticket->id]);
        }

        $this->reply($participant, $inbound, ReplyComposer::compose($decision, $now, $cardHidden), $decision->action, $decision->reason, $ticket, $decision);
    }

    /** @return list<array{author: string, text: string}> */
    private function history(Participant $participant, Message $inbound): array
    {
        return $participant->messages()
            ->where('id', '<', $inbound->id)
            ->whereNotNull('text')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (Message $m) => ['author' => $m->author, 'text' => $m->text])
            ->values()
            ->all();
    }

    /** Возвращает открытое обращение, при необходимости открыв его; при гонке второе не появится — сработает частичный уникальный индекс. */
    private function openTicket(Participant $participant, CarbonImmutable $now): Ticket
    {
        DB::table('tickets')->insertOrIgnore([
            'participant_id' => $participant->id,
            'opened_at' => $now->format('Y-m-d H:i:sP'),
        ]);

        return $participant->openTicket()->firstOrFail();
    }

    private function reply(Participant $participant, Message $inbound, string $text, string $action, string $reason, ?Ticket $ticket = null, ?Decision $decision = null): void
    {
        $replyMessage = null;
        try {
            // telegram_message_id храним только у входящих: он нужен для отсечения дублей.
            $this->telegram->sendMessage($participant->telegram_user_id, $text);
            $replyMessage = Message::create([
                'participant_id' => $participant->id,
                'ticket_id' => $ticket?->id,
                'author' => Message::AUTHOR_BOT,
                'text' => $text,
            ]);
        } catch (TelegramException $e) {
            Log::error("Не удалось отправить ответ участнику {$participant->id}: ".$e->getMessage());
        }

        BotDecision::create([
            'message_id' => $inbound->id,
            'reply_message_id' => $replyMessage?->id,
            'ticket_id' => $ticket?->id,
            'action' => $action,
            'reason' => $reason,
            'rule_refs' => $decision?->ruleRefs ?? [],
            'operator_summary' => $decision?->operatorSummary,
            'model' => $decision?->model,
            'model_output' => $decision?->modelOutput,
        ]);
    }
}
