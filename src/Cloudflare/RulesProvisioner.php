<?php

namespace Dynamic\EdgeCache\Cloudflare;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Reads the zone's cache ruleset and writes it back with this module's rules merged in.
 *
 * A PUT to the ruleset entrypoint replaces the whole ruleset, so the rules already there are read
 * first and sent back untouched. Needs a token with Zone > Cache Rules > Edit as well as the
 * purge permission.
 */
class RulesProvisioner
{
    use Injectable;

    private const PHASE = 'http_request_cache_settings';

    private ?ClientInterface $client = null;

    public function setClient(ClientInterface $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Rules the zone has now.
     *
     * @return array<int, array<string, mixed>>
     */
    public function current(): array
    {
        $response = $this->client()->request('GET', $this->uri(), $this->options(['http_errors' => false]));
        if ($response->getStatusCode() === 404) {
            return [];
        }

        return $this->decode($response)['result']['rules'] ?? [];
    }

    /**
     * The ruleset that would be written for a host, without writing it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(string $host): array
    {
        return CacheRuleset::singleton()->merge($this->current(), $host);
    }

    /**
     * @return array<int, array<string, mixed>> the ruleset as written
     */
    public function apply(string $host): array
    {
        $rules = $this->plan($host);
        $response = $this->client()->request(
            'PUT',
            $this->uri(),
            $this->options(['json' => ['rules' => $rules], 'http_errors' => false])
        );
        $this->decode($response);

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode($response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !is_array($data) || empty($data['success'])) {
            $message = is_array($data) && !empty($data['errors'])
                ? json_encode($data['errors'])
                : 'HTTP ' . $response->getStatusCode();
            throw new RuntimeException('Cloudflare cache ruleset request failed: ' . $message);
        }

        return $data;
    }

    protected function uri(): string
    {
        $zone = (string) Environment::getEnv('EDGECACHE_CLOUDFLARE_ZONE_ID');
        if ($zone === '') {
            throw new RuntimeException('EDGECACHE_CLOUDFLARE_ZONE_ID is not set');
        }

        return sprintf('zones/%s/rulesets/phases/%s/entrypoint', $zone, self::PHASE);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function options(array $extra = []): array
    {
        $token = (string) Environment::getEnv('EDGECACHE_CLOUDFLARE_API_TOKEN');
        if ($token === '') {
            throw new RuntimeException('EDGECACHE_CLOUDFLARE_API_TOKEN is not set');
        }

        return $extra + ['headers' => ['Authorization' => 'Bearer ' . $token], 'timeout' => 15];
    }

    protected function client(): ClientInterface
    {
        return $this->client ??= new Client(['base_uri' => 'https://api.cloudflare.com/client/v4/']);
    }
}
