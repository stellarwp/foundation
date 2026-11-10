<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Container;

use StellarWP\Foundation\Tests\Support\Fixtures\Container\CallbackListener;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class ContainerCallbackTest extends WPTestCase
{
	public function test_equivalent_callbacks_can_remove_wordpress_hooks(): void {
		$listener = new CallbackListener();
		$hook     = 'foundation/container/callback/' . uniqid('', true);

		add_action($hook, $this->container->callback($listener, 'listen'));
		remove_action($hook, $this->container->callback($listener, 'listen'));
		do_action($hook);

		$this->assertSame(0, $listener->calls);
	}
}
