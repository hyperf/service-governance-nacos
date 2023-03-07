<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\ServiceGovernanceNacos;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Nacos\Exception\RequestException;
use Hyperf\ServiceGovernance\DriverInterface;
use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Coordinator\Constants;
use Hyperf\Utils\Coordinator\CoordinatorManager;
use Hyperf\Utils\Coroutine;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

class NacosDriver implements DriverInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $serviceRegistered = [];

    /**
     * @var array
     */
    protected $serviceCreated = [];

    /**
     * @var array
     */
    protected $registerHeartbeat = [];

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var array
     */
    private $metadata = [];

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->client = $container->get(Client::class);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->config = $container->get(ConfigInterface::class);
    }

    public function getNodes(string $uri, string $name, array $metadata): array
    {
        $response = $this->client->instance->list($name, [
            'groupName' => $this->config->get('services.drivers.nacos.group_name'),
            'namespaceId' => $this->config->get('services.drivers.nacos.namespace_id'),
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RequestException((string) $response->getBody(), $response->getStatusCode());
        }

        $data = Json::decode((string) $response->getBody());
        $hosts = $data['hosts'] ?? [];
        $nodes = [];
        foreach ($hosts as $node) {
            if (isset($node['ip'], $node['port']) && ($node['healthy'] ?? false)) {
                $nodes[] = [
                    'host' => $node['ip'],
                    'port' => $node['port'],
                    'weight' => $this->getWeight($node['weight'] ?? 1),
                ];
            }
        }
        return $nodes;
    }

    public function register(string $name, string $host, int $port, array $metadata): void
    {
        $this->setMetadata($name, $metadata);
        $ephemeral = (bool) $this->config->get('services.drivers.nacos.ephemeral');
        if (! $ephemeral && ! array_key_exists($name, $this->serviceCreated)) {
            $response = $this->client->service->create($name, [
                'groupName' => $this->config->get('services.drivers.nacos.group_name'),
                'namespaceId' => $this->config->get('services.drivers.nacos.namespace_id'),
                'metadata' => $this->getMetadata($name),
                'protectThreshold' => (float) $this->config->get('services.drivers.nacos.protect_threshold', 0),
            ]);

            if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== 'ok') {
                throw new RequestException(sprintf('Failed to create nacos service %s , %s !', $name, (string) $response->getBody()));
            }

            $this->serviceCreated[$name] = true;
        }

        $response = $this->client->instance->register($host, $port, $name, [
            'groupName' => $this->config->get('services.drivers.nacos.group_name'),
            'namespaceId' => $this->config->get('services.drivers.nacos.namespace_id'),
            'metadata' => $this->getMetadata($name),
            'ephemeral' => $ephemeral ? 'true' : null,
        ]);

        if ($response->getStatusCode() !== 200 || (string) $response->getBody() !== 'ok') {
            throw new RequestException(sprintf('Failed to create nacos instance %s:%d! for %s , %s ', $host, $port, $name, (string) $response->getBody()));
        }

        $this->serviceRegistered[$name] = true;
        $this->registerHeartbeat($name, $host, $port);
    }

    public function isRegistered(string $name, string $host, int $port, array $metadata): bool
    {
        if (array_key_exists($name, $this->serviceRegistered)) {
            return true;
        }
        $this->setMetadata($name, $metadata);
        $response = $this->client->service->detail(
            $name,
            $this->config->get('services.drivers.nacos.group_name'),
            $this->config->get('services.drivers.nacos.namespace_id')
        );

        if ($response->getStatusCode() === 404) {
            return false;
        }

        if ($response->getStatusCode() === 500 && strpos((string) $response->getBody(), 'not found') > 0) {
            return false;
        }

        if ($response->getStatusCode() === 400 && strpos((string) $response->getBody(), 'not found') > 0) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            throw new RequestException(sprintf('Failed to get nacos service %s!', $name), $response->getStatusCode());
        }

        $this->serviceCreated[$name] = true;

        $response = $this->client->instance->detail($host, $port, $name, [
            'groupName' => $this->config->get('services.drivers.nacos.group_name'),
            'namespaceId' => $this->config->get('services.drivers.nacos.namespace_id'),
        ]);

        if ($this->isNoIpsFound($response)) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            throw new RequestException(sprintf('Failed to get nacos instance %s:%d for %s!', $host, $port, $name));
        }
        $this->serviceRegistered[$name] = true;
        $this->registerHeartbeat($name, $host, $port);

        return true;
    }

    protected function isNoIpsFound(ResponseInterface $response): bool
    {
        if ($response->getStatusCode() === 404) {
            return true;
        }

        if ($response->getStatusCode() === 500) {
            $messages = [
                'no ips found',
                'no matched ip',
            ];
            $body = (string) $response->getBody();
            foreach ($messages as $message) {
                if (strpos($body, $message) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function setMetadata(string $name, array $metadata)
    {
        $this->metadata[$name] = $metadata;
    }

    protected function getMetadata(string $name): ?string
    {
        if (empty($this->metadata[$name])) {
            return null;
        }
        unset($this->metadata[$name]['methodName']);
        return Json::encode($this->metadata[$name]);
    }

    protected function registerHeartbeat(string $name, string $host, int $port): void
    {
        $key = $name . $host . $port;
        if (isset($this->registerHeartbeat[$key])) {
            return;
        }
        $this->registerHeartbeat[$key] = true;

        Coroutine::create(function () use ($name, $host, $port) {
            retry(INF, function () use ($name, $host, $port) {
                $lightBeatEnabled = false;
                while (true) {
                    try {
                        $heartbeat = $this->config->get('services.drivers.nacos.heartbeat', 5);
                        if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($heartbeat)) {
                            break;
                        }

                        $groupName = $this->config->get('services.drivers.nacos.group_name');

                        $response = $this->client->instance->beat(
                            $name,
                            [
                                'ip' => $host,
                                'port' => $port,
                                'serviceName' => $groupName . '@@' . $name,
                            ],
                            $groupName,
                            $this->config->get('services.drivers.nacos.namespace_id'),
                            null,
                            $lightBeatEnabled
                        );

                        $result = json_decode($response->getBody()->getContents(), true);

                        if ($response->getStatusCode() === 200) {
                            $this->logger->debug(sprintf('Instance %s:%d heartbeat successfully, result code:%s', $host, $port, $result['code']));
                        } else {
                            $this->logger->error(sprintf('Instance %s:%d heartbeat failed! %s', $host, $port, (string) $response->getBody()));
                            continue;
                        }

                        $lightBeatEnabled = false;
                        if (isset($result['lightBeatEnabled'])) {
                            $lightBeatEnabled = $result['lightBeatEnabled'];
                        }

                        if ($result['code'] == 20404) {
                            $this->client->instance->register($host, $port, $name, [
                                'groupName' => $this->config->get('services.drivers.nacos.group_name'),
                                'namespaceId' => $this->config->get('services.drivers.nacos.namespace_id'),
                                'metadata' => $this->getMetadata($name),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error(sprintf('Instance %s:%d heartbeat failed! reason:%s', $host, $port, (string) $e));
                        throw $e;
                    }
                }
            });
        });
    }

    private function getWeight($weight): int
    {
        return intval(100 * $weight);
    }
}
