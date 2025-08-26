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
use Drupal;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Routing\UrlGeneratorInterface;

class OasisManagerControllerTest extends TestCase
{

    public function testLogoutRedirectsToUserLogout(): void
    {
        // Prepare a minimal Drupal container with a URL generator.
        $container = new ContainerBuilder();
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/user/logout');
        $urlGenerator->method('generateFromRoute')->willReturn('/user/logout');
        $container->set('url_generator', $urlGenerator);
        $container->set('request_stack', new RequestStack());
        Drupal::setContainer($container);

        $controller = new OasisManagerController();
        $response = $controller->logout(new Request());

        $this->assertSame('/user/logout', $response->getTargetUrl());
        $this->assertSame(302, $response->getStatusCode());
    }

}
