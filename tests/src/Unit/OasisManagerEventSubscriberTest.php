<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oasis_manager\EventSubscriber\OasisManagerEventSubscriber;
use Drupal\oasis_manager\Service\OasisAuthenticationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @covers \Drupal\oasis_manager\EventSubscriber\OasisManagerEventSubscriber
 * @group oasis_manager
 */
class OasisManagerEventSubscriberTest extends TestCase
{
    private function makeSubscriber(
        ?AccountProxyInterface $currentUser = null,
        ?MessengerInterface $messenger = null,
        ?RequestStack $stack = null
    ): OasisManagerEventSubscriber {
        $auth = $this->createMock(OasisAuthenticationService::class);
        $currentUser = $currentUser ?? $this->createMock(AccountProxyInterface::class);
        $messenger = $messenger ?? $this->createMock(MessengerInterface::class);
        $stack = $stack ?? new RequestStack();

        return new OasisManagerEventSubscriber($auth, $currentUser, $messenger, $stack);
    }

    private function makeEvent(Request $request): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    public function testSubscribedEvents(): void
    {
        $this->assertArrayHasKey(
            KernelEvents::REQUEST,
            OasisManagerEventSubscriber::getSubscribedEvents()
        );
    }

    public function testAnonymousUserIsIgnored(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAnonymous')->willReturn(true);
        $messenger = $this->createMock(MessengerInterface::class);
        $messenger->expects($this->never())->method('addWarning');

        $subscriber = $this->makeSubscriber($currentUser, $messenger);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $subscriber->checkOasisSession($this->makeEvent($request));
    }

    public function testNonOasisUserIsIgnored(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAnonymous')->willReturn(false);
        $messenger = $this->createMock(MessengerInterface::class);
        $messenger->expects($this->never())->method('addWarning');

        $stack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);

        $subscriber = $this->makeSubscriber($currentUser, $messenger, $stack);
        $subscriber->checkOasisSession($this->makeEvent($request));
    }

    public function testOasisUserWithInvalidTokenGetsWarning(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAnonymous')->willReturn(false);
        $messenger = $this->createMock(MessengerInterface::class);
        $messenger->expects($this->once())->method('addWarning');

        $stack = new RequestStack();
        $session = new Session(new MockArraySessionStorage());
        $session->set('member', '123');
        $session->set('OasisAPIToken', ''); // present, but invalid
        $request = new Request();
        $request->setSession($session);
        $stack->push($request);

        $subscriber = $this->makeSubscriber($currentUser, $messenger, $stack);
        $subscriber->checkOasisSession($this->makeEvent($request));
    }

    public function testOasisUserWithValidTokenNoWarning(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAnonymous')->willReturn(false);
        $messenger = $this->createMock(MessengerInterface::class);
        $messenger->expects($this->never())->method('addWarning');

        $stack = new RequestStack();
        $session = new Session(new MockArraySessionStorage());
        $session->set('member', '123');
        $session->set('OasisAPIToken', 'tok123');
        $request = new Request();
        $request->setSession($session);
        $stack->push($request);

        $subscriber = $this->makeSubscriber($currentUser, $messenger, $stack);
        $subscriber->checkOasisSession($this->makeEvent($request));
    }
}
