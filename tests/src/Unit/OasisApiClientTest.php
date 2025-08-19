<?php

declare(strict_types=1);

namespace Drupal\Tests\oasis_manager\Unit;

use Drupal\oasis_manager\Service\OasisApiClient;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @covers \Drupal\oasis_manager\Service\OasisApiClient
 * @group oasis_manager
 */
class OasisApiClientTest extends TestCase
{
    private function makeSut(?ClientInterface $client = null): OasisApiClient
    {
        $client = $client ?? $this->createMock(ClientInterface::class);
        $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory->method('get')->willReturn($logger);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);

        // Ensure required env vars exist for each test unless overridden.
        $_ENV['OASIS_API_USER_ENDPOINT'] = 'https://api.example.com/auth/';
        $_ENV['OASIS_ADMIN_USER'] = 'admin';
        $_ENV['OASIS_ADMIN_PASSWORD'] = 'secret';

        return new OasisApiClient($client, $loggerFactory, $configFactory);
    }


    public function testAuthenticateUserSuccessAndSanitization(): void
    {
        $payload = [
            'MemberID' => ' 123 ',
            'FirstName' => ' Alice ',
            'LastName' => ' <Bob> ',
            'LoginID' => 'user@example.com',
            'RegStatus' => 'ACTIVE',
            'RegCategory' => 'Regular Members',
            'OrchardRoles' => 'Executive Committee,Governing Council',
            'OasisAPIToken' => 'tok-123',
        ];
        $responseBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $response = new Response(200, [], $responseBody);
        $client = $this->createMock(ClientInterface::class);
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->callback(fn ($url) => str_starts_with($url, 'https://api.example.com/auth/')
                    && str_contains($url, 'user%40example.com') === false
                ),
                $this->callback(function ($opts) {
                    return isset($opts['headers']['Authorization']) && str_starts_with(
                            $opts['headers']['Authorization'], 'Basic '
                        );
                })
            )->willReturn($response);

        $sut = $this->makeSut($client);

        $result = $sut->authenticateUser('user@example.com', 'pass');

        $this->assertTrue($result['success']);
        $this->assertNull($result['error_type']);
        $this->assertIsObject($result['data']);
        $this->assertSame('123', $result['data']->MemberID);
        $this->assertSame('Alice', $result['data']->FirstName);
        // LastName should be HTML-escaped
        $this->assertSame('&lt;Bob&gt;', $result['data']->LastName);
        // Token should NOT be HTML-escaped
        $this->assertSame('tok-123', $result['data']->OasisAPIToken);
    }


    public function testAuthenticateUserInvalidInputEmpty(): void
    {
        $sut = $this->makeSut();
        $res = $sut->authenticateUser('', '');
        $this->assertFalse($res['success']);
        $this->assertSame('invalid_input', $res['error_type']);
    }


    public function testAuthenticateUserInvalidEmailFormat(): void
    {
        $sut = $this->makeSut();
        $res = $sut->authenticateUser('not-an-email', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('invalid_input', $res['error_type']);
    }


    public function testAuthenticateUserConnectException(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willThrowException(
                new ConnectException('oops', new Request('GET', 'https://api.example.com'))
            );

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('api_unavailable', $res['error_type']);
    }


    public function testAuthenticateUserRequestExceptionClientError(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $request = new Request('GET', 'https://api.example.com');
        $response = new Response(401, [], 'Unauthorized');
        $client
            ->method('request')
            ->willThrowException(new RequestException('client err', $request, $response));

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('invalid_credentials', $res['error_type']);
    }


    public function testAuthenticateUserRequestExceptionServerError(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $request = new Request('GET', 'https://api.example.com');
        $response = new Response(500, [], 'Server');
        $client
            ->method('request')
            ->willThrowException(new RequestException('server err', $request, $response));

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('api_unavailable', $res['error_type']);
    }


    public function testAuthenticateUserOtherGuzzleException(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willThrowException(new TransferException('boom'));

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('api_unavailable', $res['error_type']);
    }


    public function testAuthenticateUserInvalidJson(): void
    {
        $response = new Response(200, [], 'not-json');
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn($response);

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('invalid_response', $res['error_type']);
    }


    public function testAuthenticateUserInvalidCredentialsFromResponse(): void
    {
        $payload = ['error' => 'invalid'];
        $response = new Response(200, [], json_encode($payload));
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn($response);

        $sut = $this->makeSut($client);
        $res = $sut->authenticateUser('user@example.com', 'pass');
        $this->assertFalse($res['success']);
        $this->assertSame('invalid_credentials', $res['error_type']);
    }
}
