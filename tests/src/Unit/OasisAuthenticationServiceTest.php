<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal\oasis_manager\Service\OasisAuthenticationService;
use Drupal\oasis_manager\Service\OasisApiClient;
use Drupal\oasis_manager\Service\OasisUserManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Drupal\oasis_manager\Service\OasisAuthenticationService
 * @group oasis_manager
 */
class TestableAuthService extends OasisAuthenticationService
{

    public function __construct(
        OasisApiClient $api,
        OasisUserManager $userManager,
        LoggerChannelFactoryInterface $loggerFactory,
        MessengerInterface $messenger,
        UserAuthenticationInterface $userAuth
    ) {
        parent::__construct($api, $userManager, $loggerFactory, $messenger, $userAuth);
    }


    public function callAuthenticateWithOasis(
        FormStateInterface $fs,
        string $email,
        string $pass
    ): bool {
        return parent::authenticateWithOasis($fs, $email, $pass);
    }

}


class OasisAuthenticationServiceTest extends TestCase
{

    private function makeDeps(): array
    {
        $api = $this->createMock(OasisApiClient::class);
        $userManager = $this->createMock(OasisUserManager::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory->method('get')->willReturn($logger);
        $messenger = $this->createMock(MessengerInterface::class);
        $userAuth = $this->createMock(UserAuthenticationInterface::class);

        return [$api, $userManager, $loggerFactory, $messenger, $userAuth];
    }


    public function testAuthenticateWithOasisSuccessSetsFormState(): void
    {
        [$api, $userManager, $loggerFactory, $messenger, $userAuth] = $this->makeDeps();

        $data = (object) [
            'MemberID' => '123',
            'LoginID' => 'user@example.com',
            'FirstName' => 'Alice',
            'LastName' => 'Smith',
            'OasisAPIToken' => 'tok',
        ];

        $api->method('authenticateUser')->willReturn([
            'success' => true,
            'data' => $data,
            'error_type' => null,
        ]);

        $user = $this->createMock(User::class);
        $user->method('id')->willReturn(42);
        $userManager
            ->expects($this->once())
            ->method('getOrCreateUser')
            ->with('123', 'secret', $data)
            ->willReturn($user);
        $userManager
            ->expects($this->once())
            ->method('finalizeLogin')
            ->with($user, $data);

        /** @var FormStateInterface&MockObject $formState */
        $formState = $this->createMock(FormStateInterface::class);
        $formState
            ->expects($this->exactly(3))
            ->method('set')
            ->withConsecutive(['uid', 42], ['user', $user], ['member', true]);

        $service = new TestableAuthService($api, $userManager, $loggerFactory, $messenger, $userAuth);
        $ok = $service->callAuthenticateWithOasis($formState, 'user@example.com', 'secret');
        $this->assertTrue($ok);
    }


    public function testAuthenticateWithOasisApiUnavailableShowsError(): void
    {
        [$api, $userManager, $loggerFactory, $messenger, $userAuth] = $this->makeDeps();

        $api->method('authenticateUser')->willReturn([
            'success' => false,
            'data' => null,
            'error_type' => 'api_unavailable',
        ]);

        $messenger->expects($this->once())->method('addError');
        $formState = $this->createMock(FormStateInterface::class);

        $service = new TestableAuthService($api, $userManager, $loggerFactory, $messenger, $userAuth);
        $ok = $service->callAuthenticateWithOasis($formState, 'user@example.com', 'secret');
        $this->assertFalse($ok);
    }


    public function testAuthenticateWithOasisInvalidCredentialsNoErrorMessage(): void
    {
        [$api, $userManager, $loggerFactory, $messenger, $userAuth] = $this->makeDeps();

        $api->method('authenticateUser')->willReturn([
            'success' => false,
            'data' => null,
            'error_type' => 'invalid_credentials',
        ]);

        $messenger->expects($this->never())->method('addError');
        $formState = $this->createMock(FormStateInterface::class);

        $service = new TestableAuthService($api, $userManager, $loggerFactory, $messenger, $userAuth);
        $ok = $service->callAuthenticateWithOasis($formState, 'user@example.com', 'secret');
        $this->assertFalse($ok);
    }


    public function testAuthenticateWithOasisInvalidResponseShowsError(): void
    {
        [$api, $userManager, $loggerFactory, $messenger, $userAuth] = $this->makeDeps();

        $api->method('authenticateUser')->willReturn([
            'success' => false,
            'data' => null,
            'error_type' => 'invalid_response',
        ]);

        $messenger->expects($this->once())->method('addError');
        $formState = $this->createMock(FormStateInterface::class);

        $service = new TestableAuthService($api, $userManager, $loggerFactory, $messenger, $userAuth);
        $ok = $service->callAuthenticateWithOasis($formState, 'user@example.com', 'secret');
        $this->assertFalse($ok);
    }


    public function testAuthenticateWithOasisUnexpectedExceptionShowsError(): void
    {
        [$api, $userManager, $loggerFactory, $messenger, $userAuth] = $this->makeDeps();

        $api->method('authenticateUser')->willThrowException(new \Exception('boom'));

        $messenger->expects($this->once())->method('addError');
        $formState = $this->createMock(FormStateInterface::class);

        $service = new TestableAuthService($api, $userManager, $loggerFactory, $messenger, $userAuth);
        $ok = $service->callAuthenticateWithOasis($formState, 'user@example.com', 'secret');
        $this->assertFalse($ok);
    }

}
