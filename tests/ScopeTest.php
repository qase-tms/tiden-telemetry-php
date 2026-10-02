<?php

declare(strict_types=1);

namespace Tiden\Tests;

use PHPUnit\Framework\TestCase;
use Tiden\Breadcrumb;
use Tiden\Scope;

final class ScopeTest extends TestCase
{
    public function test_clear_breadcrumbs_keeps_tags_user_extra(): void
    {
        $scope = new Scope;
        $scope->setTag('region', 'eu');
        $scope->setUser(['id' => '42']);
        $scope->setExtra('build', 7);
        $scope->setLevel('warning');
        $scope->addBreadcrumb(new Breadcrumb('first'));
        $scope->addBreadcrumb(new Breadcrumb('second'));

        $scope->clearBreadcrumbs();
        $event = $scope->applyTo([]);

        $this->assertArrayNotHasKey('breadcrumbs', $event);
        $this->assertSame(['region' => 'eu'], $event['tags']);
        $this->assertSame(['id' => '42'], $event['user']);
        $this->assertSame(['build' => 7], $event['extra']);
        $this->assertSame('warning', $event['level']);
    }

    public function test_breadcrumbs_added_after_clear_are_kept(): void
    {
        $scope = new Scope;
        $scope->addBreadcrumb(new Breadcrumb('old'));
        $scope->clearBreadcrumbs();
        $scope->addBreadcrumb(new Breadcrumb('new'));

        $values = $scope->applyTo([])['breadcrumbs']['values'];

        $this->assertCount(1, $values);
        $this->assertSame('new', $values[0]['message']);
    }

    public function test_clear_still_wipes_everything(): void
    {
        $scope = new Scope;
        $scope->setTag('region', 'eu');
        $scope->setUser(['id' => '42']);
        $scope->setExtra('build', 7);
        $scope->setLevel('warning');
        $scope->addBreadcrumb(new Breadcrumb('first'));

        $scope->clear();

        $this->assertSame([], $scope->applyTo([]));
    }
}
