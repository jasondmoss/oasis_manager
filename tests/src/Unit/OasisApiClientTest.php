<?php

namespace Drupal\Tests\oasis_manager\Unit;

/**
 * Unit tests for the OasisApiClient service.
 *
 * @link https://www.jdmlabs.com/
 *
 * @group oasis_manager
 * @coversDefaultClass \Drupal\oasis_manager\Service\OasisApiClient
 * @subpackage OASIS_MANAGER
 * @author Jason D. Moss <work@jdmlabs.com>
 * @copyright 2025 Jason D. Moss
 * @category Test
 * @package DRUPAL11
 */

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\oasis_manager\Service\OasisApiClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

class OasisApiClientTest extends UnitTestCase
{

    /**
     * The HTTP client prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected ClientInterface|ObjectProphecy $httpClient;

    /**
     * The logger factory prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected LoggerChannelFactoryInterface|ObjectProphecy $loggerFactory;

    /**
     * The logger channel prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected LoggerChannelInterface|ObjectProphecy $loggerChannel;

    /**
     * The config factory prophecy.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy
     */
    protected ObjectProphecy|ConfigFactoryInterface $configFactory;

    /**
     * The OASIS API client service.
     *
     * @var \Drupal\oasis_manager\Service\OasisApiClient
     */
    protected OasisApiClient $oasisApiClient;


    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up environment variables for testing.
        $_ENV['OASIS_API_USER_ENDPOINT'] = 'https://api.example.com/users/';
        $_ENV['OASIS_ADMIN_USER'] = 'admin';
        $_ENV['OASIS_ADMIN_PASSWORD'] = 'password';

        // Create prophecies for dependencies.
        $this->httpClient = $this->prophesize(ClientInterface::class);
        $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
        $this->loggerChannel = $this->prophesize(LoggerChannelInterface::class);
        $this->configFactory = $this->prophesize(ConfigFactoryInterface::class);

        // Set up the logger factory to return our logger channel.
        $this->loggerFactory->get('oasis_manager')->willReturn($this->loggerChannel->reveal());

        // Create the service with the mocked dependencies.
        $this->oasisApiClient = new OasisApiClient(
            $this->httpClient->reveal(),
            $this->loggerFactory->reveal(),
            $this->configFactory->reveal()
        );
    }


    /**
     * Tests successful authentication with the OASIS API.
     *
     * @covers ::authenticateUser
     * @throws \JsonException|\GuzzleHttp\Exception\GuzzleException
     */
    public function testAuthenticateUserSuccess(): void
    {
        // Mock a successful API response.
        $response = new Response(200, [], json_encode([
            'MemberID' => '12345',
            'FirstName' => 'John',
            'LastName' => 'Doe',
            'LoginID' => 'john.doe@example.com',
            'RegStatus' => 'ACTIVE',
            'RegCategory' => 'Regular Members',
            'OrchardRoles' => 'Governing Council',
            'OasisAPIToken' => 'valid-token',
        ], JSON_THROW_ON_ERROR));

        // Set up the HTTP client to return our mocked response.
        $this->httpClient
            ->request(
                'GET',
                'https://api.example.com/users/john.doe@example.com/password123',
                Argument::any()
            )
            ->willReturn($response);

        // Call the method under test.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');

        // Assert that the result is as expected.
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertIsObject($result['data']);
        $this->assertNull($result['error_type']);
        $this->assertEquals('12345', $result['data']->MemberID);
        $this->assertEquals('John', $result['data']->FirstName);
        $this->assertEquals('Doe', $result['data']->LastName);
        $this->assertEquals('john.doe@example.com', $result['data']->LoginID);
        $this->assertEquals('ACTIVE', $result['data']->RegStatus);
        $this->assertEquals('Regular Members', $result['data']->RegCategory);
        $this->assertEquals('Governing Council', $result['data']->OrchardRoles);
        $this->assertEquals('valid-token', $result['data']->OasisAPIToken);
    }


    /**
     * Tests authentication failure with the OASIS API.
     *
     * @covers ::authenticateUser
     * @throws \JsonException|\GuzzleHttp\Exception\GuzzleException
     */
    public function testAuthenticateUserFailure(): void
    {
        // Mock a failed API response.
        $response = new Response(200, [], json_encode([
            'error' => 'Invalid credentials',
        ], JSON_THROW_ON_ERROR));

        // Set up the HTTP client to return our mocked response.
        $this->httpClient
            ->request(
                'GET',
                'https://api.example.com/users/john.doe@example.com/wrongpassword',
                Argument::any()
            )
            ->willReturn($response);

        // Set up the logger to expect a notice.
        $this->loggerChannel
            ->notice('OASIS authentication failed for @email', [
                '@email' => 'john.doe@example.com'
            ])
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisApiClient
            ->authenticateUser('john.doe@example.com', 'wrongpassword');

        // Assert that the result indicates failure with invalid credentials.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_credentials', $result['error_type']);
    }


    /**
     * Tests error handling when the API request fails with a connection error.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserConnectException(): void
    {
        // Create a connect exception (API unavailable).
        $request = new Request('GET', 'https://api.example.com/users/john.doe@example.com/password123');
        $exception = new ConnectException('Connection refused', $request);

        // Set up the HTTP client to throw our exception.
        $this->httpClient
            ->request(
                'GET',
                'https://api.example.com/users/john.doe@example.com/password123',
                Argument::any()
            )
            ->willThrow($exception);

        // Set up the logger to expect an error.
        $this->loggerChannel
            ->error('Cannot connect to OASIS API: @error', [
                '@error' => 'Connection refused'
            ])
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');

        // Assert that the result indicates API unavailable.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('api_unavailable', $result['error_type']);
    }


    /**
     * Tests error handling when the API returns a server error.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserServerError(): void
    {
        // Create a request exception with 500 status code.
        $request = new Request('GET', 'https://api.example.com/users/john.doe@example.com/password123');
        $response = new Response(500, [], 'Internal Server Error');
        $exception = new RequestException('Server error', $request, $response);

        // Set up the HTTP client to throw our exception.
        $this->httpClient->request(
            'GET', 'https://api.example.com/users/john.doe@example.com/password123', Argument::any()
        )->willThrow($exception);

        // Set up the logger to expect an error.
        $this->loggerChannel->error(
            'OASIS API server error (@status): @error', ['@status' => 500, '@error' => 'Server error']
        )->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');

        // Assert that the result indicates API unavailable.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('api_unavailable', $result['error_type']);
    }


    /**
     * Tests error handling when the API returns a client error.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserClientError(): void
    {
        // Create a request exception with 401 status code.
        $request = new Request('GET', 'https://api.example.com/users/john.doe@example.com/password123');
        $response = new Response(401, [], 'Unauthorized');
        $exception = new RequestException('Client error', $request, $response);

        // Set up the HTTP client to throw our exception.
        $this->httpClient
            ->request(
                'GET',
                'https://api.example.com/users/john.doe@example.com/password123',
                Argument::any()
            )
            ->willThrow($exception);

        // Set up the logger to expect an error.
        $this->loggerChannel
            ->error('OASIS API client error (@status): @error', [
                    '@status' => 401,
                    '@error' => 'Client error'
                ]
            )
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');

        // Assert that the result indicates invalid credentials.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_credentials', $result['error_type']);
    }


    /**
     * Tests error handling when environment variables are missing.
     *
     * @covers ::getApiEndpoint
     */
    public function testMissingApiEndpoint(): void
    {
        // Remove the environment variable.
        unset($_ENV['OASIS_API_USER_ENDPOINT']);

        // Set up the logger to expect a critical error.
        $this->loggerChannel
            ->critical('OASIS_API_USER_ENDPOINT environment variable is not set')
            ->shouldBeCalled();

        // Expect a ServiceNotFoundException.
        $this->expectException(ServiceNotFoundException::class);

        // Call the method under test.
        $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');
    }


    /**
     * Tests error handling when authentication credentials are missing.
     *
     * @covers ::getAuthorizationHeader
     */
    public function testMissingAuthCredentials(): void
    {
        // Remove the environment variables.
        unset($_ENV['OASIS_ADMIN_USER']);
        unset($_ENV['OASIS_ADMIN_PASSWORD']);

        // Set up the logger to expect a critical error.
        $this->loggerChannel
            ->critical('OASIS_ADMIN_USER or OASIS_ADMIN_PASSWORD environment variables are not set')
            ->shouldBeCalled();

        // Expect a ServiceNotFoundException.
        $this->expectException(ServiceNotFoundException::class);

        // Call the method under test.
        $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');
    }


    /**
     * Tests input validation for empty email.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserEmptyEmail(): void
    {
        // Set up the logger to expect a warning.
        $this->loggerChannel
            ->warning('Empty email or password provided for OASIS authentication')
            ->shouldBeCalled();

        // Call the method under test with empty email.
        $result = $this->oasisApiClient->authenticateUser('', 'password123');

        // Assert that the result indicates invalid input.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_input', $result['error_type']);
    }


    /**
     * Tests input validation for empty password.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserEmptyPassword(): void
    {
        // Set up the logger to expect a warning.
        $this->loggerChannel
            ->warning('Empty email or password provided for OASIS authentication')
            ->shouldBeCalled();

        // Call the method under test with empty password.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', '');

        // Assert that the result indicates invalid input.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_input', $result['error_type']);
    }


    /**
     * Tests input validation for invalid email format.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserInvalidEmail(): void
    {
        // Set up the logger to expect a warning.
        $this->loggerChannel
            ->warning('Invalid email format provided for OASIS authentication: @email', [
                '@email' => 'invalid-email'
            ])
            ->shouldBeCalled();

        // Call the method under test with invalid email.
        $result = $this->oasisApiClient->authenticateUser('invalid-email', 'password123');

        // Assert that the result indicates invalid input.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_input', $result['error_type']);
    }


    /**
     * Tests JSON exception handling.
     *
     * @covers ::authenticateUser
     */
    public function testAuthenticateUserJsonException(): void
    {
        // Mock a response with invalid JSON.
        $response = new Response(200, [], 'invalid json');

        // Set up the HTTP client to return our mocked response.
        $this->httpClient
            ->request(
                'GET',
                'https://api.example.com/users/john.doe@example.com/password123',
                Argument::any()
            )
            ->willReturn($response);

        // Set up the logger to expect an error.
        $this->loggerChannel
            ->error('Invalid JSON response from OASIS API: @error', Argument::any())
            ->shouldBeCalled();

        // Call the method under test.
        $result = $this->oasisApiClient->authenticateUser('john.doe@example.com', 'password123');

        // Assert that the result indicates invalid response.
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('invalid_response', $result['error_type']);
    }

}

/* <> */
