<?php

namespace Tests\Unit;

use App\Bot\BotContext;
use App\Bot\Decision;
use App\Bot\DrawCalendar;
use App\Bot\PromptBuilder;
use App\Bot\RulesRepository;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Сборка промптов из resources/prompts/ и правил. Контейнер Laravel не нужен. */
class PromptBuilderTest extends TestCase
{
    private const EVAL_MOMENT = '2026-10-06 10:00'; // вторник, часы прогона

    /** @var list<string> временные копии промптов, удаляем после теста */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
        parent::tearDown();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function builder(?string $promptsDir = null): PromptBuilder
    {
        return new PromptBuilder(
            new RulesRepository(self::root().'/docs/assignment/promo-rules.md'),
            $promptsDir ?? self::root().'/resources/prompts',
        );
    }

    private static function msk(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, DrawCalendar::TZ);
    }

    /** Копия папки промптов; $transform правит содержимое каждого файла. */
    private function copyPrompts(?callable $transform = null): string
    {
        $dir = sys_get_temp_dir().'/prompts-'.uniqid();
        mkdir($dir);
        $this->tempDirs[] = $dir;
        foreach (glob(self::root().'/resources/prompts/*') as $file) {
            $content = (string) file_get_contents($file);
            file_put_contents($dir.'/'.basename($file), $transform ? $transform($content) : $content);
        }

        return $dir;
    }

    public function test_system_prompt_is_instructions_followed_by_rules(): void
    {
        $system = self::builder()->system();

        $this->assertStringStartsWith('# Кто ты', $system);
        $this->assertStringContainsString('# Правила акции (единственный источник ответов)', $system);
        $this->assertStringContainsString('1.1. Акция «Вкусная осень»', $system);
        $this->assertStringContainsString('12.1. ', $system);
        $this->assertLessThan(strpos($system, '1.1. Акция'), strpos($system, '# Правила акции'));
    }

    public function test_system_prompt_drops_fictional_disclaimer(): void
    {
        $system = self::builder()->system();

        // Курсивная преамбула «данные вымышлены» — не пункт правил; участнику её пересказывать нельзя
        $this->assertStringNotContainsString('вымышлены', $system);
        $this->assertStringNotContainsString('*Бренд', $system);
        $this->assertStringContainsString('# Правила стимулирующей акции «Вкусная осень»', $system); // заголовок самого документа остаётся
    }

    public function test_user_prompt_for_first_message_at_eval_moment(): void
    {
        $user = self::builder()->user(new BotContext('сколько чеков можно?', self::msk(self::EVAL_MOMENT)));

        $this->assertStringContainsString('Сегодня: вторник, 6 октября 2026, 10:00 МСК', $user);
        $this->assertStringContainsString('сегодня, 6 октября в 15:00', $user);
        $this->assertStringContainsString('Операторы сейчас на смене.', $user);
        $this->assertStringContainsString('Открытого обращения к оператору у участника нет.', $user);
        $this->assertStringContainsString('Это первое сообщение участника', $user);
        $this->assertStringContainsString('«сколько чеков можно?»', $user);
        $this->assertStringNotContainsString('{{', $user); // все подстановки сделаны
    }

    public function test_user_prompt_calendar_and_shift_follow_now(): void
    {
        $evening = self::builder()->user(new BotContext('x', self::msk('2026-10-06 20:00')));
        $fromUtc = self::builder()->user(new BotContext('x', CarbonImmutable::parse('2026-10-06 07:00', 'UTC')));

        $this->assertStringContainsString('Операторы сейчас не на смене.', $evening);
        $this->assertStringNotContainsString('Операторы сейчас на смене.', $evening);
        $this->assertStringContainsString('Ближайший еженедельный розыгрыш: 13 октября', $evening); // сегодняшний в 15:00 уже прошёл

        $this->assertStringContainsString('Сегодня: вторник, 6 октября 2026, 10:00 МСК', $fromUtc); // 07:00 UTC показано по Москве
        $this->assertStringContainsString('Операторы сейчас на смене.', $fromUtc);
    }

    public function test_user_prompt_with_history_and_open_ticket(): void
    {
        $ctx = new BotContext('а если 3 чека?', self::msk(self::EVAL_MOMENT), [
            ['author' => 'participant', 'text' => "сколько чеков\nв день?"],
            ['author' => 'bot', 'text' => 'Не больше 10.'],
            ['author' => 'operator', 'text' => 'Проверю ваш чек.'],
        ], 'Участник спрашивает про приз.');

        $user = self::builder()->user($ctx);

        $this->assertStringContainsString('У участника есть открытое обращение к оператору, его суть: Участник спрашивает про приз.', $user);
        $this->assertStringNotContainsString('Открытого обращения к оператору у участника нет.', $user);
        $this->assertStringContainsString("Участник: сколько чеков в день?\nБот: Не больше 10.\nОператор: Проверю ваш чек.", $user); // перенос строки внутри сообщения заменён пробелом
        $this->assertStringNotContainsString('Это первое сообщение участника', $user);
        $this->assertStringContainsString('«а если 3 чека?»', $user);
    }

    public function test_placeholders_inside_participant_message_are_not_expanded(): void
    {
        $user = self::builder()->user(new BotContext('{{calendar}} {{operators}}', self::msk(self::EVAL_MOMENT)));

        $this->assertStringContainsString('«{{calendar}} {{operators}}»', $user);
    }

    public function test_schema_is_parsed_and_matches_decision_actions(): void
    {
        $schema = self::builder()->schema();

        $this->assertSame(Decision::ACTIONS, $schema['properties']['action']['enum']);
        $this->assertEqualsCanonicalizing(['action', 'rule_refs', 'operator_summary', 'text'], $schema['required']);
    }

    public function test_version_is_eight_hex_chars_and_changes_with_template(): void
    {
        $version = self::builder()->version();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $version);
        $this->assertSame($version, self::builder($this->copyPrompts())->version()); // копия тех же файлов

        $changed = $this->copyPrompts(fn (string $content) => $content."\nЕщё одна строка.\n");

        $this->assertNotSame($version, self::builder($changed)->version());
    }

    public function test_crlf_templates_give_same_version_and_prompts(): void
    {
        $crlf = $this->copyPrompts(fn (string $content) => str_replace("\n", "\r\n", $content));
        $ctx = new BotContext('вопрос', self::msk(self::EVAL_MOMENT));

        $this->assertSame(self::builder()->version(), self::builder($crlf)->version());
        $this->assertSame(self::builder()->system(), self::builder($crlf)->system());
        $this->assertSame(self::builder()->user($ctx), self::builder($crlf)->user($ctx));
        $this->assertStringNotContainsString("\r", self::builder($crlf)->user($ctx));
    }
}
