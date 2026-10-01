<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вкусная осень — панель</title>
@yield('head')
<style>
*{box-sizing:border-box}
body{margin:0;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#222;background:#f6f6f4}
a{color:#1a5fb4}
header{background:#fff;border-bottom:1px solid #ddd;padding:10px 20px;display:flex;align-items:center;gap:24px;flex-wrap:wrap}
header .brand{font-weight:700}
header nav a{margin-right:16px}
header nav a.active{color:#222;font-weight:600;text-decoration:none}
header .user{margin-left:auto;display:flex;align-items:center;gap:10px;color:#666}
main{max-width:1100px;margin:0 auto;padding:20px}
h1{font-size:20px;margin:0 0 16px}
h2{font-size:17px;margin:20px 0 10px}
.flash{padding:10px 14px;border-radius:6px;margin-bottom:14px}
.flash.success{background:#e3f6e5;color:#1d5f28}
.flash.warning{background:#fff4d6;color:#7a5200}
.flash.error{background:#fde8e8;color:#8a1c1c}
table{width:100%;border-collapse:collapse;background:#fff;margin-bottom:16px}
th,td{padding:8px 10px;border-bottom:1px solid #e5e5e5;text-align:left;vertical-align:top}
th{background:#f0f0ee;font-weight:600}
td.num{text-align:right;white-space:nowrap}
.badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:13px;background:#eee;white-space:nowrap}
.badge.answer{background:#e3f6e5}
.badge.operator{background:#fff4d6}
.badge.refuse{background:#fde8e8}
.card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px 14px;margin-bottom:14px}
.msg{margin-bottom:12px}
.msg .meta{font-size:13px;color:#666;margin-bottom:3px}
.msg .text{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;overflow-wrap:anywhere}
.msg.bot .text{background:#f0f4fa}
.msg.operator .text{background:#eef8ef}
.decision{margin:6px 0 0 24px;padding:8px 12px;border-left:3px solid #c9c9c9;background:#fafaf8;font-size:14px}
.decision ul{margin:4px 0 0;padding-left:18px}
.muted{color:#777}
.toolbar{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.toolbar h1{margin:0}
.pager{display:flex;gap:16px;margin-top:8px}
form.inline{display:inline}
label{display:block;margin:10px 0 4px}
textarea,input[type=email],input[type=password]{width:100%;padding:8px;font:inherit;border:1px solid #bbb;border-radius:6px}
textarea{min-height:110px}
button{padding:7px 16px;font:inherit;border:1px solid #999;border-radius:6px;background:#fff;cursor:pointer}
button.primary{background:#1a5fb4;color:#fff;border-color:#1a5fb4}
button.danger{color:#8a1c1c}
.actions{display:flex;gap:12px;align-items:center;margin-top:10px}
.login{max-width:380px;margin:60px auto}
.msg img.photo{display:block;max-width:320px;max-height:320px;border-radius:6px;margin-bottom:6px}
.toolbar a.active{color:#222;font-weight:600;text-decoration:none}
.rate{margin-top:8px}
form.rating{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
form.rating select,form.rating input{padding:4px 6px;font:inherit;border:1px solid #bbb;border-radius:6px}
form.rating input{width:180px}
form.rating button{padding:4px 10px}
form.rating select.correct{background:#e3f6e5}
form.rating select.wrong{background:#fde8e8}
form.rating select.debatable{background:#fff4d6}
</style>
</head>
<body>
<header>
    <span class="brand">Вкусная осень</span>
    @auth
        <nav>
            <a href="{{ route('tickets.index') }}" @class(['active' => request()->routeIs('tickets.*')])>Очередь ({{ \App\Models\Ticket::whereNull('closed_at')->count() }})</a>
            <a href="{{ route('decisions.index') }}" @class(['active' => request()->routeIs('decisions.*')])>Решения бота</a>
            <a href="{{ route('stats') }}" @class(['active' => request()->routeIs('stats')])>Статистика</a>
        </nav>
        <div class="user">
            <span>{{ auth()->user()->name }}</span>
            <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button>Выйти</button></form>
        </div>
    @endauth
</header>
<main>
    @foreach (['success', 'warning', 'error'] as $kind)
        @if (session($kind))
            <div class="flash {{ $kind }}">{{ session($kind) }}</div>
        @endif
    @endforeach
    @if ($errors->any())
        <div class="flash error">{{ $errors->first() }}</div>
    @endif

    @yield('content')
</main>
</body>
</html>
