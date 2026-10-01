<?php

namespace Tests\Unit;

use App\Bot\RulesRepository;
use PHPUnit\Framework\TestCase;

class RulesRepositoryTest extends TestCase
{
    private function rules(): RulesRepository
    {
        return new RulesRepository(dirname(__DIR__, 2).'/docs/assignment/promo-rules.md');
    }

    public function test_all_45_clauses_are_parsed(): void
    {
        $clauses = $this->rules()->clauses();

        $this->assertCount(45, $clauses);
        $this->assertArrayHasKey('1.1', $clauses);
        $this->assertArrayHasKey('12.1', $clauses);
        $this->assertStringStartsWith('Чек отклоняется, если:', $clauses['6.5']);
        $this->assertStringContainsString('превышен лимит', $clauses['6.5']);
    }

    public function test_refs_are_normalized_and_validated(): void
    {
        $rules = $this->rules();

        $this->assertTrue($rules->has('п. 2.3'));
        $this->assertTrue($rules->has('2.3.'));
        $this->assertFalse($rules->has('2.9'));
        $this->assertSame(['2.9', '13.1'], $rules->invalidRefs(['2.3', '2.9', '13.1']));
    }

    public function test_crlf_file_is_parsed_the_same(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rules');
        file_put_contents($path, str_replace("\n", "\r\n", file_get_contents(dirname(__DIR__, 2).'/docs/assignment/promo-rules.md')));

        $this->assertSame($this->rules()->clauses(), (new RulesRepository($path))->clauses());
        unlink($path);
    }
}
