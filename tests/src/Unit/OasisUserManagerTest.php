<?php

namespace Drupal\Tests\oasis_manager\Unit;

/**
 * Unit tests for the OasisUserManager service.
 *
 * @link https://www.jdmlabs.com/
 *
 * @group oasis_manager
 * @coversDefaultClass \Drupal\oasis_manager\Service\OasisUserManager
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Test
 * @package DRUPAL11
 */

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oasis_manager\Service\OasisApiClient;
use Drupal\oasis_manager\Service\OasisUserManager;
use Drupal\rabbit_hole\BehaviorSettingsManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class OasisUserManagerTest extends UnitTestCase
{

    /**
     * The entity type manager prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected EntityTypeManagerInterface|ObjectProphecy $entityTypeManager;

    /**
     * The OASIS API client prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected OasisApiClient|ObjectProphecy $oasisApiClient;

    /**
     * The logger factory prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected LoggerChannelFactoryInterface|ObjectProphecy $loggerFactory;

    /**
     * The logger channel prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected LoggerChannelInterface|ObjectProphecy $loggerChannel;

    /**
     * The current user prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected AccountProxyInterface|ObjectProphecy $currentUser;

    /**
     * The request stack prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected RequestStack|ObjectProphecy $requestStack;

    /**
     * The module handler prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected ModuleHandlerInterface|ObjectProphecy $moduleHandler;

    /**
     * The rabbit hole behavior settings manager prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected BehaviorSettingsManagerInterface|ObjectProphecy $rabbitHoleBehaviorSettingsManager;

    /**
     * The OASIS user manager service.
     *
     * @var \Drupal\oasis_manager\Service\OasisUserManager
     */
    protected OasisUserManager $oasisUserManager;


    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create prophecies for dependencies.
        $this->entityTypeManager = $this->prophesize(EntityTypeManagerInterface::class);
        $this->oasisApiClient = $this->prophesize(OasisApiClient::class);
        $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
        $this->loggerChannel = $this->prophesize(LoggerChannelInterface::class);
        $this->currentUser = $this->prophesize(AccountProxyInterface::class);
        $this->requestStack = $this->prophesize(RequestStack::class);
        $this->moduleHandler = $this->prophesize(ModuleHandlerInterface::class);
        $this->rabbitHoleBehaviorSettingsManager = $this->prophesize(BehaviorSettingsManagerInterface::class);

        // Set up the logger factory to return our logger channel.
        $this->loggerFactory->get('oasis_manager')->willReturn($this->loggerChannel->reveal());

        // Create the service with the mocked dependencies.
        $this->oasisUserManager = new OasisUserManager(
            $this->entityTypeManager->reveal(),
            $this->oasisApiClient->reveal(),
            $this->currentUser->reveal(),
            $this->loggerFactory->reveal(),
            $this->requestStack->reveal(),
            $this->moduleHandler->reveal(),
            $this->rabbitHoleBehaviorSettingsManager->reveal()
        );
    }


    /**
     * Tests successful login finalization with proper session handling.
     *
     * @covers ::finalizeLogin
     */
    public function testFinalizeLoginSuccess(): void
    {
        // Mock user.
        $user = $this->prophesize(User::class);
        $user->id()->willReturn(123);

        // Mock OASIS data.
        $oasis_data = (object) [
            'MemberID' => '12345',
            'OasisAPIToken' => 'valid-token',
            'RegCategory' => 'Regular Members',
            'OrchardRoles' => 'Governing Council'
        ];

        // Mock current user as non-admin.
        $this->currentUser->isAuthenticated()->willReturn(false);

        // Mock request and session.
        $session = $this->prophesize(SessionInterface::class);
        $request = $this->prophesize(Request::class);
        $request->getSession()->willReturn($session->reveal());
        $this->requestStack->getCurrentRequest()->willReturn($request->reveal());

        // Expect session variables to be set.
        $session->set('member', '12345')->shouldBeCalled();
        $session->set('OasisAPIToken', 'valid-token')->shouldBeCalled();
        $session->set('OasisRegCategory', 'Regular Members')->shouldBeCalled();
        $session->set('OasisOrchardRoles', 'Governing Council')->shouldBeCalled();

        // Expect success log.
        $this->loggerChannel
            ->info('OASIS user login finalized for member @member_id', [
                '@member_id' => '12345'
            ])
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisUserManager->finalizeLogin($user->reveal(), $oasis_data);

        // Assert that the result is TRUE.
        $this->assertTrue($result);
    }


    /**
     * Tests login finalization failure when no request is available.
     *
     * @covers ::finalizeLogin
     */
    public function testFinalizeLoginNoRequest(): void
    {
        // Mock user.
        $user = $this->prophesize(User::class);

        // Mock OASIS data.
        $oasis_data = (object) [
            'MemberID' => '12345',
            'OasisAPIToken' => 'valid-token',
        ];

        // Mock current user as non-admin.
        $this->currentUser->isAuthenticated()->willReturn(false);

        // Mock no current request.
        $this->requestStack->getCurrentRequest()->willReturn(null);

        // Expect error log.
        $this->loggerChannel
            ->error('No current request available for session handling')
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisUserManager->finalizeLogin($user->reveal(), $oasis_data);

        // Assert that the result is FALSE.
        $this->assertFalse($result);
    }


    /**
     * Tests login finalization prevention for admin users.
     *
     * @covers ::finalizeLogin
     */
    public function testFinalizeLoginAdminUser(): void
    {
        // Mock user.
        $user = $this->prophesize(User::class);

        // Mock OASIS data.
        $oasis_data = (object) [
            'MemberID' => '12345',
        ];

        // Mock current user as authenticated admin.
        $this->currentUser->isAuthenticated()->willReturn(true);
        $this->currentUser->hasPermission('administer users')->willReturn(true);

        // Expect notice log.
        $this->loggerChannel
            ->notice('Admin OASIS login attempt, not overwriting admin session')
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisUserManager->finalizeLogin($user->reveal(), $oasis_data);

        // Assert that the result is FALSE.
        $this->assertFalse($result);
    }

}

/* <> */
