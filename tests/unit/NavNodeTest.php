<?php

namespace viget\partskit\tests\unit;

use Codeception\Test\Unit;
use UnitTester;
use viget\partskit\models\NavNode;

class NavNodeTest extends Unit
{
    protected UnitTester $tester;

    public function testJsonSerializeOmitsPath(): void
    {
        $node = new NavNode(title: 'Button', path: 'button', url: '/parts-kit/button');
        $json = $node->jsonSerialize();

        $this->assertSame('Button', $json['title']);
        $this->assertSame('/parts-kit/button', $json['url']);
        $this->assertSame([], $json['children']);
        // `path` is intentionally excluded from the serialized output.
        $this->assertArrayNotHasKey('path', $json);
    }

    public function testDefaultsForDirectoryNode(): void
    {
        $node = new NavNode(title: 'Button', path: 'button');

        $this->assertNull($node->url);
        $this->assertSame([], $node->children);
    }

    public function testNestedChildrenSerializeRecursively(): void
    {
        $child = new NavNode(title: 'Default', path: 'button/default', url: '/parts-kit/button/default');
        $parent = new NavNode(title: 'Button', path: 'button', url: null, children: [$child]);

        $json = $parent->jsonSerialize();

        $this->assertCount(1, $json['children']);
        $this->assertInstanceOf(NavNode::class, $json['children'][0]);
        $this->assertSame('Default', $json['children'][0]->title);

        // Round-trip through json_encode to prove the JsonSerializable contract
        // propagates into nested NavNode children and drops `path` at depth.
        $encoded = json_decode(json_encode($parent), true);
        $this->assertSame('Default', $encoded['children'][0]['title']);
        $this->assertSame('/parts-kit/button/default', $encoded['children'][0]['url']);
        $this->assertArrayNotHasKey('path', $encoded['children'][0]);
    }
}
