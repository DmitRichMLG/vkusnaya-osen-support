<?php

namespace App\Bot;

/**
 * Правила акции из docs/assignment/promo-rules.md: полный текст для модели
 * и пункты по номерам для проверки ссылок. Файл может быть в CRLF.
 */
final class RulesRepository
{
    private ?string $text = null;

    /** @var array<string, string>|null номер пункта → текст */
    private ?array $clauses = null;

    public function __construct(private readonly string $path) {}

    public function text(): string
    {
        return $this->text ??= self::normalize((string) file_get_contents($this->path));
    }

    /** @return array<string, string> */
    public function clauses(): array
    {
        if ($this->clauses !== null) {
            return $this->clauses;
        }

        $clauses = [];
        $current = null;
        foreach (explode("\n", $this->text()) as $line) {
            $line = rtrim($line);
            if (preg_match('/^(\d+\.\d+)\.\s+(.*)$/u', $line, $m)) {
                $current = $m[1];
                $clauses[$current] = $m[2];

                continue;
            }
            if ($current === null) {
                continue;
            }
            if ($line === '' || str_starts_with($line, '#')) {
                $current = null;

                continue;
            }
            $clauses[$current] .= "\n".$line;
        }

        return $this->clauses = $clauses;
    }

    public function has(string $ref): bool
    {
        return isset($this->clauses()[self::normalizeRef($ref)]);
    }

    public function clause(string $ref): ?string
    {
        return $this->clauses()[self::normalizeRef($ref)] ?? null;
    }

    /**
     * @param  list<string>  $refs
     * @return list<string> нормализованные номера, которых нет в правилах
     */
    public function invalidRefs(array $refs): array
    {
        return array_values(array_filter(
            array_map(self::normalizeRef(...), $refs),
            fn (string $ref) => ! isset($this->clauses()[$ref]),
        ));
    }

    /** «п. 2.3», «2.3.» → «2.3» */
    public static function normalizeRef(string $ref): string
    {
        return trim((string) preg_replace('/[^\d.]/', '', $ref), '.');
    }

    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
