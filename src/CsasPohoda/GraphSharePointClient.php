<?php

declare(strict_types=1);

/**
 * This file is part of the CsasPohoda package
 *
 * https://github.com/Spoje-NET/csas-pohoda
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet\CsasPohoda;

/**
 * SharePoint Online file upload via Microsoft Graph (Entra ID app-only client_credentials).
 *
 * Classic SharePoint REST (_api/web/...) rejects client-secret tokens ("Unsupported app only token."),
 * Graph does not. The Entra ID app needs the Graph "Sites.Selected" application permission (admin
 * consented) and an explicit grant on the target site.
 *
 * Configured by OFFICE365_TENANT, OFFICE365_SITE, OFFICE365_CLIENTID and OFFICE365_CLSECRET.
 */
class GraphSharePointClient
{
    private ?string $siteId = null;
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(
        private readonly string $tenant,
        private readonly string $site,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    /**
     * Client from environment variables or null when SharePoint is not configured.
     *
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env): ?self
    {
        foreach (['OFFICE365_TENANT', 'OFFICE365_SITE', 'OFFICE365_CLIENTID', 'OFFICE365_CLSECRET', 'OFFICE365_PATH'] as $name) {
            if (($env[$name] ?? '') === '') {
                return null;
            }
        }

        return new self($env['OFFICE365_TENANT'], $env['OFFICE365_SITE'], $env['OFFICE365_CLIENTID'], $env['OFFICE365_CLSECRET']);
    }

    /**
     * Upload a file (created or overwritten) to $path/$filename; missing folders are created by Graph.
     *
     * @return array{webUrl: string, id: string, name: string} decoded driveItem
     */
    public function uploadFile(string $path, string $filename, string $contents): array
    {
        $url = sprintf(
            'https://graph.microsoft.com/v1.0/sites/%s/drive/root:/%s/%s:/content',
            $this->siteId(),
            $this->encodePath($path),
            rawurlencode($filename),
        );

        return (array) json_decode($this->request('PUT', $url, $contents, 'application/octet-stream'), true);
    }

    /**
     * Sharing link which stays valid across moves and renames (unlike the path based webUrl).
     */
    public function createShareLink(string $itemId, string $type = 'view', string $scope = 'organization'): string
    {
        $url = sprintf('https://graph.microsoft.com/v1.0/sites/%s/drive/items/%s/createLink', $this->siteId(), rawurlencode($itemId));
        $decoded = (array) json_decode($this->request('POST', $url, json_encode(['type' => $type, 'scope' => $scope], \JSON_THROW_ON_ERROR), 'application/json'), true);

        return (string) ($decoded['link']['webUrl'] ?? '');
    }

    /**
     * Upload a file and return the URL to store: a sharing link or the plain webUrl.
     */
    public function uploadAndLink(string $path, string $filename, string $contents, bool $permanentLink = true, string $linkType = 'view', string $linkScope = 'organization'): string
    {
        $uploaded = $this->uploadFile($path, $filename, $contents);

        if (empty($uploaded['id']) || empty($uploaded['webUrl'])) {
            throw new GraphApiException('Graph returned no driveItem for '.$filename);
        }

        return $permanentLink ? $this->createShareLink((string) $uploaded['id'], $linkType, $linkScope) : (string) $uploaded['webUrl'];
    }

    /**
     * Graph site id for tenant and site, memoized.
     */
    public function siteId(): string
    {
        if ($this->siteId === null) {
            $host = str_contains($this->tenant, '.') ? $this->tenant : $this->tenant.'.sharepoint.com';
            $decoded = json_decode($this->request('GET', "https://graph.microsoft.com/v1.0/sites/{$host}:/sites/{$this->site}"), true);

            if (!\is_array($decoded) || empty($decoded['id'])) {
                throw new GraphApiException('Graph returned no id of the site '.$this->site);
            }

            $this->siteId = (string) $decoded['id'];
        }

        return $this->siteId;
    }

    /**
     * Send one HTTP request; overridable so tests do not need the network.
     *
     * @param array<int, string> $headers
     *
     * @return array{code: int, body: string, error: string}
     */
    protected function send(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => 60,
        ]);

        if ($body !== null) {
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $result = ['code' => (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE), 'body' => \is_string($response) ? $response : '', 'error' => curl_error($ch)];
        curl_close($ch);

        return $result;
    }

    /**
     * Graph request with a token refresh and one retry on failure.
     */
    protected function request(string $method, string $url, ?string $body = null, ?string $contentType = null, bool $retried = false): string
    {
        $headers = ['Authorization: Bearer '.$this->bearerToken()];

        if ($contentType !== null) {
            $headers[] = 'Content-Type: '.$contentType;
        }

        $response = $this->send($method, $url, $headers, $body);

        if ($response['code'] >= 400 || $response['code'] === 0) {
            if (!$retried) {
                $this->accessToken = null;
                $this->expiresAt = 0;

                return $this->request($method, $url, $body, $contentType, true);
            }

            throw new GraphApiException($response['body'] !== '' ? $response['body'] : ($response['error'] !== '' ? $response['error'] : 'Graph API request failed'), $response['code'], $response['body']);
        }

        return $response['body'];
    }

    private function bearerToken(): string
    {
        if ($this->accessToken === null || time() >= $this->expiresAt) {
            // A short tenant name (contoso) gets the standard suffix, the v2 endpoint needs a real authority.
            $authority = str_contains($this->tenant, '.') ? $this->tenant : $this->tenant.'.onmicrosoft.com';
            $response = $this->send('POST', 'https://login.microsoftonline.com/'.$authority.'/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]));
            $decoded = json_decode($response['body'], true);

            if ($response['code'] !== 200 || !\is_array($decoded) || empty($decoded['access_token'])) {
                throw new GraphApiException($response['body'] !== '' ? $response['body'] : ($response['error'] ?: 'Entra ID token request returned no usable token'), $response['code'], $response['body']);
            }

            $this->accessToken = (string) $decoded['access_token'];
            $this->expiresAt = time() + max(60, (int) ($decoded['expires_in'] ?? 3600) - 60); // refresh a little early
        }

        return $this->accessToken;
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', $path), static fn (string $part): bool => $part !== '')));
    }
}
