<?php

namespace App\Console\Commands;

use App\Bot\BotContext;
use App\Bot\CardMasker;
use App\Bot\Decision;
use App\Bot\DecisionEngine;
use App\Bot\GeminiClient;
use App\Bot\PromptBuilder;
use App\Bot\ReplyComposer;
use App\Bot\RulesRepository;
use App\Support\PromoClock;
use Illuminate\Console\Command;

/**
 * Прогон 25 обращений из задания через ядро бота и сверка действия с эталоном.
 * Каждое обращение — новый участник без истории. Часы заморожены.
 */
class BotEval extends Command
{
    protected $signature = 'bot:eval
        {--now=2026-10-06 10:00 : Момент прогона по МСК}
        {--only= : Номера обращений через запятую}
        {--out= : Куда писать отчёт (по умолчанию docs/eval/<дата>.md)}
        {--models= : Список моделей через запятую вместо GEMINI_MODELS}';

    protected $description = 'Прогнать обращения из docs/assignment/requests.md и сверить с docs/expected-answers.md';

    public function handle(PromptBuilder $prompts): int
    {
        if ($this->option('models')) {
            config(['promo.gemini.models' => array_map('trim', explode(',', $this->option('models')))]);
            $this->laravel->forgetInstance(GeminiClient::class);
        }
        $engine = $this->laravel->make(DecisionEngine::class);

        PromoClock::freeze($this->option('now'));
        $now = PromoClock::now();

        $requests = self::parseRequests(base_path('docs/assignment/requests.md'));
        $expected = self::parseExpected(base_path('docs/expected-answers.md'));
        $only = $this->option('only') ? array_map('intval', explode(',', $this->option('only'))) : array_keys($requests);

        $rows = [];
        $score = ['верно' => 0, 'спорно' => 0, 'неверно' => 0];
        foreach ($only as $n) {
            $text = $requests[$n] ?? null;
            if ($text === null) {
                $this->warn("Обращения №{$n} нет в файле.");

                continue;
            }
            $decision = $engine->decide(new BotContext(CardMasker::mask($text), $now));
            $reply = ReplyComposer::compose($decision, $now, CardMasker::contains($text));
            $exp = $expected[$n] ?? null;
            $verdict = self::verdict($decision, $exp);
            $score[$verdict]++;

            $this->line(sprintf('%2d. %-9s эталон %-18s %-7s %-20s %s', $n, $decision->action, $exp['action'] ?? '—', $verdict, $decision->model ?? '—', implode(', ', $decision->ruleRefs)));
            $rows[] = [
                'n' => $n, 'request' => $text, 'decision' => $decision, 'reply' => $reply, 'expected' => $exp, 'verdict' => $verdict,
            ];
        }

        $this->newLine();
        $this->info(sprintf('Итог: верно %d, спорно %d, неверно %d из %d', $score['верно'], $score['спорно'], $score['неверно'], count($rows)));

        // date() не замораживается, в отличие от now(): имя файла — по реальному времени.
        $out = $this->option('out') ?: base_path('docs/eval/'.date('Y-m-d_Hi').'.md');
        @mkdir(dirname($out), 0777, true);
        file_put_contents($out, self::report($rows, $score, $now, $prompts->version()));
        $this->info("Отчёт: {$out}");

        return $score['неверно'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    public static function parseRequests(string $path): array
    {
        $requests = [];
        foreach (explode("\n", RulesRepository::normalize((string) file_get_contents($path))) as $line) {
            if (preg_match('/^\*\*(\d+)\.\*\*\s+(.+)$/u', trim($line), $m)) {
                $requests[(int) $m[1]] = trim($m[2]);
            }
        }

        return $requests;
    }

    /** @return array<int, array{action: string, also: ?string, refs: list<string>, summary: string}> */
    public static function parseExpected(string $path): array
    {
        $text = RulesRepository::normalize((string) file_get_contents($path));
        if (! preg_match('/<!-- eval-table:start -->(.*?)<!-- eval-table:end -->/s', $text, $m)) {
            return [];
        }
        $expected = [];
        foreach (explode("\n", $m[1]) as $line) {
            $cells = array_map('trim', explode('|', trim($line, "| \t")));
            if (count($cells) < 5 || ! ctype_digit($cells[0])) {
                continue;
            }
            $expected[(int) $cells[0]] = [
                'action' => $cells[1],
                'also' => $cells[2] === '—' ? null : $cells[2],
                'refs' => $cells[3] === '—' ? [] : array_map('trim', explode(',', $cells[3])),
                'summary' => $cells[4],
            ];
        }

        return $expected;
    }

    /** Эталон различает operator и operator+partial, для сверки действия это одно и то же. */
    private static function verdict(Decision $decision, ?array $expected): string
    {
        if ($expected === null) {
            return 'спорно';
        }
        $normalize = fn (?string $a) => $a === null ? null : explode('+', $a)[0];
        if ($decision->action === $normalize($expected['action'])) {
            return 'верно';
        }
        if ($decision->action === $normalize($expected['also'])) {
            return 'спорно';
        }

        return 'неверно';
    }

    private static function report(array $rows, array $score, $now, string $promptVersion): string
    {
        $models = implode(', ', config('promo.gemini.models'));
        $md = "# Прогон обращений\n\n";
        $md .= 'Дата прогона: '.(new \DateTimeImmutable('now', new \DateTimeZone(config('promo.timezone'))))->format('d.m.Y H:i').' МСК. ';
        $md .= 'Часы бота: '.$now->setTimezone(config('promo.timezone'))->format('d.m.Y H:i').' МСК. ';
        $md .= "Модели: {$models}. Версия промпта: `{$promptVersion}`.\n\n";
        $md .= sprintf("**Итог: верно %d, спорно %d, неверно %d из %d.** Оценка автоматическая: действие бота сравнивается с эталоном из `docs/expected-answers.md`; содержание ответов проверяет человек.\n\n",
            $score['верно'], $score['спорно'], $score['неверно'], count($rows));
        $md .= "| № | Ответ бота | Передано оператору (да/нет) | Оценка | Комментарий |\n|---|---|---|---|---|\n";
        foreach ($rows as $row) {
            /** @var Decision $d */
            $d = $row['decision'];
            $exp = $row['expected'];
            $comment = sprintf('Действие: %s (%s%s%s)%s. Эталон: %s%s.',
                $d->action,
                $d->reason,
                $d->model ? ', '.$d->model : '',
                $d->ruleRefs ? ', п. '.implode(', ', $d->ruleRefs) : '',
                $d->invalidRefs ? '; выдуманные пункты: '.implode(', ', $d->invalidRefs) : '',
                $exp['action'] ?? '—',
                $exp && $exp['refs'] ? ', п. '.implode(', ', $exp['refs']) : '',
            );
            if ($d->operatorSummary) {
                $comment .= ' Сводка оператору: '.$d->operatorSummary;
            }
            $md .= sprintf("| %d | %s | %s | %s | %s |\n",
                $row['n'],
                self::cell($row['reply']),
                $d->needsOperator() ? 'да' : 'нет',
                $row['verdict'],
                self::cell($comment),
            );
        }
        $md .= "\n## Обращения\n\n";
        foreach ($rows as $row) {
            $md .= sprintf("%d. %s\n", $row['n'], $row['request']);
        }

        return $md;
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', '<br>'], trim($text));
    }
}
