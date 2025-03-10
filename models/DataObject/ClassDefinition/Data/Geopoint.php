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

use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data\Geo\AbstractGeo;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ValidationException;
use Pimcore\Normalizer\NormalizerInterface;

class Geopoint extends AbstractGeo implements
    ResourcePersistenceAwareInterface,
    QueryResourcePersistenceAwareInterface,
    EqualComparisonInterface,
    VarExporterInterface,
    NormalizerInterface
{
    use Extension\ColumnType;
    use Extension\QueryColumnType;

    /**
     * Static type of this element
     *
     * @internal
     *
     * @var string
     */
    public string $fieldtype = 'geopoint';

    /**
     * Type for the column to query
     *
     * @internal
     *
     * @var array
     */
    public $queryColumnType = [
        'longitude' => 'double',
        'latitude' => 'double',
    ];

    /**
     * Type for the column
     *
     * @internal
     *
     * @var array
     */
    public $columnType = [
        'longitude' => 'double',
        'latitude' => 'double',
    ];

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return array
     *
     * @see ResourcePersistenceAwareInterface::getDataForResource
     *
     */
    public function getDataForResource(mixed $data, DataObject\Concrete $object = null, array $params = []): array
    {
        if ($data instanceof DataObject\Data\GeoCoordinates) {
            return [
                $this->getName() . '__longitude' => $data->getLongitude(),
                $this->getName() . '__latitude' => $data->getLatitude(),
            ];
        }

        return [
            $this->getName() . '__longitude' => null,
            $this->getName() . '__latitude' => null,
        ];
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return DataObject\Data\GeoCoordinates|null
     *
     * @see ResourcePersistenceAwareInterface::getDataFromResource
     *
     */
    public function getDataFromResource(mixed $data, Concrete $object = null, array $params = []): ?DataObject\Data\GeoCoordinates
    {
        if ($data[$this->getName() . '__longitude'] && $data[$this->getName() . '__latitude']) {
            $geopoint = new DataObject\Data\GeoCoordinates($data[$this->getName() . '__latitude'], $data[$this->getName() . '__longitude']);

            if (isset($params['owner'])) {
                $geopoint->_setOwner($params['owner']);
                $geopoint->_setOwnerFieldname($params['fieldname']);
                $geopoint->_setOwnerLanguage($params['language'] ?? null);
            }

            return $geopoint;
        }

        return null;
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return array
     *
     *@see QueryResourcePersistenceAwareInterface::getDataForQueryResource
     *
     */
    public function getDataForQueryResource(mixed $data, DataObject\Concrete $object = null, array $params = []): array
    {
        return $this->getDataForResource($data, $object, $params);
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return array|null
     *
     * @see Data::getDataForEditmode
     *
     */
    public function getDataForEditmode(mixed $data, DataObject\Concrete $object = null, array $params = []): ?array
    {
        if ($data instanceof DataObject\Data\GeoCoordinates) {
            return [
                'longitude' => $data->getLongitude(),
                'latitude' => $data->getLatitude(),
            ];
        }

        return null;
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return DataObject\Data\GeoCoordinates|null
     *
     *@see Data::getDataFromEditmode
     *
     */
    public function getDataFromEditmode(mixed $data, DataObject\Concrete $object = null, array $params = []): ?DataObject\Data\GeoCoordinates
    {
        if (is_array($data) && ($data['longitude'] || $data['latitude'])) {
            return new DataObject\Data\GeoCoordinates($data['latitude'], $data['longitude']);
        }

        return null;
    }

    /**
     * @param array|null $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return DataObject\Data\GeoCoordinates|null
     */
    public function getDataFromGridEditor(?array $data, Concrete $object = null, array $params = []): ?DataObject\Data\GeoCoordinates
    {
        return $this->getDataFromEditmode($data, $object, $params);
    }

    /**
     * @param mixed $data
     * @param DataObject\Concrete|null $object
     * @param array $params
     *
     * @return string
     *
     * @see Data::getVersionPreview
     *
     */
    public function getVersionPreview(mixed $data, DataObject\Concrete $object = null, array $params = []): string
    {
        if ($data instanceof DataObject\Data\GeoCoordinates) {
            return $data->getLatitude().','.$data->getLongitude();
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getForCsvExport(DataObject\Localizedfield|DataObject\Fieldcollection\Data\AbstractData|DataObject\Objectbrick\Data\AbstractData|DataObject\Concrete $object, array $params = []): string
    {
        $data = $this->getDataFromObjectParam($object, $params);
        if ($data instanceof DataObject\Data\GeoCoordinates) {
            return $data->getLatitude() . ',' . $data->getLongitude();
        }

        return '';
    }

    public function getDataForSearchIndex(DataObject\Localizedfield|DataObject\Fieldcollection\Data\AbstractData|DataObject\Objectbrick\Data\AbstractData|DataObject\Concrete $object, array $params = []): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function isDiffChangeAllowed(Concrete $object, array $params = []): bool
    {
        return true;
    }

    public function normalize(mixed $value, array $params = []): ?array
    {
        if ($value instanceof DataObject\Data\GeoCoordinates) {
            return [
                'latitude' => $value->getLatitude(),
                'longitude' => $value->getLongitude(),
            ];
        }

        return null;
    }

    public function denormalize(mixed $value, array $params = []): ?DataObject\Data\GeoCoordinates
    {
        if (is_array($value)) {
            $coordinates = new DataObject\Data\GeoCoordinates($value['latitude'], $value['longitude']);

            $coordinates->_setOwnerFieldname($params['fieldname'] ?? null);
            $coordinates->_setOwner($params['owner'] ?? null);
            $coordinates->_setOwnerLanguage($params['language'] ?? null);

            return $coordinates;
        }

        return null;
    }

    /**
     * @param DataObject\Data\GeoCoordinates|null $data
     * @param DataObject\Concrete|null $object
     * @param array $params
     *
     * @return array|null
     */
    public function getDataForGrid(?DataObject\Data\GeoCoordinates $data, Concrete $object = null, array $params = []): ?array
    {
        return $this->getDataForEditmode($data, $object, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function checkValidity(mixed $data, bool $omitMandatoryCheck = false, array $params = [])
    {
        $isEmpty = true;

        if ($data) {
            if (!$data instanceof DataObject\Data\GeoCoordinates) {
                throw new ValidationException('Expected an instance of GeoCoordinates');
            }
            $isEmpty = false;
        }

        if (!$omitMandatoryCheck && $this->getMandatory() && $isEmpty) {
            throw new ValidationException('Empty mandatory field [ ' . $this->getName() . ' ]');
        }
    }

    public function isEqual(mixed $oldValue, mixed $newValue): bool
    {
        if ($oldValue === null && $newValue === null) {
            return true;
        }

        if (!$oldValue instanceof DataObject\Data\GeoCoordinates
            || !$newValue instanceof DataObject\Data\GeoCoordinates) {
            return false;
        }

        return (abs($oldValue->getLongitude() - $newValue->getLongitude()) < 0.000000000001)
            && (abs($oldValue->getLatitude() - $newValue->getLatitude()) < 0.000000000001);
    }

    public function getParameterTypeDeclaration(): ?string
    {
        return '?\\' . DataObject\Data\GeoCoordinates::class;
    }

    public function getReturnTypeDeclaration(): ?string
    {
        return '?\\' . DataObject\Data\GeoCoordinates::class;
    }

    public function getPhpdocInputType(): ?string
    {
        return '\\' . DataObject\Data\GeoCoordinates::class . '|null';
    }

    public function getPhpdocReturnType(): ?string
    {
        return '\\' . DataObject\Data\GeoCoordinates::class . '|null';
    }
}
