<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\Hook;

use Drupal;

// For \Drupal::service(), ::currentUser(), ::languageManager().
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Hook implementations for oasis_manager.
 */
class OasisManagerHooks
{

    use StringTranslationTrait;

    /**
     * Implements hook_help().
     *
     * @param string $route_name
     * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
     *
     * @return string|null
     */
    #[Hook('help')]
    public function help(string $route_name, RouteMatchInterface $route_match): ?string
    {
        $langcode = Drupal::languageManager()->getCurrentLanguage()->getId();

        if ($route_name === 'help.page.oasis_manager') {
            $description = match ($langcode) {
                'fr' => <<<DESC
Le module Oasis Manager assure l’intégration entre Drupal et le système OASIS/NAF à
des fins d’authentification et d’autorisation des utilisateurs. Il permet aux
utilisateurs de se connecter au site Drupal à l’aide de leurs identifiants OASIS et
synchronise les rôles et les permissions des utilisateurs.
DESC,
                default => <<<DESC
The Oasis Manager module provides integration between Drupal and the OASIS/NAF system
for user authentication and authorization. It allows users to log in to the Drupal site
using their OASIS credentials, synchronizes user roles and permissions.
DESC,
            };

            $output = '<h3>' . $this->t('About') . '</h3>';
            $output .= "<p>$description</p>";

            return $output;
        }

        return null;
    }


    /**
     * Implements hook_form_FORM_ID_alter() for user_login_form.
     *
     * @param array $form
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     * @param string|null $form_id
     */
    #[Hook('form_user_login_form_alter')]
    public function formUserLoginFormAlter(
        array &$form,
        FormStateInterface $form_state,
        string $form_id = null
    ): void {
        $form['name']['#title'] = $this->t('Email address or username');
        $form['name']['#description'] = $this->t('Enter your email address or username.');

        /**
         * Replace all core validation handlers to prevent conflicting messages. Use
         * a static method reference to avoid storing objects in cached forms.
         */
        $form['#validate'] = [[self::class, 'userLoginValidate']];
    }


    /**
     * Implements hook_form_FORM_ID_alter() for user_login_block_form.
     *
     * @param array $form
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     * @param string|null $form_id
     */
    #[Hook('form_user_login_block_alter')]
    public function formUserLoginBlockAlter(
        array &$form,
        FormStateInterface $form_state,
        string $form_id = null
    ): void {
        $form['name']['#title'] = $this->t('Email address or username');

        // Replace all core validation handlers to prevent conflicting messages.
        $form['#validate'] = [[self::class, 'userLoginValidate']];
    }


    /**
     * Validation handler for user login forms used by this module.
     *
     * This delegates validation to the OasisAuthenticationService.
     *
     * @param array $form
     * @param \Drupal\Core\Form\FormStateInterface $form_state
     */
    public static function userLoginValidate(array &$form, FormStateInterface $form_state): void
    {
        /** @var \Drupal\oasis_manager\Service\OasisAuthenticationService $auth_service */
        $auth_service = Drupal::service('oasis_manager.authentication');
        $auth_service->validateLogin($form, $form_state);
    }


    /**
     * Implements hook_preprocess_menu().
     *
     * @param array $variables
     */
    #[Hook('preprocess_menu')]
    public function preprocessMenu(array &$variables): void
    {
        // Only alter the "User account menu".
        if (($variables['menu_name'] ?? '') !== 'account') {
            return;
        }

        $current_user = Drupal::currentUser();
        if ($current_user->isAnonymous()) {
            return;
        }

        $roles = $current_user->getRoles();
        $is_oasis_member = in_array('regular_member', $roles, true)
            || in_array('associate_member', $roles, true);

        if (! $is_oasis_member) {
            return;
        }

        if (! isset($variables['items']) || ! is_array($variables['items'])) {
            return;
        }

        foreach ($variables['items'] as &$item) {
            if (! isset($item['url'])) {
                continue;
            }

            $url = $item['url'];
            if ($url instanceof Url && $url->isRouted()
                && $url->getRouteName() === 'user.page'
            ) {
                // Rename the link and point it to our redirect route.
                $item['title'] = $this->t('Member Profile');
                $item['url'] = Url::fromRoute('oasis_manager.member_profile_redirect');

                // Ensure caching varies by user roles so only members see the change.
                $item['cache']['contexts'][] = 'user.roles';
            }
        }
    }

}

/* <> */
