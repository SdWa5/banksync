<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PayPalApiClientTest extends TestCase
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: string}> */
    private $requests = [];

    public function testWindowsNeverExceed31Days(): void
    {
        $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $to = new DateTimeImmutable('2026-03-15T00:00:00Z');

        $windows = PayPalApiClient::windows($from, $to);

        self::assertCount(3, $windows);
        self::assertEquals($from, $windows[0][0]);
        self::assertEquals($to, $windows[2][1]);
        foreach ($windows as $i => [$start, $end]) {
            self::assertLessThanOrEqual(31 * 86400, $end->getTimestamp() - $start->getTimestamp());
            if ($i > 0) {
                self::assertSame(1, $start->getTimestamp() - $windows[$i - 1][1]->getTimestamp());
            }
        }
    }

    public function testWindowsAreUtc(): void
    {
        $windows = PayPalApiClient::windows(new DateTimeImmutable('2026-09-09T00:00:00+02:00'), new DateTimeImmutable('2026-09-10T00:00:00+02:00'));

        self::assertSame('2026-09-08T22:00:00+00:00', $windows[0][0]->format(DATE_ATOM));
    }

    public function testAuthenticatesOnceAndFollowsAllPages(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => '{"access_token":"tok","expires_in":32400}'],
            ['status' => 200, 'body' => json_encode(['transaction_details' => [['id' => 1], ['id' => 2]], 'page' => 1, 'total_pages' => 2])],
            ['status' => 200, 'body' => json_encode(['transaction_details' => [['id' => 3]], 'page' => 2, 'total_pages' => 2])],
        ]);

        $details = $client->transactions(new DateTimeImmutable('2026-09-09T00:00:00Z'), new DateTimeImmutable('2026-09-20T00:00:00Z'));

        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $details);
        self::assertCount(3, $this->requests);

        $token = $this->requests[0];
        self::assertSame('POST', $token['method']);
        self::assertSame('https://api.example/v1/oauth2/token', $token['url']);
        self::assertContains('Authorization: Basic '.base64_encode('id:secret'), $token['headers']);
        self::assertSame('grant_type=client_credentials', $token['body']);

        parse_str((string) parse_url($this->requests[2]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2026-09-09T00:00:00Z', $query['start_date']);
        self::assertSame('2026-09-20T00:00:00Z', $query['end_date']);
        self::assertSame('all', $query['fields']);
        self::assertSame('500', $query['page_size']);
        self::assertSame('2', $query['page']);
        self::assertContains('Authorization: Bearer tok', $this->requests[2]['headers']);
    }

    public function testRequestsEachWindow(): void
    {
        $empty = ['status' => 200, 'body' => '{"transaction_details":[],"total_pages":0}'];
        $client = $this->client([['status' => 200, 'body' => '{"access_token":"tok"}'], $empty, $empty]);

        $client->transactions(new DateTimeImmutable('2026-08-01T00:00:00Z'), new DateTimeImmutable('2026-09-15T00:00:00Z'));

        self::assertCount(3, $this->requests);
    }

    public function testBalancesAsOfUtc(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => '{"access_token":"tok"}'],
            ['status' => 200, 'body' => '{"balances":[{"currency":"EUR","primary":true,"total_balance":{"currency_code":"EUR","value":"333.40"}}]}'],
        ]);

        $balances = $client->balances(new DateTimeImmutable('2026-09-09T00:00:00+02:00'));

        self::assertSame('333.40', $balances[0]['total_balance']['value']);
        parse_str((string) parse_url($this->requests[1]['url'], PHP_URL_QUERY), $query);
        self::assertSame('2026-09-08T22:00:00Z', $query['as_of_time']);
    }

    public function testHttpErrorNamesStatusAndReasonButNoSecret(): void
    {
        $client = $this->client([['status' => 401, 'body' => '{"error":"invalid_client","error_description":"Client Authentication failed"}']]);

        try {
            $client->authenticate();
            self::fail('Expected an exception.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringContainsString('Client Authentication failed', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testNamesTheTransportErrorWhenNothingAnswered(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayPal POST /v1/oauth2/token was not reachable (Could not resolve host).');
        $this->client([['status' => 0, 'body' => '', 'error' => 'Could not resolve host']])->authenticate();
    }

    public function testRejectsATokenResponseWithoutToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->client([['status' => 200, 'body' => '{}']])->authenticate();
    }

    public function testRejectsNonJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->client([['status' => 200, 'body' => '<html>']])->authenticate();
    }

    public function testRejectsAnEmptyRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $at = new DateTimeImmutable('2026-09-09T00:00:00Z');
        $this->client([])->transactions($at, $at);
    }

    public function testRequiresCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PayPalApiClient('id', ' ');
    }

    public function testReadsTheCredentialsFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pp');
        file_put_contents($path, json_encode(['client_id' => 'id', 'client_secret' => 'secret', 'base_url' => 'https://api.example/']));
        $responses = [['status' => 200, 'body' => '{"access_token":"tok"}']];

        try {
            PayPalApiClient::fromCredentialsFile($path, $this->transport($responses))->authenticate();
        } finally {
            unlink($path);
        }

        self::assertSame('https://api.example/v1/oauth2/token', $this->requests[0]['url']);
    }

    public function testRejectsAMissingCredentialsFile(): void
    {
        $this->expectException(RuntimeException::class);
        PayPalApiClient::fromCredentialsFile('/nonexistent/paypal.json');
    }

    /**
     * @param array<int, array{status: int, body: string}> $responses
     */
    private function client(array $responses): PayPalApiClient
    {
        return new PayPalApiClient('id', 'secret', 'https://api.example', $this->transport($responses));
    }

    /**
     * @param array<int, array{status: int, body: string}> $responses
     */
    private function transport(array $responses): callable
    {
        return function (string $method, string $url, array $headers, string $body) use (&$responses): array {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if ([] === $responses) {
                self::fail('Unexpected request to '.$url);
            }

            return array_shift($responses);
        };
    }
}
