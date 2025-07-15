<?php

namespace Drupal\Tests\oasis_manager\Functional;

/**
 * @file
 * Tests the functionality of the Oasis Manager module.
 *
 * @link https://www.jdmlabs.com/
 *
 * @group oasis_manager
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Test
 * @package DRUPAL11
 */

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;

class OasisManagerFunctionalTest extends BrowserTestBase
{

    /**
     * {@inheritdoc}
     */
    protected $defaultTheme = 'stark';

    /**
     * {@inheritdoc}
     */
    protected static $modules = [
        'oasis_manager',
        'user',
        'field',
        'system',
    ];

    /**
     * A regular member user.
     *
     * @var \Drupal\user\UserInterface
     */
    protected UserInterface $regularMemberUser;

    /**
     * An associate member user.
     *
     * @var \Drupal\user\UserInterface
     */
    protected UserInterface $associateMemberUser;

    /**
     * A non-member user.
     *
     * @var \Drupal\user\UserInterface
     */
    protected UserInterface $nonMemberUser;

    /**
     * An admin user.
     *
     * @var \Drupal\user\UserInterface
     */
    protected UserInterface $adminUser;


    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create the required roles.
        $this->createRoles();

        // Create test users.
        $this->createUsers();

        // Set up environment variables.
        putenv('OASIS_API_USER_ENDPOINT=https://api.example.com/users/');
        putenv('OASIS_ADMIN_USER=admin');
        putenv('OASIS_ADMIN_PASSWORD=password');
    }


    /**
     * Creates the roles needed for testing.
     */
    protected function createRoles(): void
    {
        // Create regular member role.
        $regular_member_role = Role::create([
            'id' => 'regular_member',
            'label' => 'Regular Member',
        ]);
        $regular_member_role->save();

        // Create associate member role.
        $associate_member_role = Role::create([
            'id' => 'associate_member',
            'label' => 'Associate Member',
        ]);
        $associate_member_role->save();

        // Create governing council role.
        $governing_council_role = Role::create([
            'id' => 'governing_council',
            'label' => 'Governing Council',
        ]);
        $governing_council_role->save();

        // Create executive committee role.
        $executive_committee_role = Role::create([
            'id' => 'executive_committee',
            'label' => 'Executive Committee',
        ]);
        $executive_committee_role->save();
    }


    /**
     * Creates the users needed for testing.
     */
    protected function createUsers(): void
    {
        // Create a regular member user.
        $this->regularMemberUser = User::create([
            'name' => 'regular_member_user',
            'mail' => 'regular@example.com',
            'pass' => 'password',
            'status' => 1,
            'roles' => ['regular_member'],
            'field_member_id' => '12345',
            'field_first_name' => 'Regular',
            'field_last_name' => 'Member',
        ]);
        $this->regularMemberUser->save();

        // Create an associate member user.
        $this->associateMemberUser = User::create([
            'name' => 'associate_member_user',
            'mail' => 'associate@example.com',
            'pass' => 'password',
            'status' => 1,
            'roles' => ['associate_member'],
            'field_member_id' => '67890',
            'field_first_name' => 'Associate',
            'field_last_name' => 'Member',
        ]);
        $this->associateMemberUser->save();

        // Create a non-member user.
        $this->nonMemberUser = User::create([
            'name' => 'non_member_user',
            'mail' => 'non_member@example.com',
            'pass' => 'password',
            'status' => 1,
        ]);
        $this->nonMemberUser->save();

        // Create an admin user.
        $this->adminUser = User::create([
            'name' => 'admin_user',
            'mail' => 'admin@example.com',
            'pass' => 'password',
            'status' => 1,
            'roles' => ['administrator'],
        ]);
        $this->adminUser->save();
    }


    /**
     * Tests that the login form has been altered correctly.
     */
    public function testLoginFormAlter(): void
    {
        // Visit the login page.
        $this->drupalGet('user/login');

        // Check that the username field has been renamed to "Email address".
        $this->assertSession()->fieldExists('Email address');
        $this->assertSession()->pageTextContains('Enter email address.');
    }


    /**
     * Tests access to the regular member area.
     */
    public function testRegularMemberAreaAccess(): void
    {
        // Test access for anonymous users.
        $this->drupalGet('regular-member-area');
        $this->assertSession()->statusCodeEquals(403);

        // Test access for regular member users.
        $this->drupalLogin($this->regularMemberUser);
        $this->drupalGet('regular-member-area');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Regular Member Area');
        $this->drupalLogout();

        // Test access for associate member users.
        $this->drupalLogin($this->associateMemberUser);
        $this->drupalGet('regular-member-area');
        $this->assertSession()->statusCodeEquals(403);
        $this->drupalLogout();

        // Test access for non-member users.
        $this->drupalLogin($this->nonMemberUser);
        $this->drupalGet('regular-member-area');
        $this->assertSession()->statusCodeEquals(403);
        $this->drupalLogout();

        // Test access for admin users.
        $this->drupalLogin($this->adminUser);
        $this->drupalGet('regular-member-area');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Regular Member Area');
        $this->drupalLogout();
    }


    /**
     * Tests access to the associate member area.
     */
    public function testAssociateMemberAreaAccess(): void
    {
        // Test access for anonymous users.
        $this->drupalGet('associate-member-area');
        $this->assertSession()->statusCodeEquals(403);

        // Test access for regular member users.
        $this->drupalLogin($this->regularMemberUser);
        $this->drupalGet('associate-member-area');
        $this->assertSession()->statusCodeEquals(403);
        $this->drupalLogout();

        // Test access for associate member users.
        $this->drupalLogin($this->associateMemberUser);
        $this->drupalGet('associate-member-area');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Associate Member Area');
        $this->drupalLogout();

        // Test access for non-member users.
        $this->drupalLogin($this->nonMemberUser);
        $this->drupalGet('associate-member-area');
        $this->assertSession()->statusCodeEquals(403);
        $this->drupalLogout();

        // Test access for admin users.
        $this->drupalLogin($this->adminUser);
        $this->drupalGet('associate-member-area');
        $this->assertSession()->statusCodeEquals(200);
        $this->assertSession()->pageTextContains('Associate Member Area');
        $this->drupalLogout();
    }


    /**
     * Tests the profile redirection functionality.
     */
    public function testProfileRedirection(): void
    {
        // Test access for anonymous users.
        $this->drupalGet('oasis-profile');
        $this->assertSession()->statusCodeEquals(403);

        // Test access for regular member users.
        $this->drupalLogin($this->regularMemberUser);

        // Set the session variables needed for redirection.
        $this->getSession()->setCookie('OasisAPIToken', 'valid-token');
        $_SESSION['OasisAPIToken'] = 'valid-token';

        // Visit the profile page.
        $this->drupalGet('oasis-profile');

        // Since we can't actually test the external redirect in a functional test,
        // we'll just check that we don't get an error message.
        $this->assertSession()->pageTextNotContains('You are not logged in as an OASIS user');
        $this->assertSession()->pageTextNotContains('OASIS Token session is empty');

        $this->drupalLogout();
    }


    /**
     * Tests the logout functionality.
     */
    public function testLogout(): void
    {
        // Test access for anonymous users.
        $this->drupalGet('oasis-logout');
        $this->assertSession()->statusCodeEquals(403);

        // Test logout for regular member users (should redirect to front page).
        $this->drupalLogin($this->regularMemberUser);
        $this->drupalGet('oasis-logout');

        // Regular users should be redirected to the front page.
        $this->assertSession()->addressEquals('/');

        // Test logout for non-member users (should redirect to front page).
        $this->drupalLogin($this->nonMemberUser);
        $this->drupalGet('oasis-logout');

        // Non-member users should be redirected to the front page.
        $this->assertSession()->addressEquals('/');

        // Test logout for admin users (should redirect to front page).
        $this->drupalLogin($this->adminUser);
        $this->drupalGet('oasis-logout');

        // Admin users should be redirected to the front page.
        $this->assertSession()->addressEquals('/');
    }


    /**
     * Tests the logout functionality for OASIS members.
     */
    public function testOasisMemberLogout(): void
    {
        // Create a test node to redirect to (node/490).
        $node = $this->drupalCreateNode([
            'type' => 'page',
            'title' => 'OASIS Logout Page',
            'nid' => 490,
        ]);

        // Test logout for OASIS member (should redirect to node/490).
        $this->drupalLogin($this->regularMemberUser);

        // Simulate OASIS session data by setting the session variable.
        // Note: In a real scenario, this would be set during OASIS authentication.
        $session = $this->getSession();
        $session->setCookie('member', '19705');

        // Set session data using Drupal's session service.
        $tempstore = \Drupal::service('tempstore.private')->get('oasis_manager');
        $tempstore->set('member', '19705');

        $this->drupalGet('oasis-logout');

        // OASIS members should be redirected to node/490.
        $this->assertSession()->addressEquals('/node/490');
    }

}

/* <> */
