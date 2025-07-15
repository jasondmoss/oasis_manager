# Oasis Manager Module

## Overview

The Oasis Manager module provides integration between Drupal and the OASIS/NAF (National Administration Framework) system for user authentication and authorization. It allows users to log in to the Drupal site using their OASIS credentials, synchronizes user roles and permissions, and provides custom routes for different member types.

## Features

- Login to Drupal using OASIS/NAF credentials
- Email-based authentication instead of username
- Automatic user creation and synchronization with OASIS
- Role-based access control for different member types (Regular Members, Associate Members)
- Special role handling for Governing Council and Executive Committee members
- Custom logout and profile management routes with intelligent redirects
- Bilingual support (English/French) for redirects to the OASIS system
- Session management for OASIS tokens

## Requirements

- Drupal 11
- PHP 8.3 or higher (tested with PHP 8.3 and 8.4)
- Environment variables for OASIS API configuration

## Installation

1. Place the module in your Drupal installation's `modules/custom` directory.
2. Configure the required environment variables (see Configuration section).
3. Enable the module via Drush: `drush en oasis_manager` or through the Drupal admin interface.

### What Happens During Installation

When the module is installed, it automatically:

- **Creates User Roles**: Creates the required `regular_member` and `associate_member` user roles
- **Installs Default Configuration**: Sets up default configuration values for:
  - OASIS Profile Base URL: `https://members.ajc-ajj.ca`
  - API Timeout: 30 seconds
  - API Connect Timeout: 10 seconds
- **Validates Environment**: Checks that required environment variables are configured
- **Logs Installation**: Records successful installation in the system logs

### Requirements Checking

The module includes a requirements hook that monitors:

- **Environment Variables**: Ensures all required OASIS API environment variables are set
- **User Roles**: Verifies that required user roles exist
- **Configuration**: Checks that module configuration is complete

You can view these requirements on the Status Report page (`/admin/reports/status`).

## Uninstallation

When the module is uninstalled, it automatically:

- **Removes Configuration**: Deletes all module-specific configuration settings
- **Preserves User Data**: User roles are only removed if they have no users assigned to prevent data loss
- **Logs Uninstallation**: Records successful uninstallation in the system logs

### Safe Uninstallation Process

The uninstallation process is designed to be safe:

1. **Configuration Cleanup**: All `oasis_manager.settings` configuration is removed
2. **Role Preservation**: User roles (`regular_member`, `associate_member`) are preserved if any users are assigned to them
3. **Data Protection**: No user accounts or user data are deleted during uninstallation

To completely remove all traces of the module:

1. First, manually remove users from the `regular_member` and `associate_member` roles
2. Then uninstall the module via Drush: `drush pmu oasis_manager` or through the Drupal admin interface
3. The roles will be automatically deleted if no users are assigned to them

## Configuration

The module requires the following environment variables to be set in your `.env` file:

```
OASIS_API_USER_ENDPOINT=https://api.example.com/users/
OASIS_ADMIN_USER=your_admin_username
OASIS_ADMIN_PASSWORD=your_admin_password
```

No additional configuration is needed through the Drupal admin interface.

## Technical Implementation

### Architecture

The module follows a service-oriented architecture with the following components:

#### Services

- **OasisApiClient**: Handles communication with the OASIS API
- **OasisUserManager**: Manages user creation, updates, and synchronization
- **OasisAuthenticationService**: Handles authentication with OASIS

#### Event Subscribers

- **OasisManagerEventSubscriber**: Subscribes to Drupal events for authentication and session management

#### Controller

- **OasisManagerController**: Handles routes for logout, profile redirection, and member areas

### Integration with Drupal

The module integrates with Drupal through:

- Form alters for login forms
- Custom validation and submission handlers
- Event subscribers for request handling
- Custom routes and access control

### Integration with OASIS/NAF

The module communicates with the OASIS API to:

- Authenticate user credentials
- Retrieve member information (ID, name, status, category, roles)
- Obtain API tokens for session management

### Logout Redirect Behavior

The module provides intelligent logout redirects based on user type:

- **OASIS Members**: Users who logged in through OASIS authentication (identified by the presence of the 'member' session flag) are redirected to `node/490` upon logout
- **All Other Users**: Regular Drupal users, administrators, and staff members are redirected to the front page (`<front>`) upon logout

This behavior is implemented in the custom logout route `/oasis-logout` and ensures that OASIS members are directed to the appropriate logout page while maintaining standard Drupal behavior for other user types.

## Security Considerations

- All API communication uses HTTPS with proper timeout configurations
- API credentials are stored in environment variables, not in the database
- Password handling follows Drupal's security best practices
- Comprehensive input validation is performed on all user input including email format validation
- User credentials are sent via POST requests with JSON body instead of GET parameters for enhanced security
- All OASIS data is sanitized to prevent XSS attacks
- Session tokens are validated on each request using proper Drupal session handling
- Proper session management prevents direct $_SESSION manipulation
- URL validation prevents malicious redirects
- Request timeouts prevent hanging connections

## Testing

The module includes both unit and functional tests:

- **Unit Tests**: Test the API client and authentication services
- **Functional Tests**: Test the integration with Drupal, including form alters, authentication, and routes

Run tests using PHPUnit:

```
vendor/bin/phpunit web/modules/custom/oasis_manager
```

## Troubleshooting

### Common Issues

1. **Authentication Failures**:
   - Check that the OASIS API is accessible
   - Verify that the environment variables are correctly set
   - Check the Drupal logs for specific error messages

2. **Missing User Roles**:
   - Ensure that the required roles exist in Drupal
   - Check that the OASIS data includes the expected role information

3. **Session Issues**:
   - Clear the Drupal cache
   - Check that session cookies are being properly set

## Maintainers

- Your Name <your.email@example.com>

## License

This module is licensed under the GPL v2 or later.

Copyright (c) 2025.
