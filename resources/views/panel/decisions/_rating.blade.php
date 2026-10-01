@use('App\Http\Panel\Format')
@use('App\Models\BotDecision')
{{-- Оценка решения оператором: шкала из ТЗ, комментарий, кто и когда. Последняя оценка побеждает, пустая снимает её. --}}
<form method="post" action="{{ route('decisions.rate', $decision) }}" class="rating" id="decision-{{ $decision->id }}">
    @csrf
    <select name="rating" class="{{ $decision->rating }}">
        <option value="">без оценки</option>
        @foreach (BotDecision::RATINGS as $value)
            <option value="{{ $value }}" @selected($decision->rating === $value)>{{ Format::rating($value) }}</option>
        @endforeach
    </select>
    <input type="text" name="comment" maxlength="2000" placeholder="комментарий" value="{{ $decision->rating_comment }}">
    <button>Сохранить</button>
    @if ($decision->rating)
        <span class="muted">{{ $decision->ratedBy?->name ?? 'оператор' }}, {{ Format::msk($decision->rated_at) }}</span>
    @endif
</form>
