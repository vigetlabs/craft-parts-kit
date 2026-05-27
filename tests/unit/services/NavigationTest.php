<?php

namespace viget\partskit\tests\unit\services;

use Codeception\Test\Unit;
use UnitTester;
use viget\partskit\models\NavNode;
use viget\partskit\services\Navigation;

/**
 * Exercises Navigation::getNav() against the fixture template tree at
 * tests/_craft/templates/parts-kit/. The Craft test harness points the site
 * templates path at that directory, so the fixtures drive every assertion.
 */
class NavigationTest extends Unit
{
    protected UnitTester $tester;

    /**
     * @return NavNode[]
     */
    private function nav(): array
    {
        return (new Navigation())->getNav();
    }

    private function findByTitle(array $nodes, string $title): ?NavNode
    {
        foreach ($nodes as $node) {
            if ($node->title === $title) {
                return $node;
            }
        }

        return null;
    }

    public function testBuildsTopLevelNodesFromTemplateTree(): void
    {
        $titles = array_map(fn(NavNode $n) => $n->title, $this->nav());

        // Directories become top-level nodes, ordered by path; index.twig is skipped.
        $this->assertSame(['Alpha', 'Button', 'Cta block'], $titles);
    }

    public function testDirectoryNodesHaveNullUrlAndFileNodesHaveUrl(): void
    {
        $button = $this->findByTitle($this->nav(), 'Button');
        $this->assertNotNull($button);
        $this->assertNull($button->url, 'Directory nodes have no URL');

        $default = $this->findByTitle($button->children, 'Default');
        $this->assertNotNull($default);
        $this->assertSame('/parts-kit/button/default', $default->url);
    }

    public function testExcludesUnderscoreFilesAndSortsChildrenByTitle(): void
    {
        $button = $this->findByTitle($this->nav(), 'Button');
        $childTitles = array_map(fn(NavNode $n) => $n->title, $button->children);

        // _ignore.twig is excluded; remaining children are alpha-sorted by title.
        $this->assertSame(['Blue', 'Default'], $childTitles);
    }

    public function testSkipsRootIndexTemplate(): void
    {
        $titles = array_map(fn(NavNode $n) => $n->title, $this->nav());

        $this->assertNotContains('Index', $titles);
    }

    /**
     * @group bug-9
     *
     * getNav() builds nested-only directories correctly: a top-level directory whose
     * only descendant lives in a sub-subdirectory still appears with its full child
     * chain. Bug #9 ("first folder without a direct .twig fails to render") is therefore
     * a rendering-layer issue downstream of getNav(), not a data-shape bug here. This
     * test pins getNav()'s correct nested-build behavior so a future #9 fix can rely on it.
     */
    public function testBuildsNestedOnlyDirectory(): void
    {
        $alpha = $this->findByTitle($this->nav(), 'Alpha');
        $this->assertNotNull($alpha);
        $this->assertNull($alpha->url);
        $this->assertCount(1, $alpha->children);

        $sub = $alpha->children[0];
        $this->assertSame('Sub', $sub->title);
        $this->assertCount(1, $sub->children);

        $widget = $sub->children[0];
        $this->assertSame('Widget', $widget->title);
        $this->assertSame('/parts-kit/alpha/sub/widget', $widget->url);
    }
}
