<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BotDecision;
use App\Support\PromoClock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DecisionController extends Controller
{
    /** Все решения, новые сверху; ?rating=correct|wrong|debatable|none — фильтр по оценке оператора. */
    public function index(Request $request): View
    {
        $filter = (string) $request->query('rating', '');

        $decisions = BotDecision::query()
            ->with(['message.participant', 'reply', 'ratedBy'])
            ->when($filter === 'none', fn ($q) => $q->whereNull('rating'))
            ->when(in_array($filter, BotDecision::RATINGS, true), fn ($q) => $q->where('rating', $filter))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('panel.decisions.index', ['decisions' => $decisions, 'filter' => $filter]);
    }

    /** Оценка по шкале из ТЗ с комментарием. Одна оценка на решение, последняя побеждает; пустая снимает оценку вместе с комментарием. */
    public function rate(Request $request, BotDecision $decision): RedirectResponse
    {
        $data = $request->validate([
            'rating' => ['nullable', Rule::in(BotDecision::RATINGS)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = $data['rating'] ?? null;
        $decision->update([
            'rating' => $rating,
            'rating_comment' => $rating === null ? null : ($data['comment'] ?? null),
            'rated_by' => $rating === null ? null : $request->user()->id,
            'rated_at' => $rating === null ? null : PromoClock::now(),
        ]);

        // Назад на ту же страницу, к этому решению.
        return redirect(url()->previous().'#decision-'.$decision->id)
            ->with('success', $rating === null ? 'Оценка снята.' : 'Оценка сохранена.');
    }
}
