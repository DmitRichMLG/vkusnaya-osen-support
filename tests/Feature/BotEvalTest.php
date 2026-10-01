<?php

namespace Tests\Feature;

use App\Console\Commands\BotEval;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotEvalTest extends TestCase
{
    public function test_requests_and_expected_answers_are_parsed(): void
    {
        $requests = BotEval::parseRequests(base_path('docs/assignment/requests.md'));
        $expected = BotEval::parseExpected(base_path('docs/expected-answers.md'));

        $this->assertCount(25, $requests);
        $this->assertCount(25, $expected);
        $this->assertSame('кефир участвует?', $requests[2]);
        $this->assertSame('answer', $expected[2]['action']);
        $this->assertSame(['4.2'], $expected[2]['refs']);
        $this->assertSame('operator+partial', $expected[12]['action']);
        $this->assertSame('answer', $expected[12]['also']);
        $this->assertSame([], $expected[23]['refs']);
    }

    public function test_command_writes_report_and_compares_actions(): void
    {
        config(['promo.gemini.models' => ['model-a']]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(
            ['action' => 'answer', 'text' => 'Нет, кефир не участвует.', 'operator_summary' => '', 'rule_refs' => ['4.2']],
        )]]]]]])]);
        $out = sys_get_temp_dir().'/eval-test.md';

        $this->artisan('bot:eval', ['--only' => '2,23', '--out' => $out])
            ->expectsOutputToContain('Итог: верно 1, спорно 0, неверно 1 из 2')
            ->assertExitCode(1);

        $report = file_get_contents($out);
        $this->assertStringContainsString('| 2 | Нет, кефир не участвует. | нет | верно |', $report);
        $this->assertStringContainsString('| 23 | Нет, кефир не участвует. | нет | неверно |', $report);
        $this->assertStringContainsString('Часы бота: 06.10.2026 10:00 МСК', $report);
        unlink($out);
    }
}
