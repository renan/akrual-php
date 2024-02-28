<?php

namespace Akrual;

use DateTimeInterface;
use DateTimeImmutable;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use function json_decode;

final class Client {
    public function __construct(
        private string $endpoint,
        private string $username,
        private string $password,
        private ?string $oauthToken = null,
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
            [
                RequestOptions::FORM_PARAMS => [
                    'username' => $this->username,
                    'password' => $this->password,
                    'grant_type' => 'password',
                ],
            ],
        );
        
        $json = $this->parseResponse($response);
        if (empty($json['access_token'])) {
            throw new Exception('Access token not found in the response body');
        }

        $this->oauthToken = $json['access_token'];

        return $this->oauthToken;
    }

    public function getAllSeries(): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Escrituracao/GetAllSeries',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => sprintf('Bearer %s', $this->oauthToken),
                ],
            ],
        );

        return $this->parseResponse($response);
    }

    public function getSingleSerie(int $serieId): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Escrituracao/GetSingleSerie',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => sprintf('Bearer %s', $this->oauthToken),
                ],
                RequestOptions::QUERY => [
                    'serieId' => $serieId,
                ],
            ],
        );

        return $this->parseResponse($response);
    }

    public function getCalendarEvents(int $serieId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $response = $this->getClient()->request(
            'GET',
            '/Calendar/GetCalendarEventsAPI',
            [
                RequestOptions::HEADERS => [
                    'Authorization' => sprintf('Bearer %s', $this->oauthToken),
                ],
                RequestOptions::JSON => [
                    'serieId' => $serieId,
                    'dateInitial' => $start->format(DateTimeInterface::ISO8601),
                    'dateFinal' => $end->format(DateTimeInterface::ISO8601),
                ],
            ],
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
