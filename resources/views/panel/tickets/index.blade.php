@extends('layouts.panel')
@use('App\Http\Panel\Format')

@section('head')
<meta http-equiv="refresh" content="30">
@endsection

@section('content')
<div class="toolbar">
    <h1>{{ $closed ? 'Закрытые обращения' : 'Очередь обращений' }}</h1>
    <span>
        @if ($closed)
            <a href="{{ route('tickets.index') }}">Открытые</a> · Закрытые
        @else
            Открытые · <a href="{{ route('tickets.index', ['status' => 'closed']) }}">Закрытые</a>
        @endif
    </span>
</div>

@if ($tickets->isEmpty())
    <p class="muted">Обращений нет.</p>
@else
<table>
    <thead>
        <tr><th>№</th><th>Участник</th><th>Открыто</th><th>Ждёт</th><th>Суть</th></tr>
    </thead>
    <tbody>
    @foreach ($tickets as $ticket)
        <tr>
            <td><a href="{{ route('tickets.show', $ticket) }}">№{{ $ticket->id }}</a></td>
            <td>{{ Format::participant($ticket->participant) }}</td>
            <td>{{ Format::msk($ticket->opened_at) }}</td>
            <td>
                @if ($ticket->closed_at)
                    закрыто {{ Format::msk($ticket->closed_at) }}
                @elseif ($ticket->answered)
                    отвечено
                @else
                    {{ Format::duration((int) $ticket->opened_at->diffInSeconds($now)) }}
                @endif
            </td>
            <td>{{ $ticket->summary ?: '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
@endsection
