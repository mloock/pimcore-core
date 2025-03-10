<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Config;

use Pimcore\Config;
use Pimcore\File;
use Pimcore\Helper\StopMessengerWorkersTrait;
use Pimcore\Model\Tool\SettingsStore;
use Symfony\Component\Yaml\Yaml;

class LocationAwareConfigRepository
{
    use StopMessengerWorkersTrait;

    public const LOCATION_SYMFONY_CONFIG = 'symfony-config';

    public const LOCATION_SETTINGS_STORE = 'settings-store';

    public const LOCATION_DISABLED = 'disabled';

    protected array $containerConfig = [];

    protected ?string $settingsStoreScope = null;

    protected ?string $storageDirectory = null;

    protected ?string $writeTargetEnvVariableName = null;

    protected ?string $defaultWriteLocation = self::LOCATION_SYMFONY_CONFIG;

    public function __construct(
        array $containerConfig,
        ?string $settingsStoreScope,
        ?string $storageDirectory,
        ?string $writeTargetEnvVariableName,
        ?string $defaultWriteLocation = null
    ) {
        $this->containerConfig = $containerConfig;
        $this->settingsStoreScope = $settingsStoreScope;
        $this->storageDirectory = rtrim($storageDirectory, '/\\');
        $this->writeTargetEnvVariableName = $writeTargetEnvVariableName;
        $this->defaultWriteLocation = $defaultWriteLocation ?: self::LOCATION_SYMFONY_CONFIG;
    }

    public function loadConfigByKey(string $key): array
    {
        $dataSource = null;

        // try to load from container config
        $data = $this->getDataFromContainerConfig($key, $dataSource);

        // try to load from SettingsStore
        if (!$data) {
            $data = $this->getDataFromSettingsStore($key, $dataSource);
        }

        return [
            $data,
            $dataSource,
        ];
    }

    private function getDataFromContainerConfig(string $key, ?string &$dataSource): mixed
    {
        if (isset($this->containerConfig[$key])) {
            $dataSource = self::LOCATION_SYMFONY_CONFIG;
        }

        return $this->containerConfig[$key] ?? null;
    }

    private function getDataFromSettingsStore(string $key, ?string &$dataSource): mixed
    {
        $settingsStoreEntryData = null;
        $settingsStoreEntry = SettingsStore::get($key, $this->settingsStoreScope);
        if ($settingsStoreEntry) {
            $settingsStoreEntryData = json_decode($settingsStoreEntry->getData(), true);
            $dataSource = self::LOCATION_SETTINGS_STORE;
        }

        return $settingsStoreEntryData;
    }

    /**
     * @param string|null $key
     * @param string|null $dataSource
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function isWriteable(?string $key = null, ?string $dataSource = null): bool
    {
        $key = $key ?: uniqid('pimcore_random_key_', true);
        $writeTarget = $this->getWriteTarget();

        if ($writeTarget === self::LOCATION_SYMFONY_CONFIG && !\Pimcore::getKernel()->isDebug()) {
            return false;
        } elseif ($writeTarget === self::LOCATION_DISABLED) {
            return false;
        } elseif ($dataSource === self::LOCATION_SYMFONY_CONFIG && !file_exists($this->getVarConfigFile($key))) {
            return false;
        }

        return true;
    }

    /**
     * @return string Can be either yaml (var/config/...) or "settings-store". defaults to "yaml"
     *
     * @throws \Exception
     */
    public function getWriteTarget(): string
    {
        $env = $this->writeTargetEnvVariableName ? $_SERVER[$this->writeTargetEnvVariableName] ?? null : null;
        if ($env) {
            $writeLocation = $env;
        } else {
            $writeLocation = $this->defaultWriteLocation;
        }

        if (!in_array($writeLocation, [self::LOCATION_SETTINGS_STORE, self::LOCATION_SYMFONY_CONFIG, self::LOCATION_DISABLED])) {
            throw new \Exception(sprintf('Invalid write location: %s', $writeLocation));
        }

        return $writeLocation;
    }

    /**
     * @param string $key
     * @param mixed $data
     * @param callable|null $yamlStructureCallback
     *
     * @throws \Exception
     */
    public function saveConfig(string $key, mixed $data, callable $yamlStructureCallback = null): void
    {
        $writeLocation = $this->getWriteTarget();

        if ($writeLocation === self::LOCATION_SYMFONY_CONFIG) {
            if (is_callable($yamlStructureCallback)) {
                $data = $yamlStructureCallback($key, $data);
            }

            $this->writeYaml($key, $data);
        } elseif ($writeLocation === self::LOCATION_SETTINGS_STORE) {
            $settingsStoreData = json_encode($data);
            SettingsStore::set($key, $settingsStoreData, 'string', $this->settingsStoreScope);
        }

        $this->stopMessengerWorkers();
    }

    private function writeYaml(string $key, array $data): void
    {
        $yamlFilename = $this->getVarConfigFile($key);

        $this->searchAndReplaceMissingParameters($data);

        File::put($yamlFilename, Yaml::dump($data, 50));

        $this->invalidateConfigCache();
    }

    private function searchAndReplaceMissingParameters(array &$data): void
    {
        $container = \Pimcore::getContainer();

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $this->searchAndReplaceMissingParameters($value);

                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            if (preg_match('/%([^%\s]+)%/', (string) $value, $match)) {
                $key = $match[1];

                if (str_starts_with($key, 'env(') && str_ends_with($key, ')')  && 'env()' !== $key) {
                    continue;
                }

                if (!$container->hasParameter($key)) {
                    $value = preg_replace('/%([^%\s]+)%/', '%%$1%%', $value);
                }
            }
        }
    }

    private function getVarConfigFile(string $key): string
    {
        return $this->storageDirectory . '/' . $key . '.yaml';
    }

    /**
     * @param string $key
     * @param string|null $dataSource
     *
     * @throws \Exception
     */
    public function deleteData(string $key, ?string $dataSource): void
    {
        if (!$this->isWriteable($key)) {
            throw new \Exception('You are trying to delete a non-writable configuration.');
        }

        if ($dataSource === self::LOCATION_SYMFONY_CONFIG) {
            unlink($this->getVarConfigFile($key));
            $this->invalidateConfigCache();
        } elseif ($dataSource === self::LOCATION_SETTINGS_STORE) {
            SettingsStore::delete($key, $this->settingsStoreScope);
        }

        $this->stopMessengerWorkers();
    }

    public function fetchAllKeys(): array
    {
        return array_unique(array_merge(
            SettingsStore::getIdsByScope($this->settingsStoreScope),
            array_keys($this->containerConfig)
        ));
    }

    private function invalidateConfigCache(): void
    {
        // invalidate container config cache if debug flag on kernel is set
        $systemConfigFile = Config::locateConfigFile('system.yaml');
        if ($systemConfigFile) {
            touch($systemConfigFile);
        }
    }
}
