<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\Controller;

/**
 * @file
 * Controller for the Oasis Manager module.
 *
 * @link https://www.jdmlabs.com/
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Module
 * @package DRUPAL11
 */

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class OasisManagerController extends ControllerBase
{

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static
    {
        // No dependencies required for this controller.
        return new static();
    }


    /**
     * Logs out the current user by redirecting to Drupal core's logout route.
     * This ensures CSRF validation and full session invalidation is handled by core.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function logout(Request $request): RedirectResponse
    {
        /**
         * Redirect to the core logout route. Drupal will automatically append the
         * CSRF token for routes that require it and will fully invalidate the session
         * during logout.
         */
        return $this->redirect('user.logout');
    }


    /**
     * Redirects Oasis members to the external Member Profile page.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function memberProfileRedirect(Request $request): RedirectResponse
    {
        $current_user = Drupal::currentUser();
        if ($current_user->isAnonymous()) {
            return $this->redirect('user.page');
        }

        // Check roles to ensure this only applies to Oasis members.
        $roles = $current_user->getRoles();
        $is_oasis_member = in_array('regular_member', $roles, true)
            || in_array('associate_member', $roles, true);
        if (! $is_oasis_member) {
            return $this->redirect('user.page');
        }

        // Oasis token login base URI.
        $oasis_login_url = getenv('OASIS_TOKEN_LOGIN_URL');

        // Determine member URL from environment based on language.
        $langcode = Drupal::languageManager()->getCurrentLanguage()->getId();
        $env_key = ($langcode === 'fr')
            ? 'OASIS_MEMBER_PROFILE_URL_FR'
            : 'OASIS_MEMBER_PROFILE_URL_EN';
        $member_uri = $_ENV[$env_key] ?? getenv($env_key) ?: '';

        // Get token from session.
        $token = null;
        if ($request->hasSession() && ($session = $request->getSession())) {
            $token = $session->get('OasisAPIToken');
        }

        // Get email from the user entity.
        $email = '';
        $uid = (int) $current_user->id();
        if ($uid) {
            $account = User::load($uid);
            if ($account) {
                $email = $account->getEmail();
            }
        }

        // If we have everything needed, redirect externally; otherwise fallback.
        if (! empty($oasis_login_url)
            && ! empty($member_uri)
            && ! empty($token)
            && ! empty($email)
        ) {
            $external = Url::fromUri(
                $oasis_login_url . '/'
                . $email . '/'
                . $token . '/'
                . $member_uri
            )->toString();

            return new TrustedRedirectResponse($external, 302);
        }

        return $this->redirect('user.page');
    }

}

/* <> */
