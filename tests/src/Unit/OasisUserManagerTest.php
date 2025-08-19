<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal\oasis_manager\Service\OasisUserManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\oasis_manager\Service\OasisApiClient;
use Drupal\user\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @covers \Drupal\oasis_manager\Service\OasisUserManager
 * @group oasis_manager
 */
class OasisUserManagerTest extends TestCase
{
    private function makeSut(
        ?AccountProxyInterface $currentUser = null,
        ?RequestStack $requestStack = null
    ): OasisUserManager {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $apiClient = $this->createMock(OasisApiClient::class);

        $currentUser = $currentUser ?? $this->createMock(AccountProxyInterface::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory->method('get')->willReturn($logger);

        $requestStack = $requestStack ?? new RequestStack();

        return new OasisUserManager(
            $entityTypeManager,
            $apiClient,
            $currentUser,
            $loggerFactory,
            $requestStack
        );
    }

    public function testFinalizeLoginReturnsFalseWhenAdminSessionPresent(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAuthenticated')->willReturn(true);
        $currentUser->method('hasPermission')->with('administer users')->willReturn(true);

        $sut = $this->makeSut($currentUser);
        $user = $this->createMock(User::class);
        $oasis = (object) ['MemberID' => '1'];

        $this->assertFalse($sut->finalizeLogin($user, $oasis));
    }

    public function testFinalizeLoginFailsWithoutRequestOrSession(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAuthenticated')->willReturn(false);

        $requestStack = new RequestStack();
        $sut = $this->makeSut($currentUser, $requestStack);

        $user = $this->createMock(User::class);
        $oasis = (object) [];
        $this->assertFalse($sut->finalizeLogin($user, $oasis));

        // Now add request without session
        $requestStack->push(new Request());
        $this->assertFalse($sut->finalizeLogin($user, $oasis));
    }

    public function testFinalizeLoginSetsSessionValuesAndReturnsTrue(): void
    {
        $currentUser = $this->createMock(AccountProxyInterface::class);
        $currentUser->method('isAuthenticated')->willReturn(false);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $sut = $this->makeSut($currentUser, $requestStack);

        $user = $this->createMock(User::class);
        $oasis = (object) [
            'MemberID' => '123',
            'OasisAPIToken' => 'tok',
            'RegCategory' => 'Regular Members',
            'OrchardRoles' => 'Executive Committee',
        ];

        $this->assertTrue($sut->finalizeLogin($user, $oasis));
        $this->assertSame('123', $session->get('member'));
        $this->assertSame('tok', $session->get('OasisAPIToken'));
        $this->assertSame('Regular Members', $session->get('OasisRegCategory'));
        $this->assertSame('Executive Committee', $session->get('OasisOrchardRoles'));
    }
}
