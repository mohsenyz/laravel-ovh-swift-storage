<?php

namespace Hedii\LaravelOvhSwiftStorage;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use OpenStack\OpenStack;

class OvhSwiftStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @param \Illuminate\Filesystem\FilesystemManager $filesystemManager
     * @param \Illuminate\Contracts\Cache\Repository $cache
     */
    public function boot(FilesystemManager $filesystemManager, CacheRepository $cache): void
    {
        $filesystemManager->extend('ovh-swift', function ($app, array $config) use ($cache): FilesystemInterface {
            $options = [
                'authUrl' => $config['authUrl'],
                'region' => $config['region'],
                'user' => [
                    'name' => $config['username'],
                    'password' => $config['password'],
                    'domain' => [
                        'id' => 'default',
                    ],
                ],
                'scope' => [
                    'project' => [
                        'id' => $config['projectId'],
                    ],
                ],
                'publicUrl' => $this->getContainerPublicUrl($config),
            ];

            $openstack = new OpenStack($options);

            $identity = $openstack->identityV3(['region' => $config['region']]);

            if ($token = $cache->get('openstack-token')) {
                $options['cachedToken'] = $token;
            } else {
                $token = $identity->generateToken(['user' => $options['user']]);

                $cache->put(
                    'openstack-token',
                    $token->export(),
                    Carbon::now()->diffInSeconds(Carbon::createFromImmutable($token->expires))
                );

                $options['cachedToken'] = $token->export();
            }

            $container = $openstack->objectStoreV1()->getContainer($config['containerName']);

            $adapter = new OvhSwiftStorageAdapter($container, $config['prefix'], $options['publicUrl']);

            return new Filesystem($adapter);
        });
    }

    /**
     * Get the container public url.
     *
     * @param array $config
     * @return string|null
     */
    private function getContainerPublicUrl(array $config): ?string
    {
        if (isset($config['visibility']) && $config['visibility'] === 'public') {
            if (isset($config['publicUrl']) && $config['publicUrl']) {
                $base = $config['publicUrl'];
            } else {
                $region = strtolower($config['region']);

                $base = "https://storage.{$region}.cloud.ovh.net/v1/AUTH_{$config['projectId']}/{$config['containerName']}";
            }

            return (isset($config['prefix']) && $config['prefix']) ? "{$base}/{$config['prefix']}" : $base;
        }

        return null;
    }
}
