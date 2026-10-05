<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

/**
 * Read-only client for PayPal's Transaction Search and Balances APIs.
 *
 * The HTTP transport is a callable so tests can replace it. Its signature is
 * `function (string $method, string $url, array $headers, string $body): array{status: int, body: string}`.
 * Without one, Dolibarr's getURLContent() is used, so the module adds no dependency.
 *
 * The client never logs or returns the client secret or the access token.
 */
class PayPalApiClient
{
    public const LIVE_BASE_URL = 'https://api-m.paypal.com';
    public const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    /** PayPal rejects ranges above 31 days. */
    public const MAX_WINDOW_DAYS = 31;

    /** The schema allows at most 500 rows per page. */
    public const PAGE_SIZE = 500;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var callable */
    private $http;

    /** @var string|null */
    private $accessToken;

    public function __construct(string $clientId, string $clientSecret, string $baseUrl = self::LIVE_BASE_URL, ?callable $http = null)
    {
        if ('' === trim($clientId) || '' === trim($clientSecret)) {
            throw new InvalidArgumentException('PayPal client ID and secret are required.');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? [self::class, 'dolibarrTransport'];
    }

    /**
     * Builds a client from a JSON credentials file holding `client_id`, `client_secret` and
     * optionally `base_url`. The file is meant to be a read-only Docker mount such as
     * /run/secrets/paypal.json, so the secret never enters the Dolibarr database.
     */
    public static function fromCredentialsFile(string $path, ?callable $http = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('PayPal credentials file "%s" is missing or not readable.', $path));
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!\is_array($data)) {
            throw new RuntimeException(sprintf('PayPal credentials file "%s" is not valid JSON.', $path));
        }

        return new self(
            (string) ($data['client_id'] ?? ''),
            (string) ($data['client_secret'] ?? ''),
            (string) ($data['base_url'] ?? self::LIVE_BASE_URL),
            $http
        );
    }

    /**
     * Fetches an access token. Useful as a connection test, because it proves the credentials
     * without touching any transaction data.
     */
    public function authenticate(): void
    {
        $response = $this->send(
            'POST',
            $this->baseUrl.'/v1/oauth2/token',
            [
                'Authorization: Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            'grant_type=client_credentials'
        );

        $token = (string) ($response['access_token'] ?? '');
        if ('' === $token) {
            throw new RuntimeException('PayPal answered the token request without an access token.');
        }

        $this->accessToken = $token;
    }

    /**
     * Lists all transactions between two instants, split into 31-day windows and followed across
     * every page.
     *
     * @return array<int, array<string, mixed>> The `transaction_details` items in PayPal's order
     */
    public function transactions(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            throw new InvalidArgumentException('The end of the range must lie after its start.');
        }

        $details = [];
        foreach (self::windows($from, $to) as [$start, $end]) {
            $page = 1;
            do {
                $query = http_build_query([
                    'start_date' => $start->format('Y-m-d\TH:i:s\Z'),
                    'end_date' => $end->format('Y-m-d\TH:i:s\Z'),
                    'fields' => 'all',
                    'page_size' => self::PAGE_SIZE,
                    'page' => $page,
                ]);
                $response = $this->authorizedGet('/v1/reporting/transactions?'.$query);

                foreach ((array) ($response['transaction_details'] ?? []) as $detail) {
                    if (\is_array($detail)) {
                        $details[] = $detail;
                    }
                }

                $totalPages = (int) ($response['total_pages'] ?? 1);
                ++$page;
            } while ($page <= $totalPages);
        }

        return $details;
    }

    /**
     * Returns the balances at an instant, as PayPal's `balances` list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function balances(DateTimeImmutable $asOf): array
    {
        $response = $this->authorizedGet('/v1/reporting/balances?'.http_build_query([
            'as_of_time' => $asOf->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]));

        return array_values(array_filter((array) ($response['balances'] ?? []), 'is_array'));
    }

    /**
     * Splits a range into consecutive windows of at most 31 days, all in UTC.
     *
     * @return array<int, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    public static function windows(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $utc = new DateTimeZone('UTC');
        $start = $from->setTimezone($utc);
        $end = $to->setTimezone($utc);

        $windows = [];
        while ($start < $end) {
            $windowEnd = $start->modify('+'.self::MAX_WINDOW_DAYS.' days -1 second');
            if ($windowEnd > $end) {
                $windowEnd = $end;
            }
            $windows[] = [$start, $windowEnd];
            $start = $windowEnd->modify('+1 second');
        }

        return $windows;
    }

    /**
     * @return array<string, mixed>
     */
    private function authorizedGet(string $path): array
    {
        if (null === $this->accessToken) {
            $this->authenticate();
        }

        return $this->send('GET', $this->baseUrl.$path, [
            'Authorization: Bearer '.$this->accessToken,
            'Accept: application/json',
        ], '');
    }

    /**
     * @param string[] $headers
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, array $headers, string $body): array
    {
        $result = \call_user_func($this->http, $method, $url, $headers, $body);
        $status = (int) ($result['status'] ?? 0);
        $decoded = json_decode((string) ($result['body'] ?? ''), true);

        if ($status < 200 || $status >= 300) {
            $reason = \is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['message'] ?? $decoded['name'] ?? '') : '';
            throw new RuntimeException(sprintf('PayPal %s %s answered HTTP %d%s.', $method, parse_url($url, PHP_URL_PATH), $status, '' !== $reason ? ' ('.$reason.')' : ''));
        }
        if (!\is_array($decoded)) {
            throw new RuntimeException(sprintf('PayPal %s %s answered with something other than JSON.', $method, parse_url($url, PHP_URL_PATH)));
        }

        return $decoded;
    }

    /**
     * Default transport through Dolibarr's getURLContent().
     *
     * @param string[] $headers
     *
     * @return array{status: int, body: string}
     */
    public static function dolibarrTransport(string $method, string $url, array $headers, string $body): array
    {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

        $result = getURLContent($url, 'POST' === $method ? 'POST' : 'GET', $body, 0, $headers, ['https'], 0, 1);

        return [
            'status' => (int) ($result['http_code'] ?? 0),
            'body' => (string) ($result['content'] ?? ''),
        ];
    }
}
