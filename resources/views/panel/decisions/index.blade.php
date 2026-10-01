@extends('layouts.panel')
@use('App\Http\Panel\Format')

@section('content')
<h1>Решения бота</h1>

@if ($decisions->isEmpty())
    <p class="muted">Решений пока нет.</p>
@else
<table>
    <thead>
        <tr>
            <th>Время</th><th>Участник</th><th>Сообщение</th><th>Действие</th><th>Причина</th>
            <th>Модель</th><th>Пункты</th><th>Ответ бота</th><th>Обращение</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($decisions as $decision)
        <tr>
            <td>{{ Format::msk($decision->created_at) }}</td>
            <td>{{ $decision->message?->participant ? Format::participant($decision->message->participant) : '—' }}</td>
            <td>{{ $decision->message?->text }}</td>
            <td><span class="badge {{ $decision->action }}">{{ Format::action($decision->action) }}</span></td>
            <td>{{ Format::reason($decision->reason) }}</td>
            <td>{{ $decision->model ?: '—' }}</td>
            <td>{{ $decision->rule_refs ? implode(', ', $decision->rule_refs) : '—' }}</td>
            <td>{{ $decision->reply?->text ?: '—' }}</td>
            <td>@if ($decision->ticket_id)<a href="{{ route('tickets.show', $decision->ticket_id) }}">№{{ $decision->ticket_id }}</a>@else —@endif</td>
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
