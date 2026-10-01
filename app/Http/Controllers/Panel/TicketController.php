<?php

namespace App\Http\Controllers\Panel;

use App\Bot\RulesRepository;
use App\Http\Controllers\Controller;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Ticket;
use App\Support\PromoClock;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /** Очередь: открытые старые сверху, ?status=closed — закрытые новые сверху. */
    public function index(Request $request): View
    {
        $closed = $request->query('status') === 'closed';

        $tickets = Ticket::query()
            ->with('participant')
            ->when(
                $closed,
                fn ($q) => $q->whereNotNull('closed_at')->orderByDesc('closed_at')->orderByDesc('id'),
                fn ($q) => $q->whereNull('closed_at')->orderBy('opened_at')->orderBy('id'),
            )
            // «Отвечено», если оператор уже писал в это обращение.
            ->withExists(['messages as answered' => fn ($q) => $q->where('author', Message::AUTHOR_OPERATOR)])
            // Суть — из последнего решения бота по этому обращению.
            ->addSelect(['summary' => BotDecision::select('operator_summary')
                ->whereColumn('ticket_id', 'tickets.id')
                ->orderByDesc('id')
                ->limit(1)])
            ->get();

        return view('panel.tickets.index', [
            'tickets' => $tickets,
            'closed' => $closed,
            'now' => PromoClock::now(),
        ]);
    }

    public function show(Ticket $ticket, RulesRepository $rules): View
    {
        $ticket->load(['participant', 'closedBy']);

        // Вся переписка участника, не только это обращение: оператору нужен контекст.
        $messages = Message::query()
            ->where('participant_id', $ticket->participant_id)
            ->with(['decision', 'operator'])
            ->orderBy('id')
            ->get();

        return view('panel.tickets.show', [
            'ticket' => $ticket,
            'messages' => $messages,
            'rules' => $rules,
        ]);
    }

    public function reply(Request $request, Ticket $ticket, TelegramClient $telegram): RedirectResponse
    {
        if (! $ticket->isOpen()) {
            return back()->with('error', 'Обращение закрыто, ответить нельзя.');
        }

        $text = $request->validate(['text' => ['required', 'string', 'max:4000']])['text'];

        // Сначала в Telegram: если не дошло, в базе ничего не остаётся.
        try {
            $telegram->sendMessage((int) $ticket->participant->telegram_user_id, __('bot.operator_reply', ['text' => $text]));
        } catch (TelegramException $e) {
            return back()->withInput()->with('error', 'Не удалось отправить в Telegram: '.$e->getMessage());
        }

        Message::create([
            'participant_id' => $ticket->participant_id,
            'ticket_id' => $ticket->id,
            'author' => Message::AUTHOR_OPERATOR,
            'operator_id' => $request->user()->id,
            'content_type' => 'text',
            'text' => $text,
            'created_at' => PromoClock::now(),
        ]);

        return back()->with('success', 'Ответ отправлен.');
    }

    public function close(Request $request, Ticket $ticket, TelegramClient $telegram): RedirectResponse
    {
        if (! $ticket->isOpen()) {
            return redirect()->route('tickets.index')->with('error', "Обращение №{$ticket->id} уже закрыто.");
        }

        $ticket->update(['closed_at' => PromoClock::now(), 'closed_by' => $request->user()->id]);

        // Уведомление участнику: его сбой не отменяет закрытие.
        try {
            $telegram->sendMessage((int) $ticket->participant->telegram_user_id, __('bot.ticket_closed'));
        } catch (TelegramException $e) {
            return redirect()->route('tickets.index')
                ->with('warning', "Обращение №{$ticket->id} закрыто, но уведомить участника не удалось: ".$e->getMessage());
        }

        Message::create([
            'participant_id' => $ticket->participant_id,
            'ticket_id' => $ticket->id,
            'author' => Message::AUTHOR_BOT,
            'content_type' => 'text',
            'text' => __('bot.ticket_closed'),
            'created_at' => PromoClock::now(),
        ]);

        return redirect()->route('tickets.index')->with('success', "Обращение №{$ticket->id} закрыто.");
    }
}
