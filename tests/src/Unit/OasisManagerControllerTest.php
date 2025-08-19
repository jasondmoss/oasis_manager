<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal\oasis_manager\Controller\OasisManagerController;
use Drupal\user\UserAuthInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\oasis_manager\Controller\OasisManagerController
 * @group oasis_manager
 */
class DummyUserAuth implements UserAuthInterface
{
    public bool $logoutCalled = false;

    public function authenticate($name, $password)
    {
        return false;
    }

    public function logout(): void
    {
        $this->logoutCalled = true;
    }
}

class OasisManagerControllerTest extends TestCase
{
    public function testLogoutCallsUserAuthLogout(): void
    {
        $auth = new DummyUserAuth();
        $controller = new OasisManagerController($auth);
        $controller->logout(new Request());
        $this->assertTrue($auth->logoutCalled);
    }
}
