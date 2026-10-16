<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

//MammotionApi mit festen Antworten statt echter HTTP-Anfragen. Alle Werte sind synthetisch.
class FakeMammotionApi extends MammotionApi
{
    public array $requests = [];

    public function __construct(private array $responses, string $Language = 'en-US')
    {
        parent::__construct($Language);
    }

    protected function http(string $Method, string $Url, ?string $Body, array $Headers): array
    {
        $this->requests[] = ['method' => $Method, 'url' => $Url, 'body' => $Body, 'headers' => $Headers];
        return array_shift($this->responses);
    }
}

class MammotionApiTest extends TestCase
{
    public function testTokenFromPlainResponse(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 600])]]);
        $token = $api->requestToken('id', 'secret');
        $this->assertSame('a1', $token['AccessToken']);
        $this->assertSame('r1', $token['RefreshToken']);
        $this->assertEqualsWithDelta(time() + 600, $token['ExpiresAt'], 5);
    }

    // Der Server liefert die Token-Antwort im Feld data des üblichen Umschlags.
    public function testTokenFromWrappedResponse(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 0, 'msg' => 'Request success', 'data' => ['access_token' => 'a2', 'expires_in' => 60]])]]);
        $token = $api->requestToken('id', 'secret');
        $this->assertSame('a2', $token['AccessToken']);
        $this->assertSame('', $token['RefreshToken']);
    }

    public function testTokenRequestUsesClientCredentials(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['access_token' => 'a'])]]);
        $api->requestToken('id', 'secret');
        parse_str($api->requests[0]['body'], $fields);
        $this->assertSame('client_credentials', $fields['grant_type']);
        $this->assertSame('id', $fields['client_id']);
        $this->assertArrayNotHasKey('refresh_token', $fields);
    }

    public function testTokenRequestUsesRefreshGrant(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['access_token' => 'a'])]]);
        $api->requestToken('id', 'secret', 'refresh-value');
        parse_str($api->requests[0]['body'], $fields);
        $this->assertSame('refresh_token', $fields['grant_type']);
        $this->assertSame('refresh-value', $fields['refresh_token']);
    }

    public function testRejectedTokenRequestKeepsStatusAndMessage(): void
    {
        $api = new FakeMammotionApi([[400, json_encode(['error' => 'invalid_client', 'error_description' => 'Bad credentials'])]]);
        try {
            $api->requestToken('id', 'wrong');
            $this->fail('Exception expected');
        } catch (MammotionApiException $e) {
            $this->assertSame('Bad credentials', $e->getMessage());
            $this->assertSame(400, $e->getHttpStatus());
        }
    }

    // Antwort mit Status 200, aber ohne Token: gilt als abgelehnte Anmeldung (401), damit das Modul den Status setzt.
    public function testSuccessWithoutTokenIsUnauthorized(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 40200, 'msg' => 'unauthorized_client'])]]);
        try {
            $api->requestToken('id', 'secret');
            $this->fail('Exception expected');
        } catch (MammotionApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('No access token in the response', $e->getMessage());
            $this->assertStringContainsString('unauthorized_client', $e->getMessage());
        }
    }

    public function testCallReturnsDataForBothSuccessCodes(): void
    {
        $api = new FakeMammotionApi([
            [200, json_encode(['code' => 0, 'data' => ['a' => 1]])],
            [200, json_encode(['code' => 200, 'data' => [['b' => 2]]])],
        ]);
        $this->assertSame(['a' => 1], $api->call('GET', '/v1/mowers', 'token'));
        $this->assertSame([['b' => 2]], $api->call('GET', '/v1/mowers', 'token'));
    }

    public function testCallWithoutDataReturnsNull(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 0])]]);
        $this->assertNull($api->call('POST', '/v1/mower/action', 'token', ['x' => 1]));
    }

    public function testCallSendsTokenLanguageAndJsonBody(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 0, 'data' => null])]], 'de-DE');
        $api->call('POST', '/v1/mower/action', 'tok', ['taskName' => 'Garten Süd']);
        $request = $api->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(MammotionApi::API_URL . '/v1/mower/action', $request['url']);
        $this->assertContains('Authorization: Bearer tok', $request['headers']);
        $this->assertContains('Accept-Language: de-DE', $request['headers']);
        $this->assertContains('Content-Type: application/json', $request['headers']);
        $this->assertSame('{"taskName":"Garten Süd"}', $request['body']);
    }

    public function testCallWithoutBodySendsNoContentType(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 0, 'data' => []])]]);
        $api->call('GET', '/v1/mowers', 'tok');
        $this->assertNull($api->requests[0]['body']);
        $this->assertNotContains('Content-Type: application/json', $api->requests[0]['headers']);
    }

    public function testExpiredTokenKeepsStatus401(): void
    {
        $api = new FakeMammotionApi([[401, json_encode(['code' => 401, 'msg' => 'Token expired'])]]);
        try {
            $api->call('GET', '/v1/mowers', 'old');
            $this->fail('Exception expected');
        } catch (MammotionApiException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertSame('Token expired', $e->getMessage());
        }
    }

    public function testBusinessErrorKeepsApiCode(): void
    {
        $api = new FakeMammotionApi([[200, json_encode(['code' => 50802, 'msg' => 'Invalid device information'])]]);
        try {
            $api->call('POST', '/v1/mower/ws/ticket', 'tok', []);
            $this->fail('Exception expected');
        } catch (MammotionApiException $e) {
            $this->assertSame(50802, $e->getApiCode());
            $this->assertSame(200, $e->getHttpStatus());
        }
    }

    public function testNonJsonResponseIsReported(): void
    {
        $api = new FakeMammotionApi([[502, '<html>Bad Gateway</html>']]);
        try {
            $api->call('GET', '/v1/mowers', 'tok');
            $this->fail('Exception expected');
        } catch (MammotionApiException $e) {
            $this->assertSame(502, $e->getHttpStatus());
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
        }
    }
}
