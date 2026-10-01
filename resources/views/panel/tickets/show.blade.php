@extends('layouts.panel')
@use('App\Http\Panel\Format')

@section('content')
<div class="toolbar">
    <h1>Обращение №{{ $ticket->id }}</h1>
    <a href="{{ route('tickets.index') }}">← к очереди</a>
</div>

<div class="card">
    <div>
        <strong>Участник:</strong> {{ Format::participant($ticket->participant) }}
        @if ($ticket->participant->username)<span class="muted">{{ '@'.$ticket->participant->username }}</span>@endif
        <span class="muted">· id {{ $ticket->participant->telegram_user_id }}</span>
    </div>
    <div><strong>Открыто:</strong> {{ Format::msk($ticket->opened_at) }}</div>
    <div>
        <strong>Статус:</strong>
        @if ($ticket->closed_at)
            закрыто {{ Format::msk($ticket->closed_at) }}{{ $ticket->closedBy ? ', '.$ticket->closedBy->name : '' }}
        @else
            открыто
        @endif
    </div>
</div>

<h2>Переписка</h2>
@forelse ($messages as $message)
    <div class="msg {{ $message->author }}">
        <div class="meta">
            {{ Format::author($message) }} · {{ Format::msk($message->created_at) }}
            @if ($message->ticket_id && $message->ticket_id !== $ticket->id)
                · обращение №{{ $message->ticket_id }}
            @endif
        </div>
        <div class="text">{!! nl2br(e(Format::body($message))) !!}</div>
        @if ($message->decision)
            @php($decision = $message->decision)
            <div class="decision">
                <div>
                    <span class="badge {{ $decision->action }}">{{ Format::action($decision->action) }}</span>
                    · причина: {{ Format::reason($decision->reason) }}
                    · модель: {{ $decision->model ?: '—' }}
                </div>
                @if ($decision->operator_summary)
                    <div><strong>Для оператора:</strong> {{ $decision->operator_summary }}</div>
                @endif
                @if ($decision->rule_refs)
                    <ul>
                    @foreach ($decision->rule_refs as $ref)
                        <li>
                            <strong>п. {{ $ref }}</strong> —
                            @if (($clause = $rules->clause($ref)) !== null)
                                {{ $clause }}
                            @else
                                <span class="muted">нет в правилах</span>
                            @endif
                        </li>
                    @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
@empty
    <p class="muted">Сообщений нет.</p>
@endforelse

@if ($ticket->isOpen())
    <h2>Ответить</h2>
    <form method="post" action="{{ route('tickets.reply', $ticket) }}" class="card">
        @csrf
        <textarea name="text" required maxlength="4000" placeholder="Текст ответа участнику">{{ old('text') }}</textarea>
        <div class="actions"><button class="primary">Отправить</button></div>
    </form>
    <form method="post" action="{{ route('tickets.close', $ticket) }}" onsubmit="return confirm('Закрыть обращение №{{ $ticket->id }}? Участник получит уведомление.')">
        @csrf
        <button class="danger">Закрыть обращение</button>
    </form>
@else
    <p class="muted">Обращение закрыто.</p>
@endif
@endsection
