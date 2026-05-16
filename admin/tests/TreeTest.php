<?php

declare(strict_types=1);

namespace tests;

use app\common\Tree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    private function makeFlatData(): array
    {
        return [
            ['id' => 1, 'pid' => 0, 'name' => 'Root'],
            ['id' => 2, 'pid' => 1, 'name' => 'Child A'],
            ['id' => 3, 'pid' => 1, 'name' => 'Child B'],
            ['id' => 4, 'pid' => 2, 'name' => 'Grandchild'],
            ['id' => 5, 'pid' => 0, 'name' => 'Root 2'],
        ];
    }

    public function testGetTreeReturnsTopLevelNodes(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree();

        $this->assertCount(2, $result);
        $this->assertSame('Root', $result[0]['name']);
        $this->assertSame('Root 2', $result[1]['name']);
    }

    public function testGetTreeIncludesChildren(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree();

        $this->assertCount(2, $result[0]['children']);
        $this->assertSame('Child A', $result[0]['children'][0]['name']);
        $this->assertSame('Child B', $result[0]['children'][1]['name']);
    }

    public function testGetTreeIncludesNestedChildren(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree();

        $grandchildren = $result[0]['children'][0]['children'];
        $this->assertCount(1, $grandchildren);
        $this->assertSame('Grandchild', $grandchildren[0]['name']);
    }

    public function testGetTreeWithExcludeAncestors(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree([2], Tree::EXCLUDE_ANCESTORS);

        $this->assertCount(1, $result);
        $this->assertSame('Child A', $result[0]['name']);
        $this->assertArrayHasKey('children', $result[0]);
    }

    public function testGetTreeWithIncludeAncestors(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree([4], Tree::INCLUDE_ANCESTORS);

        // Should return root node containing the full path
        $this->assertCount(1, $result);
        $this->assertSame('Root', $result[0]['name']);
    }

    public function testGetTreeWithIncludeReturnsEmptyForMissingId(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getTree([999], Tree::EXCLUDE_ANCESTORS);

        $this->assertSame([], $result);
    }

    public function testGetDescendantReturnsDirectChildren(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getDescendant([1]);

        $ids = array_column($result, 'id');
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
    }

    public function testGetDescendantIncludesNestedDescendants(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getDescendant([1]);

        $ids = array_column($result, 'id');
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
    }

    public function testGetDescendantWithSelf(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getDescendant([2], true);

        $ids = array_column($result, 'id');
        $this->assertContains(2, $ids);
        $this->assertContains(4, $ids);
    }

    public function testGetDescendantReturnsEmptyForMissingId(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getDescendant([999]);

        $this->assertSame([], $result);
    }

    public function testGetDescendantDoesNotIncludeChildrenKey(): void
    {
        $tree = new Tree($this->makeFlatData());

        $result = $tree->getDescendant([1]);

        foreach ($result as $item) {
            $this->assertArrayNotHasKey('children', $item);
        }
    }

    public function testCustomPidName(): void
    {
        $data = [
            ['id' => 1, 'parent_id' => 0, 'name' => 'Parent'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'Child'],
        ];

        $tree = new Tree($data, 'parent_id');
        $result = $tree->getTree();

        $this->assertCount(1, $result);
        $this->assertSame('Parent', $result[0]['name']);
        $this->assertCount(1, $result[0]['children']);
    }

    public function testEmptyDataReturnsEmptyTree(): void
    {
        $tree = new Tree([]);

        $this->assertSame([], $tree->getTree());
        $this->assertSame([], $tree->getDescendant([1]));
    }

    public function testGetTreeSkipsUnknownAncestorsForIncludeCase(): void
    {
        $tree = new Tree($this->makeFlatData());

        // 999 doesn't exist — should be skipped, not crash
        $result = $tree->getTree([1, 999], Tree::INCLUDE_ANCESTORS);

        $this->assertCount(1, $result);
        $this->assertSame('Root', $result[0]['name']);
    }

    public function testSingleNodeTree(): void
    {
        $data = [['id' => 1, 'pid' => 0, 'name' => 'Only']];
        $tree = new Tree($data);

        $result = $tree->getTree();

        $this->assertCount(1, $result);
        $this->assertSame('Only', $result[0]['name']);
        $this->assertArrayNotHasKey('children', $result[0]);
    }

    public function testOrphanNodesAreTopLevel(): void
    {
        $data = [
            ['id' => 1, 'pid' => 99, 'name' => 'Orphan'], // pid 99 doesn't exist
            ['id' => 2, 'pid' => 0, 'name' => 'Root'],
        ];

        $tree = new Tree($data);
        $result = $tree->getTree();

        $this->assertCount(2, $result);
        $this->assertSame('Orphan', $result[0]['name']);
    }

    #[DataProvider('depthProvider')]
    public function testGetTreeHandlesDeepNesting(int $depth): void
    {
        $data = [];
        for ($i = 1; $i <= $depth; $i++) {
            $data[] = ['id' => $i, 'pid' => $i === 1 ? 0 : $i - 1, 'name' => "Node {$i}"];
        }

        $tree = new Tree($data);
        $result = $tree->getTree();

        $this->assertCount(1, $result);
        $node = $result[0];
        $count = 1;
        while (!empty($node['children'])) {
            $node = $node['children'][0];
            $count++;
        }
        $this->assertSame($depth, $count);
    }

    public static function depthProvider(): array
    {
        return [
            'depth 3' => [3],
            'depth 5' => [5],
            'depth 10' => [10],
        ];
    }
}
