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

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class OasisManagerController extends ControllerBase
{

    /**
     * Logs out the current user and redirects based on user type.
     *
     * OASIS members are redirected to node/490, all other users to <front>.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The current request.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *   A redirect response to the appropriate page.
     */
    public function logout(Request $request): RedirectResponse
    {
        // Check if the user is an OASIS member before logging out
        // We must check this BEFORE calling user_logout() as it clears the session
        $session = $request->getSession();
        $is_oasis_member = $session->get('member');

        // Log out the user (this will clear the session)
        user_logout();

        // Determine redirect URL based on user type.
        if ($is_oasis_member) {
            // OASIS members are redirected to node/490.
            $redirect_url = Url::fromRoute('entity.node.canonical', [
                'node' => 490
            ])->toString();
        } else {
            // All other users are redirected to the front page.
            $redirect_url = Url::fromRoute('<front>')->toString();
        }

        return new RedirectResponse($redirect_url);
    }


    /**
     * Redirects the user to their OASIS profile management page.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The current request.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *   A redirect response to the OASIS profile page.
     */
    public function redirectToProfile(Request $request): RedirectResponse
    {
        $current_user = $this->currentUser();
        $language = $this->languageManager()->getCurrentLanguage()->getId();
        $session = $request->getSession();

        // Check if the user has the required roles.
        if (! $this->userHasMemberRole($current_user)) {
            $this->messenger()->addError($this->t('You are not logged in as an OASIS user'));

            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        // Check if the OASIS token is available using proper session handling.
        $oasis_token = $session->get('OasisAPIToken');
        if (empty($oasis_token)) {
            $this->messenger()->addWarning($this->t(
                'OASIS Token session is empty, will not be able to log into the OASIS site.'
            ));

            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        // Get configurable URLs from module configuration
        $config = $this->config('oasis_manager.settings');
        $base_url = $config->get('oasis_profile_base_url') ?: 'https://members.ajc-ajj.ca';

        // Determine the URL based on the current language.
        $profile_path = ($language === 'en')
            ? '/en/membership/update-member-profile'
            : '/fr/services-aux-membres/update-member-profile';

        $url = $base_url . $profile_path;

        // Validate the URL before redirecting
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->messenger()->addError($this->t('Invalid profile URL configuration.'));

            return new RedirectResponse(Url::fromRoute('<front>')->toString());
        }

        return new RedirectResponse($url);
    }


    /**
     * Displays the regular member area.
     *
     * @return array
     *   A render array for the regular member area.
     */
    public function regularMemberArea(): array
    {
        return [
            '#markup' => $this->t('Regular Member Area'),
            '#cache' => [
                'contexts' => ['user.roles'],
                'tags' => ['user:' . $this->currentUser()->id()]
            ]
        ];
    }


    /**
     * Displays the associate member area.
     *
     * @return array
     *   A render array for the associate member area.
     */
    public function associateMemberArea(): array
    {
        return [
            '#markup' => $this->t('Associate Member Area'),
            '#cache' => [
                'contexts' => ['user.roles'],
                'tags' => ['user:' . $this->currentUser()->id()]
            ]
        ];
    }


    /**
     * Access callback for the regular member area.
     *
     * @param \Drupal\Core\Session\AccountInterface $account
     *   The user account.
     *
     * @return \Drupal\Core\Access\AccessResult
     *   The access result.
     */
    public static function userIsRegularMember(AccountInterface $account): AccessResult
    {
        return AccessResult::allowedIf(
            in_array('regular_member', $account->getRoles(), true)
            || $account->hasPermission('administer users')
        )->addCacheContexts(['user.roles']);
    }


    /**
     * Access callback for the associate member area.
     *
     * @param \Drupal\Core\Session\AccountInterface $account
     *   The user account.
     *
     * @return \Drupal\Core\Access\AccessResult
     *   The access result.
     */
    public static function userIsAssociateMember(AccountInterface $account): AccessResult
    {
        return AccessResult::allowedIf(
            in_array('associate_member', $account->getRoles(), true)
            || $account->hasPermission('administer users')
        )->addCacheContexts(['user.roles']);
    }


    /**
     * Checks if a user has a member role.
     *
     * @param \Drupal\Core\Session\AccountInterface $account
     *   The user account.
     *
     * @return bool
     *   TRUE if the user has a member role, FALSE otherwise.
     */
    protected function userHasMemberRole(AccountInterface $account): bool
    {
        $roles = $account->getRoles();

        return in_array('regular_member', $roles, true)
            || in_array('associate_member', $roles, true);
    }

}

/* <> */
