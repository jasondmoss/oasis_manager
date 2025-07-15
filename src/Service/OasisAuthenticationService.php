<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\Service;

/**
 * @file
 * Service for handling OASIS authentication.
 *
 * @link https://www.jdmlabs.com/
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Module
 * @package DRUPAL11
 */

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserAuthenticationInterface;
use Exception;

class OasisAuthenticationService
{

    /**
     * The OASIS API client.
     *
     * @var \Drupal\oasis_manager\Service\OasisApiClient
     */
    protected OasisApiClient $oasisApiClient;

    /**
     * The OASIS user manager.
     *
     * @var \Drupal\oasis_manager\Service\OasisUserManager
     */
    protected OasisUserManager $oasisUserManager;

    /**
     * The logger factory.
     *
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
     */
    protected LoggerChannelFactoryInterface $loggerFactory;

    /**
     * The messenger service.
     *
     * @var \Drupal\Core\Messenger\MessengerInterface
     */
    protected MessengerInterface $messenger;

    /**
     * The user authentication service.
     *
     * @var \Drupal\user\UserAuthenticationInterface
     */
    protected UserAuthenticationInterface $userAuth;


    /**
     * Constructs a new OasisAuthenticationService object.
     *
     * @param \Drupal\oasis_manager\Service\OasisApiClient $oasis_api_client
     *   The OASIS API client.
     * @param \Drupal\oasis_manager\Service\OasisUserManager $oasis_user_manager
     *   The OASIS user manager.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
     *   The logger factory.
     * @param \Drupal\Core\Messenger\MessengerInterface $messenger
     *   The messenger service.
     * @param \Drupal\user\UserAuthenticationInterface $user_auth
     *   The user authentication service.
     */
    public function __construct(
        OasisApiClient $oasis_api_client,
        OasisUserManager $oasis_user_manager,
        LoggerChannelFactoryInterface $logger_factory,
        MessengerInterface $messenger,
        UserAuthenticationInterface $user_auth
    ) {
        $this->oasisApiClient = $oasis_api_client;
        $this->oasisUserManager = $oasis_user_manager;
        $this->loggerFactory = $logger_factory;
        $this->messenger = $messenger;
        $this->userAuth = $user_auth;
    }


    /**
     * Validates a login attempt using OASIS credentials.
     *
     * This method is called from the form validation handler and handles the
     * authentication process, including checking for existing users, validating
     * credentials with OASIS, and creating or updating users as needed.
     *
     * @param array $form
     *   The form array.
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The form state.
     */
    public function validateLogin(array &$form, FormStateInterface $form_state): void
    {
        $name_or_email = $form_state->getValue('name');
        $password = $form_state->getValue('pass');

        // Check if this is a member login attempt.
        $is_member = true;
        $email = $name_or_email;

        // Check if input is an email address.
        if (! str_contains($name_or_email, '@')) {
            // Input is a username, try to load user by username.
            $user = user_load_by_name($name_or_email);
            if ($user) {
                // Get the email address for this user.
                $email = $user->getEmail();

                // Check if the user has member roles.
                if (! $this->userHasMemberRole($user)) {
                    $is_member = false;
                }
            }
        } else {
            // Input is an email, try loading the user by email.
            $user = user_load_by_mail($name_or_email);
            if ($user) {
                $form_state->setValue('name', $user->getAccountName());

                // Check if the user has member roles.
                if (! $this->userHasMemberRole($user)) {
                    $is_member = false;
                }
            }
        }

        // If this is a member login or we don't have a local user, try OASIS authentication.
        if ($is_member || ! $user) {
            if ($this->authenticateWithOasis($form_state, $email, $password)) {
                // Authentication successful, no need for further validation.
                $form_state->setValidationComplete();

                return;
            }
        }

        // If we have a local non-member user, authenticate them with Drupal's standard method.
        if ($user && ! $is_member) {
            // Authenticate the user using Drupal's standard authentication.
            if ($this->userAuth->authenticateAccount($user, $password)) {
                // Set form state values for successful login.
                $form_state->set('uid', $user->id());
                $form_state->set('user', $user);
                $form_state->setValidationComplete();

                return;
            } else {
                // Password is incorrect for non-member user.
                $form_state->setErrorByName(
                    'name',
                    t('Unrecognized username/email address or password.')
                );

                return;
            }
        }

        // If we get here, authentication failed.
        $form_state->setErrorByName(
            'name',
            t('Unrecognized username/email address or password.')
        );
    }


    /**
     * Checks if a user has a member role.
     *
     * @param \Drupal\user\Entity\User $user
     *   The user to check.
     *
     * @return bool
     *   TRUE if the user has a member role, FALSE otherwise.
     */
    protected function userHasMemberRole(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array('regular_member', $roles, true)
            || in_array('associate_member', $roles, true);
    }


    /**
     * Authenticates a user with OASIS.
     *
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     *   The form state.
     * @param string $email
     *   The user's email address.
     * @param string $password
     *   The user's password.
     *
     * @return bool
     *   TRUE if authentication was successful, FALSE otherwise.
     */
    protected function authenticateWithOasis(
        FormStateInterface $form_state,
        string $email,
        string $password
    ): bool {
        try {
            // Authenticate with OASIS API.
            $auth_result = $this->oasisApiClient->authenticateUser($email, $password);

            if (! $auth_result['success']) {
                // Handle different types of authentication failures
                switch ($auth_result['error_type']) {
                    case 'api_unavailable':
                        $this->messenger->addError(t(
                            'The OASIS authentication service is temporarily unavailable: <a href="@url">@url</a>. '
                            . 'Please try again later or contact support if the problem persists.', [
                                '@url' => 'https://ajcapi.azurewebsites.net/'
                            ]
                        ));
                        $this->loggerFactory
                            ->get('oasis_manager')
                            ->warning('OASIS API unavailable during login attempt for @email', [
                                '@email' => $email
                            ]);
                        break;

                    case 'invalid_credentials':
                        /**
                         * Don't show specific error message for invalid credentials.
                         * Let the generic message handle it.
                         */
                        break;

                    case 'invalid_response':
                        $this->messenger->addError(t(
                            'There was a problem communicating with the authentication '
                            . 'service. Please try again later.'
                        ));
                        $this->loggerFactory
                            ->get('oasis_manager')
                            ->warning('Invalid response from OASIS API during login attempt for @email', [
                                '@email' => $email
                            ]);
                        break;

                    case 'invalid_input':
                        // Input validation errors are already logged, no user message needed
                        break;

                    default:
                        $this->messenger->addError(t(
                            'An unexpected error occurred during authentication. '
                            . 'Please try again later.'
                        ));
                        $this->loggerFactory
                            ->get('oasis_manager')
                            ->warning(
                                'Unknown error type during OASIS authentication for @email: @error_type', [
                                    '@email' => $email,
                                    '@error_type' => $auth_result['error_type'] ?? 'unknown'
                                ]
                            );
                        break;
                }

                return false;
            }

            $oasis_data = $auth_result['data'];
            if (! $oasis_data || empty($oasis_data->MemberID)) {
                return false;
            }

            // Get or create the user.
            $user = $this->oasisUserManager
                ->getOrCreateUser($oasis_data->MemberID, $password, $oasis_data);

            if (! $user) {
                $this->loggerFactory
                    ->get('oasis_manager')
                    ->error('Failed to create or update user from OASIS data');
                $this->messenger
                    ->addError(t(
                        'There was a problem setting up your account. Please contact support.'
                    ));

                return false;
            }

            // Set form state values for login.
            $form_state->set('uid', $user->id());
            $form_state->set('user', $user);
            $form_state->set('member', true);

            // Finalize login.
            $this->oasisUserManager->finalizeLogin($user, $oasis_data);

            return true;
        } catch (Exception $e) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error(
                    'Error during OASIS authentication: @error', [
                        '@error' => $e->getMessage()
                    ]
                );
            $this->messenger->addError(t(
                'An unexpected error occurred during authentication. Please try again later.'
            ));

            return false;
        }
    }

}

/* <> */
