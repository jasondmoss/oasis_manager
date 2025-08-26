<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\oasis_manager\Hook\OasisManagerHooks;
use Drupal\oasis_manager\Service\OasisAuthenticationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OasisManagerHooksTest extends TestCase
{

    private function setUpMinimalContainer(): void
    {
        $container = new ContainerBuilder();

        // Translation service used by StringTranslationTrait (t()).
        $translator = $this->createMock(TranslationInterface::class);
        $translator
            ->method('translate')
            ->willReturnCallback(
                function ($string, array $args = []) {
                    // A very naive translator for testing.
                    if (! empty($args)) {
                        foreach ($args as $k => $v) {
                            $string = str_replace($k, $v, $string);
                        }
                    }

                    return $string;
                }
            );
        $container->set('string_translation', $translator);

        // Messenger is referenced in other areas; provide a stub.
        $container->set('messenger', $this->createMock(MessengerInterface::class));

        // Url generation used when creating Url objects.
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/user');
        $urlGenerator->method('generateFromRoute')->willReturnCallback(
            fn (string $route) => '/' . str_replace('.', '/', $route)
        );
        $container->set('url_generator', $urlGenerator);

        // Current user (not directly needed for these tests but common in hooks).
        $container->set('current_user', $this->createMock(AccountProxyInterface::class));

        Drupal::setContainer($container);
    }


    public function testUserLoginValidateDelegatesToService(): void
    {
        $this->setUpMinimalContainer();

        // Mock our auth service and assert validateLogin is called with same args.
        /** @var OasisAuthenticationService&MockObject $auth */
        $auth = $this->createMock(OasisAuthenticationService::class);
        $auth->expects($this->once())->method('validateLogin');

        Drupal::getContainer()->set('oasis_manager.authentication', $auth);

        $form = [];
        $formState = $this->createMock(FormStateInterface::class);
        OasisManagerHooks::userLoginValidate($form, $formState);
    }


    public function testFormUserLoginFormAlterReplacesValidateHandlers(): void
    {
        $this->setUpMinimalContainer();
        $hooks = new OasisManagerHooks();

        $form = [
            'name' => [
                '#title' => 'Username',
                '#description' => '',
            ],
            '#validate' => [['Drupal\\user\\Form\\UserLoginForm', 'validateFinal']],
        ];
        $fs = $this->createMock(FormStateInterface::class);
        $hooks->formUserLoginFormAlter($form, $fs);

        $this->assertSame('Email address or username', $form['name']['#title']);
        $this->assertSame(
            'Enter your email address or username.',
            $form['name']['#description']
        );
        $this->assertIsArray($form['#validate']);
        $this->assertSame(
            [OasisManagerHooks::class, 'userLoginValidate'],
            $form['#validate'][0]
        );
    }


    public function testPreprocessMenuRenamesUserPageForMembers(): void
    {
        $this->setUpMinimalContainer();
        $hooks = new OasisManagerHooks();

        $variables = [
            'menu_name' => 'account',
            'items' => [
                [
                    'title' => 'My account',
                    'url' => Url::fromRoute('user.page'),
                    'cache' => [
                        'contexts' => [],
                    ],
                ],
            ],
        ];

        $hooks->preprocessMenu($variables);

        $this->assertSame('Member Profile', $variables['items'][0]['title']);
        $this->assertInstanceOf(Url::class, $variables['items'][0]['url']);
        $this->assertTrue($variables['items'][0]['url']->isRouted());
        $this->assertSame(
            'oasis_manager.member_profile_redirect',
            $variables['items'][0]['url']->getRouteName()
        );
        $this->assertContains('user.roles', $variables['items'][0]['cache']['contexts']);
    }

}
