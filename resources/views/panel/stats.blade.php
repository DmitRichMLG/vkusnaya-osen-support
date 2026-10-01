@extends('layouts.panel')
@use('App\Http\Panel\Format')

@section('content')
<h1>Статистика</h1>

@php($pct = fn (int $n) => $total > 0 ? round($n * 100 / $total).'%' : '—')

<h2>Вопросы к боту</h2>
<table>
    <tr><th>Всего вопросов</th><td class="num">{{ $total }}</td><td class="num"></td></tr>
    <tr><th>Ответил сам</th><td class="num">{{ $actions['answer'] }}</td><td class="num">{{ $pct($actions['answer']) }}</td></tr>
    <tr><th>Передано оператору</th><td class="num">{{ $actions['operator'] }}</td><td class="num">{{ $pct($actions['operator']) }}</td></tr>
    <tr><th class="muted">— по решению модели</th><td class="num">{{ $operatorReasons['model'] }}</td><td class="num">{{ $pct($operatorReasons['model']) }}</td></tr>
    <tr><th class="muted">— пункты не прошли проверку</th><td class="num">{{ $operatorReasons['invalid_refs'] }}</td><td class="num">{{ $pct($operatorReasons['invalid_refs']) }}</td></tr>
    <tr><th class="muted">— ИИ не ответил</th><td class="num">{{ $operatorReasons['llm_error'] }}</td><td class="num">{{ $pct($operatorReasons['llm_error']) }}</td></tr>
    <tr><th>Отказ</th><td class="num">{{ $actions['refuse'] }}</td><td class="num">{{ $pct($actions['refuse']) }}</td></tr>
</table>
<p class="muted">Служебные ответы (приветствие, «я на связи») не считаются.</p>

<h2>Оценки операторов</h2>
<table>
    <tr><th>Верно</th><td class="num">{{ $ratings['correct'] }}</td><td class="num">{{ $pct($ratings['correct']) }}</td></tr>
    <tr><th>Неверно</th><td class="num">{{ $ratings['wrong'] }}</td><td class="num">{{ $pct($ratings['wrong']) }}</td></tr>
    <tr><th>Спорно</th><td class="num">{{ $ratings['debatable'] }}</td><td class="num">{{ $pct($ratings['debatable']) }}</td></tr>
    <tr><th>Без оценки</th><td class="num">{{ $ratings['none'] }}</td><td class="num">{{ $pct($ratings['none']) }}</td></tr>
</table>
<p class="muted">Оценки ставятся на странице «Решения бота» и в обращении; считаются по тем же вопросам, что и выше, доля — от всех вопросов.</p>

<h2>Обращения</h2>
<table>
    <tr><th>Всего</th><td class="num">{{ $tickets['total'] }}</td></tr>
    <tr><th>Открыто</th><td class="num">{{ $tickets['open'] }}</td></tr>
    <tr><th>Закрыто</th><td class="num">{{ $tickets['closed'] }}</td></tr>
    <tr><th>Ждут первого ответа оператора</th><td class="num">{{ $tickets['waiting'] }}</td></tr>
    <tr><th>Среднее время первого ответа оператора</th><td class="num">{{ $avgFirstReplySeconds === null ? '—' : Format::duration($avgFirstReplySeconds) }}</td></tr>
</table>
<p class="muted">Время первого ответа: от открытия обращения до первого сообщения оператора в нём, календарные часы.</p>
@endsection
