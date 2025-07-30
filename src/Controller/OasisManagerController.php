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
use Drupal\user\UserAuthInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class OasisManagerController extends ControllerBase
{

    /**
     * The user authentication service.
     *
     * @var \Drupal\user\UserAuthInterface
     */
    protected UserAuthInterface $userAuth;


    /**
     * Constructs a new OasisManagerController object.
     *
     * @param \Drupal\user\UserAuthInterface $user_auth
     *   The user authentication service.
     */
    public function __construct(UserAuthInterface $user_auth)
    {
        $this->userAuth = $user_auth;
    }


    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('user.auth')
        );
    }


    /**
     * Logs out the current user.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     *   The current request.
     */
    public function logout(Request $request): void
    {
        // Log out the user (this will clear the session)
        $this->userAuth->logout();
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
    public static function userIsAssociateMember(
        AccountInterface $account
    ): AccessResult {
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
