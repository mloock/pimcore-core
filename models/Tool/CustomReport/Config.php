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

namespace Pimcore\Model\Tool\CustomReport;

use Pimcore\Model;

/**
 * @internal
 *
 * @method bool isWriteable()
 * @method string getWriteTarget()
 * @method void delete()
 * @method void save()
 */
class Config extends Model\AbstractModel implements \JsonSerializable
{
    protected string $name = '';

    protected string $sql = '';

    protected array $dataSourceConfig = [];

    protected array $columnConfiguration = [];

    protected string $niceName = '';

    protected string $group = '';

    protected string $groupIconClass = '';

    protected string $iconClass = '';

    protected bool $menuShortcut = true;

    protected string $reportClass = '';

    protected string $chartType = '';

    protected ?string $pieColumn = null;

    protected ?string $pieLabelColumn = null;

    protected ?string $xAxis = null;

    protected null|string|array $yAxis = null;

    protected ?int $modificationDate = null;

    protected ?int $creationDate = null;

    protected bool $shareGlobally = true;

    /**
     * @var string[]
     */
    protected array $sharedUserNames = [];

    /**
     * @var string[]
     */
    protected array $sharedRoleNames = [];

    /**
     * @param string $name
     *
     * @return null|Config
     *
     * @throws \Exception
     */
    public static function getByName(string $name): ?Config
    {
        try {
            $report = new self();

            /** @var Model\Tool\CustomReport\Config\Dao $dao */
            $dao = $report->getDao();
            $dao->getByName($name);

            return $report;
        } catch (Model\Exception\NotFoundException $e) {
            return null;
        }
    }

    /**
     * @param Model\User|null $user
     *
     * @return array
     */
    public static function getReportsList(Model\User $user = null): array
    {
        $reports = [];

        $list = new Config\Listing();
        if ($user) {
            $items = $list->getDao()->loadForGivenUser($user);
        } else {
            $items = $list->getDao()->loadList();
        }

        foreach ($items as $item) {
            $reports[] = [
                'id' => $item->getName(),
                'text' => $item->getName(),
                'cls' => 'pimcore_treenode_disabled',
                'writeable' => $item->isWriteable(),
            ];
        }

        return $reports;
    }

    /**
     * @param ?\stdClass $configuration
     * @param Config|null $fullConfig
     *
     * @return Model\Tool\CustomReport\Adapter\CustomReportAdapterInterface
     *
     *@deprecated Use ServiceLocator with id 'pimcore.custom_report.adapter.factories' to determine the factory for the adapter instead
     *
     */
    public static function getAdapter(?\stdClass $configuration, Config $fullConfig = null): Adapter\CustomReportAdapterInterface
    {
        if ($configuration === null) {
            $configuration = new \stdClass();
        }

        $type = $configuration->type ?: 'sql';
        $serviceLocator = \Pimcore::getContainer()->get('pimcore.custom_report.adapter.factories');

        if (!$serviceLocator->has($type)) {
            throw new \RuntimeException(sprintf('Could not find Custom Report Adapter with type %s', $type));
        }

        /** @var Model\Tool\CustomReport\Adapter\CustomReportAdapterFactoryInterface $factory */
        $factory = $serviceLocator->get($type);

        return $factory->create($configuration, $fullConfig);
    }

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setSql(string $sql)
    {
        $this->sql = $sql;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function setColumnConfiguration(array $columnConfiguration)
    {
        $this->columnConfiguration = $columnConfiguration;
    }

    public function getColumnConfiguration(): array
    {
        return $this->columnConfiguration;
    }

    public function setGroup(string $group)
    {
        $this->group = $group;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function setGroupIconClass(string $groupIconClass)
    {
        $this->groupIconClass = $groupIconClass;
    }

    public function getGroupIconClass(): string
    {
        return $this->groupIconClass;
    }

    public function setIconClass(string $iconClass)
    {
        $this->iconClass = $iconClass;
    }

    public function getIconClass(): string
    {
        return $this->iconClass;
    }

    public function setNiceName(string $niceName)
    {
        $this->niceName = $niceName;
    }

    public function getNiceName(): string
    {
        return $this->niceName;
    }

    public function setMenuShortcut(bool $menuShortcut)
    {
        $this->menuShortcut = (bool) $menuShortcut;
    }

    public function getMenuShortcut(): bool
    {
        return $this->menuShortcut;
    }

    public function setDataSourceConfig(array $dataSourceConfig)
    {
        $this->dataSourceConfig = $dataSourceConfig;
    }

    public function getDataSourceConfig(): ?\stdClass
    {
        if (is_array($this->dataSourceConfig) && isset($this->dataSourceConfig[0])) {
            $dataSourceConfig = new \stdClass();
            $dataSourceConfigArray = $this->dataSourceConfig[0];

            foreach ($dataSourceConfigArray as $key => $value) {
                $dataSourceConfig->$key = $value;
            }

            return $dataSourceConfig;
        }

        return null;
    }

    public function setChartType(string $chartType)
    {
        $this->chartType = $chartType;
    }

    public function getChartType(): string
    {
        return $this->chartType;
    }

    public function setPieColumn(?string $pieColumn)
    {
        $this->pieColumn = $pieColumn;
    }

    public function getPieColumn(): ?string
    {
        return $this->pieColumn;
    }

    public function setXAxis(?string $xAxis)
    {
        $this->xAxis = $xAxis;
    }

    public function getXAxis(): ?string
    {
        return $this->xAxis;
    }

    public function setYAxis(array|string|null $yAxis)
    {
        $this->yAxis = $yAxis;
    }

    public function getYAxis(): array|string|null
    {
        return $this->yAxis;
    }

    public function setPieLabelColumn(?string $pieLabelColumn)
    {
        $this->pieLabelColumn = $pieLabelColumn;
    }

    public function getPieLabelColumn(): ?string
    {
        return $this->pieLabelColumn;
    }

    public function getModificationDate(): ?int
    {
        return $this->modificationDate;
    }

    public function setModificationDate(int $modificationDate)
    {
        $this->modificationDate = $modificationDate;
    }

    public function getCreationDate(): ?int
    {
        return $this->creationDate;
    }

    public function setCreationDate(int $creationDate)
    {
        $this->creationDate = $creationDate;
    }

    public function getReportClass(): string
    {
        return $this->reportClass;
    }

    public function setReportClass(string $reportClass)
    {
        $this->reportClass = $reportClass;
    }

    public function getShareGlobally(): bool
    {
        return $this->shareGlobally;
    }

    public function setShareGlobally(bool $shareGlobally): void
    {
        $this->shareGlobally = $shareGlobally;
    }

    /**
     * @return int[]
     */
    public function getSharedUserIds(): array
    {
        $sharedUserIds = [];
        if ($this->sharedUserNames) {
            foreach ($this->sharedUserNames as $username) {
                $user = Model\User::getByName($username);
                if ($user) {
                    $sharedUserIds[] = $user->getId();
                }
            }
        }

        return $sharedUserIds;
    }

    /**
     * @return int[]
     */
    public function getSharedRoleIds(): array
    {
        $sharedRoleIds = [];
        if ($this->sharedRoleNames) {
            foreach ($this->sharedRoleNames as $name) {
                $role = Model\User\Role::getByName($name);
                if ($role) {
                    $sharedRoleIds[] = $role->getId();
                }
            }
        }

        return $sharedRoleIds;
    }

    /**
     * @return string[]
     */
    public function getSharedUserNames(): array
    {
        return $this->sharedUserNames;
    }

    /**
     * @param string[] $sharedUserNames
     */
    public function setSharedUserNames(array $sharedUserNames): void
    {
        $this->sharedUserNames = $sharedUserNames;
    }

    /**
     * @return string[]
     */
    public function getSharedRoleNames(): array
    {
        return $this->sharedRoleNames;
    }

    /**
     * @param string[] $sharedRoleNames
     */
    public function setSharedRoleNames(array $sharedRoleNames): void
    {
        $this->sharedRoleNames = $sharedRoleNames;
    }

    public function jsonSerialize(): array
    {
        $data = $this->getObjectVars();
        $data['sharedUserIds'] = $this->getSharedUserIds();
        $data['sharedRoleIds'] = $this->getSharedRoleIds();

        return $data;
    }

    public function __clone()
    {
        if ($this->dao) {
            $this->dao = clone $this->dao;
            $this->dao->setModel($this);
        }
    }
}
