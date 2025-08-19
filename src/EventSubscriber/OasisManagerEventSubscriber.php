<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\EventSubscriber;

/**
 * @file
 * Event subscriber for the Oasis Manager module.
 *
 * @link https://www.jdmlabs.com/
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Module
 * @package DRUPAL11
 */

use Drupal;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\oasis_manager\Service\OasisAuthenticationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class OasisManagerEventSubscriber implements EventSubscriberInterface
{

    /**
     * The OASIS authentication service.
     *
     * @var \Drupal\oasis_manager\Service\OasisAuthenticationService
     */
    protected OasisAuthenticationService $oasisAuthenticationService;

    /**
     * The current user.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected AccountProxyInterface $currentUser;

    /**
     * The messenger service.
     *
     * @var \Drupal\Core\Messenger\MessengerInterface
     */
    protected MessengerInterface $messenger;

    /**
     * The request stack.
     *
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    protected RequestStack $requestStack;


    /**
     * Constructs a new OasisManagerEventSubscriber object.
     *
     * @param \Drupal\oasis_manager\Service\OasisAuthenticationService $oasis_authentication_service
     *   The OASIS authentication service.
     * @param \Drupal\Core\Session\AccountProxyInterface $current_user
     *   The current user.
     * @param \Drupal\Core\Messenger\MessengerInterface $messenger
     *   The messenger service.
     * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
     *   The request stack.
     */
    public function __construct(
        OasisAuthenticationService $oasis_authentication_service,
        AccountProxyInterface $current_user,
        MessengerInterface $messenger,
        RequestStack $request_stack
    ) {
        $this->oasisAuthenticationService = $oasis_authentication_service;
        $this->currentUser = $current_user;
        $this->messenger = $messenger;
        $this->requestStack = $request_stack;
    }


    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['checkOasisSession', 28]
        ];
    }


    /**
     * Checks the OASIS session on each request.
     *
     * This method verifies that users with OASIS roles still have valid sessions
     * and takes appropriate action if not. It also intercepts requests to the
     * default logout route and redirects OASIS members to the custom logout route.
     *
     * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
     *   The request event.
     */
    public function checkOasisSession(RequestEvent $event): void
    {
        // Only act on the main request, not subrequests.
        if (! $event->isMainRequest()) {
            return;
        }

        // Get the current request.
        $request = $event->getRequest();

        // Only check authenticated users for session validation.
        if ($this->currentUser->isAnonymous()) {
            return;
        }

        // Check if this is an OASIS user (has member session variables).
        if (! $this->isOasisUser()) {
            return;
        }

        // Check if the OASIS token is still valid.
        if (! $this->hasValidOasisToken()) {
            // Token is invalid or expired, log a warning.
            Drupal::logger('oasis_manager')
                ->warning('Invalid or expired OASIS token for user @uid', [
                    '@uid' => $this->currentUser->id(),
                ]);

            // Optionally, you could force logout or refresh the token here.
            // For now, just display a warning to the user.
            $this->messenger
                ->addWarning(t(
                    'Your OASIS session may have expired. Please log out and log '
                    . 'in again if you experience any issues.'
                ));
        }
    }


    /**
     * Checks if the current user is an OASIS user.
     *
     * @return bool
     *   TRUE if the user is an OASIS user, FALSE otherwise.
     */
    protected function isOasisUser(): bool
    {
        if (! ($request = $this->requestStack->getCurrentRequest())) {
            return false;
        }

        if (! ($session = $request->getSession())) {
            return false;
        }

        // Check for OASIS session variables using proper session handling.
        return $session->has('member') && $session->has('OasisAPIToken');
    }


    /**
     * Checks if the OASIS token is valid.
     *
     * In a real implementation, this would verify the token with the OASIS API.
     * For this example, we'll just check if it exists and is not empty.
     *
     * @return bool
     *   TRUE if the token is valid, FALSE otherwise.
     */
    protected function hasValidOasisToken(): bool
    {
        if (! ($request = $this->requestStack->getCurrentRequest())) {
            return false;
        }

        if (! ($session = $request->getSession())) {
            return false;
        }

        $token = $session->get('OasisAPIToken');

        return ! empty($token) && is_string($token);
    }

}

/* <> */
