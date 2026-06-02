<?php

namespace Bschmitt\Amqp\Test\Unit\Support;

use Bschmitt\Amqp\Support\MessageDiff;
use PHPUnit\Framework\TestCase;

class MessageDiffTest extends TestCase
{
    public function testIdenticalJsonBodiesReportNoChanges(): void
    {
        $diff = (new MessageDiff())->diffBody(
            '{"a":1,"b":2}',
            '{"b":2,"a":1}'
        );

        $this->assertTrue($diff['identical']);
        $this->assertSame('json', $diff['format']);
        $this->assertSame([], $diff['changes']);
    }

    public function testJsonBodyDetectsAddedRemovedAndChangedKeys(): void
    {
        $diff = (new MessageDiff())->diffBody(
            '{"a":1,"b":2,"c":3}',
            '{"a":1,"b":99,"d":4}'
        );

        $this->assertFalse($diff['identical']);
        $ops = $this->indexByPath($diff['changes']);

        $this->assertSame('changed', $ops['/b']['op']);
        $this->assertSame(2, $ops['/b']['from']);
        $this->assertSame(99, $ops['/b']['to']);
        $this->assertSame('removed', $ops['/c']['op']);
        $this->assertSame('added', $ops['/d']['op']);
    }

    public function testNestedJsonPaths(): void
    {
        $diff = (new MessageDiff())->diffBody(
            '{"items":[{"total":1},{"total":2}]}',
            '{"items":[{"total":1},{"total":3}]}'
        );

        $ops = $this->indexByPath($diff['changes']);
        $this->assertArrayHasKey('/items/1/total', $ops);
        $this->assertSame(2, $ops['/items/1/total']['from']);
        $this->assertSame(3, $ops['/items/1/total']['to']);
    }

    public function testNonJsonBodiesFallBackToLineDiff(): void
    {
        $diff = (new MessageDiff())->diffBody(
            "line1\nline2\nline3",
            "line1\nLINE2\nline3"
        );

        $this->assertSame('text', $diff['format']);
        $this->assertFalse($diff['identical']);

        $lines = array_map(static function ($change) {
            return ($change['op'] === 'added' ? '+' : '-') . $change['line'];
        }, $diff['changes']);

        $this->assertContains('-line2', $lines);
        $this->assertContains('+LINE2', $lines);
    }

    public function testHeaderDiffDetectsAddedRemovedChanged(): void
    {
        $diff = (new MessageDiff())->diffAssoc(
            ['x-correlation-id' => 'a', 'x-retry-attempt' => 0],
            ['x-correlation-id' => 'b', 'x-causation-id' => 'parent']
        );

        $this->assertFalse($diff['identical']);
        $this->assertSame(['x-causation-id' => 'parent'], $diff['added']);
        $this->assertSame(['x-retry-attempt' => 0], $diff['removed']);
        $this->assertSame(['x-correlation-id' => ['from' => 'a', 'to' => 'b']], $diff['changed']);
    }

    public function testFullDiffStructureMatchesExpectedShape(): void
    {
        $left = [
            'id' => 'msg_1',
            'direction' => 'published',
            'routing' => 'orders.created',
            'body' => '{"orderId":"o-1","total":9.99}',
            'properties' => ['content_type' => 'application/json'],
            'headers' => ['x-correlation-id' => 'corr_a'],
            'recorded_at' => 1700000000.123,
        ];
        $right = [
            'id' => 'msg_2',
            'direction' => 'published',
            'routing' => 'orders.created',
            'body' => '{"orderId":"o-1","total":19.99}',
            'properties' => ['content_type' => 'application/json'],
            'headers' => ['x-correlation-id' => 'corr_a', 'x-retry-attempt' => 1],
            'recorded_at' => 1700000001.456,
        ];

        $diff = (new MessageDiff())->diff($left, $right);

        $this->assertSame('msg_1', $diff['left']['id']);
        $this->assertSame('msg_2', $diff['right']['id']);
        $this->assertFalse($diff['body']['identical']);
        $this->assertSame(['x-retry-attempt' => 1], $diff['headers']['added']);
        $this->assertTrue($diff['properties']['identical']);
    }

    public function testRenderEmitsHumanFriendlyOutput(): void
    {
        $diff = (new MessageDiff())->diff(
            [
                'id' => 'a',
                'direction' => 'published',
                'routing' => 'r',
                'body' => '{"x":1}',
                'headers' => [],
                'properties' => [],
            ],
            [
                'id' => 'b',
                'direction' => 'published',
                'routing' => 'r',
                'body' => '{"x":2}',
                'headers' => [],
                'properties' => [],
            ]
        );

        $output = (new MessageDiff())->render($diff);

        $this->assertStringContainsString('left :', $output);
        $this->assertStringContainsString('right:', $output);
        $this->assertStringContainsString('── body ──', $output);
        $this->assertStringContainsString('~ /x : 1 -> 2', $output);
        $this->assertStringContainsString('── headers ──', $output);
        $this->assertStringContainsString('(identical)', $output);
    }

    /**
     * @param array<int, array<string, mixed>> $changes
     * @return array<string, array<string, mixed>>
     */
    private function indexByPath(array $changes): array
    {
        $byPath = [];
        foreach ($changes as $change) {
            $byPath[(string) $change['path']] = $change;
        }
        return $byPath;
    }
}
