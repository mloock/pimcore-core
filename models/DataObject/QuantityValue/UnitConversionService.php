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

namespace Pimcore\Model\DataObject\QuantityValue;

use Pimcore\Model\DataObject\ClassDefinition\Helper\UnitConverterResolver;
use Pimcore\Model\DataObject\Data\AbstractQuantityValue;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\Exception\UnsupportedException;

class UnitConversionService
{
    public function __construct(protected QuantityValueConverterInterface $defaultConverter)
    {
    }

    /**
     * @template T of AbstractQuantityValue
     *
     * @param T $quantityValue
     * @param Unit $toUnit
     *
     * @return QuantityValue
     *
     * @throws UnsupportedException If $quantityValue is no QuantityValue
     * @throws \Exception
     */
    public function convert(AbstractQuantityValue $quantityValue, Unit $toUnit): QuantityValue
    {
        if (!$quantityValue instanceof QuantityValue) {
            throw new UnsupportedException('Only QuantityValue is supported.');
        }
        $baseUnit = $toUnit->getBaseunit();

        if ($baseUnit === null) {
            $baseUnit = $toUnit;
        }

        $converterServiceName = $baseUnit->getConverter();
        if ($converterServiceName) {
            $converterService = UnitConverterResolver::resolveUnitConverter($converterServiceName);
        } else {
            $converterService = $this->defaultConverter;
        }

        if (!$converterService instanceof QuantityValueConverterInterface) {
            throw new \Exception('Converter class needs to implement '.QuantityValueConverterInterface::class);
        }

        return $converterService->convert($quantityValue, $toUnit);
    }
}
