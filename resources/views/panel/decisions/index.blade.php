@extends('layouts.panel')
@use('App\Http\Panel\Format')

@section('content')
<div class="toolbar">
    <h1>Решения бота</h1>
    <span class="muted">оценка:</span>
    @foreach (['' => 'все', 'none' => 'без оценки', 'correct' => 'верно', 'wrong' => 'неверно', 'debatable' => 'спорно'] as $value => $label)
        <a href="{{ route('decisions.index', $value === '' ? [] : ['rating' => $value]) }}" @class(['active' => $filter === $value])>{{ $label }}</a>
    @endforeach
</div>

@if ($decisions->isEmpty())
    <p class="muted">Решений нет.</p>
@else
<table>
    <thead>
        <tr>
            <th>Время</th><th>Участник</th><th>Сообщение</th><th>Действие</th><th>Причина</th>
            <th>Модель</th><th>Пункты</th><th>Ответ бота</th><th>Обращение</th><th>Оценка оператора</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($decisions as $decision)
        <tr>
            <td>{{ Format::msk($decision->created_at) }}</td>
            <td>{{ $decision->message?->participant ? Format::participant($decision->message->participant) : '—' }}</td>
            <td>@if ($decision->message?->telegram_file_id)<a href="{{ route('messages.photo', $decision->message) }}" target="_blank">[фото]</a> @endif{{ $decision->message ? Format::body($decision->message) : '' }}</td>
            <td><span class="badge {{ $decision->action }}">{{ Format::action($decision->action) }}</span></td>
            <td>{{ Format::reason($decision->reason) }}</td>
            <td>{{ $decision->model ?: '—' }}</td>
            <td>{{ $decision->rule_refs ? implode(', ', $decision->rule_refs) : '—' }}</td>
            <td>{{ $decision->reply?->text ?: '—' }}</td>
            <td>@if ($decision->ticket_id)<a href="{{ route('tickets.show', $decision->ticket_id) }}">№{{ $decision->ticket_id }}</a>@else —@endif</td>
            <td>@include('panel.decisions._rating', ['decision' => $decision])</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="pager">
    @if ($decisions->previousPageUrl())<a href="{{ $decisions->previousPageUrl() }}">← новее</a>@endif
    <span class="muted">страница {{ $decisions->currentPage() }} из {{ $decisions->lastPage() }}</span>
    @if ($decisions->nextPageUrl())<a href="{{ $decisions->nextPageUrl() }}">старее →</a>@endif
</div>
@endif
@endsection
