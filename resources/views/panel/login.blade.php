@extends('layouts.panel')

@section('content')
<div class="login card">
    <h1>Вход в панель операторов</h1>
    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        <label for="password">Пароль</label>
        <input id="password" type="password" name="password" required>
        <div class="actions"><button class="primary">Войти</button></div>
    </form>
</div>
@endsection
