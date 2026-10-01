<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BotDecision;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Определения метрик — docs/design.md §6. Всё считается запросами, строки в PHP не грузим. */
class StatsController extends Controller
{
    public function __invoke(): View
    {
        // Вопросы к боту: smalltalk и служебные ответы не считаем.
        $rows = BotDecision::query()
            ->where('action', '!=', BotDecision::ACTION_SMALLTALK)
            ->selectRaw('action, reason, count(*) as n')
            ->groupBy('action', 'reason')
            ->get();

        $actions = [BotDecision::ACTION_ANSWER => 0, BotDecision::ACTION_OPERATOR => 0, BotDecision::ACTION_REFUSE => 0];
        $operatorReasons = ['model' => 0, 'invalid_refs' => 0, 'llm_error' => 0];
        foreach ($rows as $row) {
            $actions[$row->action] = ($actions[$row->action] ?? 0) + (int) $row->n;
            if ($row->action === BotDecision::ACTION_OPERATOR) {
                $operatorReasons[$row->reason] = ($operatorReasons[$row->reason] ?? 0) + (int) $row->n;
            }
        }
        $total = array_sum($actions);

        // Оценки операторов по тем же вопросам (без smalltalk); «без оценки» — остаток.
        $ratings = BotDecision::query()
            ->where('action', '!=', BotDecision::ACTION_SMALLTALK)
            ->whereNotNull('rating')
            ->selectRaw('rating, count(*) as n')
            ->groupBy('rating')
            ->pluck('n', 'rating')
            ->map(fn ($n) => (int) $n)
            ->all();
        $ratings = array_merge(array_fill_keys(BotDecision::RATINGS, 0), $ratings);
        $ratings['none'] = $total - array_sum($ratings);

        $tickets = [
            'total' => Ticket::count(),
            'open' => Ticket::whereNull('closed_at')->count(),
            'closed' => Ticket::whereNotNull('closed_at')->count(),
            'waiting' => Ticket::whereNull('closed_at')
                ->whereDoesntHave('messages', fn ($q) => $q->where('author', Message::AUTHOR_OPERATOR))
                ->count(),
        ];

        // Первый ответ оператора в обращении минус opened_at, в секундах; среднее по обращениям с ответом.
        $firstReplies = DB::table('tickets')
            ->join('messages', function (JoinClause $join) {
                $join->on('messages.ticket_id', '=', 'tickets.id')
                    ->where('messages.author', '=', Message::AUTHOR_OPERATOR);
            })
            ->groupBy('tickets.id')
            ->selectRaw('extract(epoch from (min(messages.created_at) - tickets.opened_at)) as seconds');
        $avgSeconds = DB::query()->fromSub($firstReplies, 'first_replies')->avg('seconds');

        return view('panel.stats', [
            'total' => $total,
            'actions' => $actions,
            'ratings' => $ratings,
            'operatorReasons' => $operatorReasons,
            'tickets' => $tickets,
            'avgFirstReplySeconds' => $avgSeconds === null ? null : (int) round((float) $avgSeconds),
        ]);
    }
}
