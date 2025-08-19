<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\Service;

/**
 * @file
 * Service for managing Drupal users based on OASIS data.
 *
 * @link https://www.jdmlabs.com/
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Module
 * @package DRUPAL11
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;
use Exception;
use Symfony\Component\HttpFoundation\RequestStack;

class OasisUserManager
{

    /**
     * The entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected EntityTypeManagerInterface $entityTypeManager;

    /**
     * The OASIS API client.
     *
     * @var \Drupal\oasis_manager\Service\OasisApiClient
     */
    protected OasisApiClient $oasisApiClient;

    /**
     * The current user.
     *
     * @var \Drupal\Core\Session\AccountProxyInterface
     */
    protected AccountProxyInterface $currentUser;

    /**
     * The logger factory.
     *
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
     */
    protected LoggerChannelFactoryInterface $loggerFactory;

    /**
     * The request stack.
     *
     * @var \Symfony\Component\HttpFoundation\RequestStack
     */
    protected RequestStack $requestStack;


    /**
     * Constructs a new OasisUserManager object.
     *
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   The entity type manager.
     * @param \Drupal\oasis_manager\Service\OasisApiClient $oasis_api_client
     *   The OASIS API client.
     * @param \Drupal\Core\Session\AccountProxyInterface $current_user
     *   The current user.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
     *   The logger factory.
     * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
     *   The request stack.
     */
    public function __construct(
        EntityTypeManagerInterface $entity_type_manager,
        OasisApiClient $oasis_api_client,
        AccountProxyInterface $current_user,
        LoggerChannelFactoryInterface $logger_factory,
        RequestStack $request_stack
    ) {
        $this->entityTypeManager = $entity_type_manager;
        $this->oasisApiClient = $oasis_api_client;
        $this->currentUser = $current_user;
        $this->loggerFactory = $logger_factory;
        $this->requestStack = $request_stack;
    }


    /**
     * Gets or creates a user based on OASIS member data.
     *
     * @param string $member_id
     *   The OASIS member ID.
     * @param string $password
     *   The user's password.
     * @param object $oasis_data
     *   The OASIS member data.
     *
     * @return \Drupal\user\Entity\User|null
     *   The user entity, or NULL if an error occurred.
     */
    public function getOrCreateUser(
        string $member_id,
        string $password,
        object $oasis_data
    ): ?User {
        try {
            // Try to load the user by member ID.
            $user = $this->getUserByMemberId($member_id);
            if (! $user) {
                // Create a new user if one doesn't exist.
                $user = $this->createUser($member_id, $password, $oasis_data);

                $this->loggerFactory
                    ->get('oasis_manager')
                    ->notice('Created new user from OASIS data: @email', [
                        '@email' => $oasis_data->LoginID
                    ]);
            }

            // Always sync the local Drupal password to the OASIS password used at login.
            $user->setPassword(trim($password));

            // Update the user with the latest OASIS data.
            $this->updateUserFromOasisData($user, $oasis_data);

            return $user;
        } catch (EntityStorageException $e) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Error creating or updating user from OASIS data: @error', [
                    '@error' => $e->getMessage()
                ]);

            return null;
        } catch (Exception $e) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Unexpected error processing OASIS user data: @error', [
                    '@error' => $e->getMessage()
                ]);

            return null;
        }
    }


    /**
     * Gets a user by OASIS member ID.
     *
     * @param string $member_id
     *
     * @return \Drupal\user\Entity\User|null
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    protected function getUserByMemberId(string $member_id): ?User
    {
        $user_ids = $this->entityTypeManager
            ->getStorage('user')
            ->getQuery()
            ->condition('field_member_id', $member_id)
            ->accessCheck(false)
            ->execute();

        if (empty($user_ids)) {
            return null;
        }

        $user_id = reset($user_ids);

        return User::load($user_id);
    }


    /**
     * Creates a new user from OASIS data.
     *
     * @param string $member_id
     *   The OASIS member ID.
     * @param string $password
     *   The user's password.
     * @param object $oasis_data
     *   The OASIS member data.
     *
     * @return \Drupal\user\Entity\User
     *   The new user entity.
     */
    protected function createUser(
        string $member_id,
        string $password,
        object $oasis_data
    ): User {
        $user = User::create([
            'name' => "{$oasis_data->FirstName}-{$oasis_data->LastName}-{$member_id}",
            'pass' => trim($password),
            'mail' => $oasis_data->LoginID,
            'status' => (strtoupper($oasis_data->RegStatus) === 'ACTIVE'),
            'roles' => ['authenticated'],
        ]);

        $user->enforceIsNew();
        $user->activate();

        return $user;
    }


    /**
     * Updates a user with OASIS data.
     *
     * @param \Drupal\user\Entity\User $user
     *   The user to update.
     * @param object $oasis_data
     *   The OASIS member data.
     *
     * @throws \Drupal\Core\Entity\EntityStorageException
     *   Thrown if the user cannot be saved.
     */
    protected function updateUserFromOasisData(User $user, object $oasis_data): void
    {
        //echo "<pre>";
        //var_dump($oasis_data);
        //echo "</pre>";
        //exit();

        // Update basic user fields.
        $desired_email = $oasis_data->LoginID ?? '';
        if (! empty($desired_email) && $desired_email !== $user->getEmail()) {
            // Check for email conflict before updating to avoid save exceptions.
            $conflict_ids = $this->entityTypeManager
                ->getStorage('user')
                ->getQuery()
                ->condition('mail', $desired_email)
                ->condition('uid', $user->id(), '<>')
                ->accessCheck(false)
                ->range(0, 1)
                ->execute();

            if (! empty($conflict_ids)) {
                $this->loggerFactory
                    ->get('oasis_manager')
                    ->warning('Skipped updating email for user @uid from OASIS because @email is already in use by another account.', [
                        '@uid' => $user->id(),
                        '@email' => $desired_email
                    ]);
            } else {
                $user->setEmail($desired_email);
            }
        }

        $user->set('field_first_name', $oasis_data->FirstName);
        $user->set('field_last_name', $oasis_data->LastName);
        $user->set('field_member_id', $oasis_data->MemberID);

        // Update user status based on OASIS status.
        if (strtoupper($oasis_data->RegStatus) === 'ACTIVE') {
            $user->activate();
        } else {
            $user->block();
        }

        // Assign roles based on OASIS data.
        $this->assignUserRoles($user, $oasis_data);

        // Save the updated user.
        $user->save();
    }


    /**
     * Assigns roles to a user based on OASIS data.
     *
     * @param \Drupal\user\Entity\User $user
     *   The user to update.
     * @param object $oasis_data
     *   The OASIS member data.
     */
    protected function assignUserRoles(User $user, object $oasis_data): void
    {
        // Assign base role based on member category.
        switch ($oasis_data->RegCategory) {
            case 'Regular Members':
                $user->addRole('regular_member');
                break;

            case 'Associate Members':
                $user->addRole('associate_member');
                break;
        }

        // Assign additional roles based on OASIS roles.
        if (! empty($oasis_data->OrchardRoles)) {
            $orchard_roles = explode(',', $oasis_data->OrchardRoles);

            if (in_array('Governing Council', $orchard_roles, true)) {
                $user->addRole('governing_council');
            }

            if (in_array('Executive Committee', $orchard_roles, true)) {
                $user->addRole('executive_committee');
            }
        }
    }


    /**
     * Sets OASIS session variables after successful authentication.
     *
     * Note: This method no longer calls user_login_finalize() to prevent
     * duplicate session logging. Drupal's core login process will handle
     * the actual user login finalization.
     *
     * @param \Drupal\user\Entity\User $user
     *   The authenticated user.
     * @param object $oasis_data
     *   The OASIS member data.
     *
     * @return bool
     *   TRUE if session setup was successful, FALSE otherwise.
     */
    public function finalizeLogin(User $user, object $oasis_data): bool
    {
        // Don't overwrite an admin session.
        if ($this->currentUser->isAuthenticated()
            && $this->currentUser->hasPermission('administer users')
        ) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->notice('Admin OASIS login attempt, not overwriting admin session');

            return false;
        }

        // Get the current request and session.
        if (! ($request = $this->requestStack->getCurrentRequest())) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('No current request available for session handling');

            return false;
        }

        if (! ($session = $request->getSession())) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('No session available for OASIS data storage');

            return false;
        }

        // Set session variables using proper session handling.
        $session->set('member', $oasis_data->MemberID ?? '');
        $session->set('OasisAPIToken', $oasis_data->OasisAPIToken ?? '');
        $session->set('OasisRegCategory', $oasis_data->RegCategory ?? '');

        if (! empty($oasis_data->OrchardRoles)) {
            $session->set('OasisOrchardRoles', $oasis_data->OrchardRoles);
        }

        // Log successful finalization for auditing and to satisfy unit test expectations.
        $this->loggerFactory
            ->get('oasis_manager')
            ->info('OASIS user login finalized for member @member_id', [
                '@member_id' => $oasis_data->MemberID ?? ''
            ]);

        return true;
    }

}

/* <> */
