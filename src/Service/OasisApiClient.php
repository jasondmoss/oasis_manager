<?php

declare(strict_types=1);

namespace Drupal\oasis_manager\Service;

/**
 * @file
 * Service for communicating with the OASIS API.
 *
 * @link https://www.jdmlabs.com/
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Module
 * @package DRUPAL11
 */

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class OasisApiClient
{

    /**
     * The HTTP client.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected ClientInterface $httpClient;

    /**
     * The logger factory.
     *
     * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
     */
    protected LoggerChannelFactoryInterface $loggerFactory;

    /**
     * The config factory.
     *
     * @var \Drupal\Core\Config\ConfigFactoryInterface
     */
    protected ConfigFactoryInterface $configFactory;


    /**
     * Constructs a new OasisApiClient object.
     *
     * @param \GuzzleHttp\ClientInterface $http_client
     *   The HTTP client.
     * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
     *   The logger factory.
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
     *   The config factory.
     */
    public function __construct(
        ClientInterface $http_client,
        LoggerChannelFactoryInterface $logger_factory,
        ConfigFactoryInterface $config_factory
    ) {
        $this->httpClient = $http_client;
        $this->loggerFactory = $logger_factory;
        $this->configFactory = $config_factory;
    }


    /**
     * Authenticates a user with the OASIS API.
     *
     * @param string $email
     *   The user's email address.
     * @param string $password
     *   The user's password.
     *
     * @return array
     *   An array with 'success' (bool), 'data' (object|null), and 'error_type' (string|null).
     *   Error types: 'api_unavailable', 'invalid_credentials', 'invalid_response', 'unknown'
     */
    public function authenticateUser(string $email, string $password): array
    {
        // Input validation.
        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'data' => null,
                'error_type' => 'invalid_input'
            ];
        }

        // Validate email format.
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'data' => null,
                'error_type' => 'invalid_input'
            ];
        }

        try {
            // Use GET request with credentials in URL as required by OASIS API.
            $response = $this->httpClient
                ->request('GET', $this->getApiEndpoint() . "$email/$password", [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => $this->getAuthorizationHeader()
                    ],
                    'timeout' => 30,
                    'connect_timeout' => 10
                ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, false, 512, JSON_THROW_ON_ERROR);

            // Validate response structure.
            if (! is_object($data)) {
                $this->loggerFactory
                    ->get('oasis_manager')
                    ->error('Invalid response format from OASIS API');

                return [
                    'success' => false,
                    'data' => null,
                    'error_type' => 'invalid_response'
                ];
            }

            // Check if we have a valid member ID.
            if (! empty($data->MemberID)) {
                // Sanitize the response data.
                $data = $this->sanitizeOasisData($data);

                return [
                    'success' => true,
                    'data' => $data,
                    'error_type' => null
                ];
            }

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'invalid_credentials'
            ];
        } catch (ConnectException $e) {
            // Connection failed - API is likely unavailable.
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Cannot connect to OASIS API: @error', [
                    '@error' => $e->getMessage()
                ]);

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'api_unavailable'
            ];
        } catch (RequestException $e) {
            // HTTP error (4xx, 5xx) - API responded but with an error.
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($statusCode >= 500) {
                // Server error - treat as API unavailable.
                $this->loggerFactory
                    ->get('oasis_manager')
                    ->error('OASIS API server error (@status): @error', [
                        '@status' => $statusCode,
                        '@error' => $e->getMessage()
                    ]);

                return [
                    'success' => false,
                    'data' => null,
                    'error_type' => 'api_unavailable'
                ];
            }

            // Client error - likely authentication issue.
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('OASIS API client error (@status): @error', [
                    '@status' => $statusCode,
                    '@error' => $e->getMessage()
                ]);

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'invalid_credentials'
            ];
        } catch (GuzzleException $e) {
            // Other Guzzle exceptions.
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Error communicating with OASIS API: @error', [
                    '@error' => $e->getMessage()
                ]);

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'api_unavailable'
            ];
        } catch (JsonException $e) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Invalid JSON response from OASIS API: @error', [
                    '@error' => $e->getMessage()
                ]);

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'invalid_response'
            ];
        } catch (Exception $e) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->error('Unexpected error during OASIS authentication: @error', [
                    '@error' => $e->getMessage()
                ]);

            return [
                'success' => false,
                'data' => null,
                'error_type' => 'unknown'
            ];
        }
    }


    /**
     * Sanitizes OASIS data to prevent XSS and other security issues.
     *
     * @param object $data
     *   The raw OASIS data.
     *
     * @return object
     *   The sanitized OASIS data.
     */
    protected function sanitizeOasisData(object $data): object
    {
        $sanitized = new \stdClass();

        // Sanitize string fields.
        $string_fields = [
            'MemberID',
            'FirstName',
            'LastName',
            'LoginID',
            'RegStatus',
            'RegCategory',
            'OrchardRoles'
        ];
        foreach ($string_fields as $field) {
            if (isset($data->$field)) {
                $sanitized->$field = htmlspecialchars(
                    trim((string) $data->$field), ENT_QUOTES, 'UTF-8'
                );
            }
        }

        // Handle OasisAPIToken separately (don't HTML encode tokens).
        if (isset($data->OasisAPIToken)) {
            $sanitized->OasisAPIToken = trim((string) $data->OasisAPIToken);
        }

        return $sanitized;
    }


    /**
     * Gets the API endpoint from environment variables.
     *
     * @return string
     *   The API endpoint URL.
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     *   Thrown if the required environment variable is not set.
     */
    protected function getApiEndpoint(): string
    {
        $endpoint = $_ENV['OASIS_API_USER_ENDPOINT'] ?? null;

        if (! $endpoint) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->critical('OASIS_API_USER_ENDPOINT environment variable is not set');

            throw new ServiceNotFoundException(
                'OASIS_API_USER_ENDPOINT', null, null, [],
                'Environment variable OASIS_API_USER_ENDPOINT is not set. Please '
                    . 'check your .env file.'
            );
        }

        return $endpoint;
    }


    /**
     * Gets the authorization header for API requests.
     *
     * @return string
     *   The authorization header value.
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     *   Thrown if the required environment variables are not set.
     */
    protected function getAuthorizationHeader(): string
    {
        $username = $_ENV['OASIS_ADMIN_USER'] ?? null;
        $password = $_ENV['OASIS_ADMIN_PASSWORD'] ?? null;

        if (! $username || ! $password) {
            $this->loggerFactory
                ->get('oasis_manager')
                ->critical('OASIS_ADMIN_USER or OASIS_ADMIN_PASSWORD environment variables are not set');

            throw new ServiceNotFoundException(
                'OASIS credentials', null, null, [],
                'Environment variables for OASIS credentials are not set. Please '
                    . 'check your .env file.'
            );
        }

        return 'Basic ' . base64_encode("$username:$password");
    }

}

/* <> */
