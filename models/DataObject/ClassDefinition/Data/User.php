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

namespace Pimcore\Model\DataObject\ClassDefinition\Data;

use Pimcore\Model;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Service;
use Pimcore\Model\DataObject\Concrete;

class User extends Model\DataObject\ClassDefinition\Data\Select
{
    /**
     * Static type of this element
     *
     * @internal
     *
     * @var string
     */
    public string $fieldtype = 'user';

    /**
     * @internal
     */
    public bool $unique = false;

    /**
     * @internal
     *
     * @return $this
     */
    protected function init(): static
    {
        //loads select list options
        $options = $this->getOptions();
        if (\Pimcore::inAdmin() || empty($options)) {
            $this->configureOptions();
        }

        return $this;
    }

    /**
     * @param mixed $data
     * @param Concrete|null $object
     * @param array $params
     *
     * @return string|null
     *
     * @see ResourcePersistenceAwareInterface::getDataFromResource
     *
     */
    public function getDataFromResource(mixed $data, Concrete $object = null, array $params = []): ?string
    {
        if (!empty($data)) {
            try {
                $this->checkValidity($data, true, $params);
            } catch (\Exception $e) {
                $data = null;
            }
        }

        return $data ? (string) $data : null;
    }

    /**
     * @param mixed $data
     * @param Model\DataObject\Concrete|null $object
     * @param array $params
     *
     * @return null|string
     *
     *@see ResourcePersistenceAwareInterface::getDataForResource
     *
     */
    public function getDataForResource(mixed $data, DataObject\Concrete $object = null, array $params = []): ?string
    {
        $this->init();
        if (!empty($data)) {
            try {
                $this->checkValidity($data, true, $params);
            } catch (\Exception $e) {
                $data = null;
            }
        }

        return $data;
    }

    /**
     * @internal
     */
    public function configureOptions()
    {
        $list = new Model\User\Listing();
        $list->setOrder('asc');
        $list->setOrderKey('name');
        $users = $list->load();

        $options = [];
        if (is_array($users) && count($users) > 0) {
            foreach ($users as $user) {
                if ($user instanceof Model\User) {
                    $value = $user->getName();
                    $first = $user->getFirstname();
                    $last = $user->getLastname();
                    if (!empty($first) || !empty($last)) {
                        $value .= ' (' . $first . ' ' . $last . ')';
                    }
                    $options[] = [
                        'value' => $user->getId(),
                        'key' => $value,
                    ];
                }
            }
        }
        $this->setOptions($options);
    }

    /**
     * {@inheritdoc}
     */
    public function checkValidity(mixed $data, bool $omitMandatoryCheck = false, array $params = [])
    {
        if (!$omitMandatoryCheck && $this->getMandatory() && empty($data)) {
            throw new Model\Element\ValidationException('Empty mandatory field [ '.$this->getName().' ]');
        }

        if (!empty($data)) {
            $user = Model\User::getById((int)$data);
            if (!$user instanceof Model\User) {
                throw new Model\Element\ValidationException('Invalid user reference');
            }
        }
    }

    public function getDataForSearchIndex(DataObject\Localizedfield|DataObject\Fieldcollection\Data\AbstractData|DataObject\Objectbrick\Data\AbstractData|DataObject\Concrete $object, array $params = []): string
    {
        return '';
    }

    /**
     * @param array $data
     *
     * @return static
     */
    public static function __set_state($data)
    {
        $obj = parent::__set_state($data);

        if (\Pimcore::inAdmin()) {
            $obj->configureOptions();
        }

        return $obj;
    }

    public function __sleep()
    {
        $vars = get_object_vars($this);
        unset($vars['options']);

        return array_keys($vars);
    }

    public function __wakeup()
    {
        //loads select list options
        $this->init();
    }

    public function jsonSerialize(): static
    {
        if (Service::doRemoveDynamicOptions()) {
            $this->options = null;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveBlockedVars(): array
    {
        $blockedVars = parent::resolveBlockedVars();
        $blockedVars[] = 'options';

        return $blockedVars;
    }

    public function getUnique(): bool
    {
        return $this->unique;
    }

    public function setUnique(bool $unique)
    {
        $this->unique = (bool) $unique;
    }
}
