<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\BotDecision;
use Illuminate\Contracts\View\View;

class DecisionController extends Controller
{
    public function index(): View
    {
        $decisions = BotDecision::query()
            ->with(['message.participant', 'reply'])
            ->orderByDesc('id')
            ->paginate(50);

        return view('panel.decisions.index', ['decisions' => $decisions]);
    }
}
