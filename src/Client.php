<?php

namespace Akrual;

use DateTimeInterface;
use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use function json_decode;

final class Client {
    public function __construct(
        private string $endpoint,
        private string $username,
        private string $password,
        private ?string $oauthToken = null,
        private ?CookieJar $cookieJar = null,
    ) {
        // no-op
    }

    public function isAuthed(): bool
    {
        if ($this->oauthToken === null) {
            return false;
        }

        $token = (new Parser(
            new JoseEncoder(),
        ))->parse($this->oauthToken);

        return !$token->isExpired(
            new DateTimeImmutable(),
        );
    }

    public function auth(): string
    {
        if ($this->isAuthed()) {
            return $this->oauthToken;
        }

        $response = $this->getClient()->request(
            'POST',
            '/oauth/token',
            $this->getRequestOptions([
                RequestOptions::JSON => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password',
                ],
            ]),
        );
        
        $json = $this->parseResponse($response);
        if (empty($json['access_token'])) {
            throw new Exception('Access token not found in the response body');
        }

        $this->oauthToken = $json['access_token'];

        if ($response->hasHeader('Set-Cookie')) {
            $uri = Utils::uriFor($this->endpoint);

            $cookies = array_map(function(string $cookie) use ($uri): SetCookie {
                $cookie = SetCookie::fromString($cookie);
                $cookie->setDomain($uri->getHost());

                return $cookie;
            }, $response->getHeader('Set-Cookie'));

            $this->cookieJar = new CookieJar(false, $cookies);
        }

        return $this->oauthToken;
    }

    public function getAllSeries(): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Escrituracao/GetAllSeries',
            $this->getRequestOptions(),
        );

        return $this->parseResponse($response);
    }

    public function getSingleSerie(int $serieId): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Escrituracao/GetSingleSerie',
            $this->getRequestOptions([
                RequestOptions::QUERY => [
                    'serieId' => $serieId,
                ],
            ]),
        );

        return $this->parseResponse($response);
    }

    public function getAllUnitPrices(): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Escrituracao/GetAllPus',
            $this->getRequestOptions(),
        );

        return $this->parseResponse($response);
    }

    public function getUnitPrices(int $serieId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/CRM/GetPus',
            $this->getRequestOptions([
                RequestOptions::FORM_PARAMS => [
                    'serieId' => $serieId,
                    'dateInitial' => $start->format(DateTimeInterface::ISO8601),
                    'dateFinal' => $end->format(DateTimeInterface::ISO8601),
                ],
            ]),
        );

        return $this->parseResponse($response);
    }

    public function getCalendarEvents(int $serieId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Calendar/GetCalendarEventsAPI',
            $this->getRequestOptions([
                RequestOptions::JSON => [
                    'serieId' => $serieId,
                    'dateInitial' => $start->format(DateTimeInterface::ISO8601),
                    'dateFinal' => $end->format(DateTimeInterface::ISO8601),
                ],
            ]),
        );

        return $this->parseResponse($response);
    }

    public function getExpenses(int $serieId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/CRM/GetWorkflowDespesas',
            $this->getRequestOptions([
                RequestOptions::JSON => [
                    'serieId' => $serieId,
                    'dateInitial' => $start->format(DateTimeInterface::ISO8601),
                    'dateFinal' => $end->format(DateTimeInterface::ISO8601),
                ],
            ]),
        );

        return $this->parseResponse($response);
    }

    public function calculateDesagio(int $serieId, int $type, DateTimeInterface $date, float $value): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/CRM/Desagio',
            $this->getRequestOptions([
                RequestOptions::QUERY => [
                    'serieId' => $serieId,
                    'tipo' => $type,
                    'data' => $date->format('Y-m-d'),
                    'valor' => $value,
                ],
            ]),
        );

        return $this->parseResponse($response);
    }

    private function getClient(): GuzzleClient
    {
        static $client = null;

        if ($client === null) {
            $client = new GuzzleClient([
                'base_uri' => $this->endpoint,
            ]);
        }

        return $client;
    }

    private function getRequestOptions(array $options = []): array
    {
        if (!empty($this->oauthToken)) {
            $options[RequestOptions::HEADERS] = [
                'Authorization' => sprintf('Bearer %s', $this->oauthToken),
            ];
        }

        if ($this->cookieJar !== null) {
            $options[RequestOptions::COOKIES] = $this->cookieJar;
        }

        return $options;
    }

    private function parseResponse(Response $response): array
    {
        $contentType = $response->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') === false) {
            throw new Exception(sprintf(
                'Unexpected Content-Type: %s',
                $contentType,
            ));
        }

        $body = $response->getBody()->getContents();
        
        return json_decode($body, true);
    }
}
