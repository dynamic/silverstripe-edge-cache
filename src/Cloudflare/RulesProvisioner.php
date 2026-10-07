<?php

namespace Dynamic\EdgeCache\Cloudflare;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Reads the zone's cache ruleset and writes it back with this module's rules merged in.
 *
 * A PUT to the ruleset entrypoint replaces the whole ruleset, so the rules already there are read
 * first and sent back untouched. Between the read and the write nothing stops another change (a
 * rule saved in the dashboard) from being overwritten; run it from one place at a time. Needs a
 * token with the "Cache Settings" permission group (dashboard: Cache Rules) on this zone: Read to
 * plan, Write to validate or change.
 *
 * Anything that is not an unambiguous answer is an error, never "the zone has no rules": a
 * misread empty ruleset would be written back over the zone's real one.
 */
class RulesProvisioner
{
    use Injectable;

    private const PHASE = 'http_request_cache_settings';

    /**
     * Cloudflare's code for "this zone has no ruleset for the phase yet".
     */
    private const ERROR_NO_ENTRYPOINT = 10003;

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
        $response = $this->send('GET', []);
        $data = $this->decodeBody($response);

        if ($response->getStatusCode() === 404 && $this->hasErrorCode($data, self::ERROR_NO_ENTRYPOINT)) {
            return [];
        }
        $this->assertSuccess($response, $data);

        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Cloudflare answered without a ruleset; refusing to treat that as an empty zone.');
        }
        if (array_key_exists('rules', $result)) {
            return (array) $result['rules'];
        }
        // An empty ruleset can omit the rules key; it still names itself.
        if (isset($result['id'], $result['kind'])) {
            return [];
        }

        throw new RuntimeException('Cloudflare answered with a ruleset this module does not recognise; nothing was changed.');
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
     * Ask Cloudflare to check the ruleset that would be written, without writing it. A rulesets API
     * dry run runs the same syntax, field, phase and plan checks as a real write.
     *
     * @return array<int, array<string, mixed>> the ruleset that was checked
     * @throws RuntimeException when Cloudflare rejects it
     */
    public function validate(string $host): array
    {
        $rules = $this->plan($host);
        $this->put($rules, true);

        return $rules;
    }

    /**
     * @param bool $validate check with a dry run first, so a rejected rule never reaches the zone
     * @return array<int, array<string, mixed>> the rules the zone reports holding after the write
     */
    public function apply(string $host, bool $validate = true): array
    {
        $rules = $this->plan($host);
        if ($validate) {
            $this->put($rules, true);
        }

        $written = $this->put($rules, false);

        return is_array($written['result']['rules'] ?? null) ? $written['result']['rules'] : $rules;
    }

    /**
     * Remove this module's rules and leave every other rule in place.
     *
     * @param string|null $host only the rules written for this host; null removes them for every host
     * @return int how many rules were removed (0 writes nothing)
     */
    public function remove(?string $host = null): int
    {
        $existing = $this->current();
        $kept = CacheRuleset::singleton()->removeOwned($existing, $host);
        $removed = count($existing) - count($kept);
        if ($removed > 0) {
            $this->put($kept, false);
        }

        return $removed;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed> the decoded response
     */
    protected function put(array $rules, bool $dryRun): array
    {
        try {
            $response = $this->send('PUT', [
                'json' => ['rules' => $rules],
                'query' => $dryRun ? ['dry_run' => 'true'] : [],
            ]);
        } catch (TransportException $e) {
            throw new RuntimeException($dryRun
                ? 'Could not reach Cloudflare for the dry run; nothing was written. ' . $e->getMessage()
                : 'Could not reach Cloudflare during the write, so it may or may not have been applied. Run the task '
                    . 'with no arguments to see the rules the zone holds now. ' . $e->getMessage(), 0, $e);
        }

        $data = $this->decodeBody($response);
        try {
            $this->assertSuccess($response, $data);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                ($dryRun
                    ? 'Cloudflare rejected the ruleset in the dry run; nothing was written. '
                    : 'Cloudflare rejected the write. ')
                . $e->getMessage(),
                0,
                $e
            );
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $extra
     * @throws TransportException when Cloudflare cannot be reached
     */
    protected function send(string $method, array $extra): ResponseInterface
    {
        try {
            return $this->client()->request($method, $this->uri(), $this->options($extra));
        } catch (GuzzleException $e) {
            throw new TransportException('Cloudflare did not answer: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeBody(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function assertSuccess(ResponseInterface $response, array $data): void
    {
        if ($response->getStatusCode() < 300 && !empty($data['success'])) {
            return;
        }

        $message = !empty($data['errors']) ? json_encode($data['errors']) : 'HTTP ' . $response->getStatusCode();
        throw new RuntimeException('Cloudflare cache ruleset request failed: ' . $message);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function hasErrorCode(array $data, int $code): bool
    {
        foreach ((array) ($data['errors'] ?? []) as $error) {
            if (is_array($error) && (int) ($error['code'] ?? 0) === $code) {
                return true;
            }
        }

        return false;
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
     * Redirects are never followed: Guzzle turns a redirected PUT into a GET, which would read as a
     * successful write.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function options(array $extra = []): array
    {
        $token = (string) Environment::getEnv('EDGECACHE_CLOUDFLARE_API_TOKEN');
        if ($token === '') {
            throw new RuntimeException('EDGECACHE_CLOUDFLARE_API_TOKEN is not set');
        }

        return $extra + [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'timeout' => 15,
            'http_errors' => false,
            'allow_redirects' => false,
        ];
    }

    protected function client(): ClientInterface
    {
        return $this->client ??= new Client(['base_uri' => 'https://api.cloudflare.com/client/v4/']);
    }
}
