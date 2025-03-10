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
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\Element;
use Pimcore\Normalizer\NormalizerInterface;
use Pimcore\Tool\DomCrawler;
use Pimcore\Tool\Text;

class Wysiwyg extends Data implements ResourcePersistenceAwareInterface, QueryResourcePersistenceAwareInterface, TypeDeclarationSupportInterface, EqualComparisonInterface, VarExporterInterface, NormalizerInterface, IdRewriterInterface, PreGetDataInterface
{
    use DataObject\ClassDefinition\Data\Extension\Text;
    use DataObject\Traits\DataHeightTrait;
    use DataObject\Traits\DataWidthTrait;
    use DataObject\Traits\SimpleComparisonTrait;
    use DataObject\Traits\SimpleNormalizerTrait;
    use Extension\ColumnType;
    use Extension\QueryColumnType;

    /**
     * Static type of this element
     *
     * @internal
     *
     * @var string
     */
    public string $fieldtype = 'wysiwyg';

    /**
     * Type for the column to query
     *
     * @internal
     *
     * @var string
     */
    public $queryColumnType = 'longtext';

    /**
     * Type for the column
     *
     * @internal
     *
     * @var string
     */
    public $columnType = 'longtext';

    /**
     * @internal
     */
    public string $toolbarConfig = '';

    /**
     * @internal
     */
    public bool $excludeFromSearchIndex = false;

    /**
     * @internal
     *
     * @var string|int
     */
    public string|int $maxCharacters = 0;

    public function setToolbarConfig(string $toolbarConfig)
    {
        $this->toolbarConfig = $toolbarConfig;
    }

    public function getToolbarConfig(): string
    {
        return $this->toolbarConfig;
    }

    public function isExcludeFromSearchIndex(): bool
    {
        return $this->excludeFromSearchIndex;
    }

    public function setExcludeFromSearchIndex(bool $excludeFromSearchIndex): static
    {
        $this->excludeFromSearchIndex = $excludeFromSearchIndex;

        return $this;
    }

    public function getMaxCharacters(): int|string
    {
        return $this->maxCharacters;
    }

    public function setMaxCharacters(int|string $maxCharacters)
    {
        $this->maxCharacters = $maxCharacters;
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return string|null
     *
     *@see ResourcePersistenceAwareInterface::getDataForResource
     *
     */
    public function getDataForResource(mixed $data, DataObject\Concrete $object = null, array $params = []): ?string
    {
        return Text::wysiwygText($data);
    }

    /**
     * @param mixed $data
     * @param DataObject\Concrete|null $object
     * @param array $params
     *
     * @return string|null
     *
     *@see ResourcePersistenceAwareInterface::getDataFromResource
     *
     */
    public function getDataFromResource(mixed $data, DataObject\Concrete $object = null, array $params = []): ?string
    {
        return Text::wysiwygText($data);
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return string|null
     *
     *@see QueryResourcePersistenceAwareInterface::getDataForQueryResource
     *
     */
    public function getDataForQueryResource(mixed $data, DataObject\Concrete $object = null, array $params = []): ?string
    {
        $data = $this->getDataForResource($data, $object, $params);

        if (null !== $data) {
            $data = strip_tags($data, '<a><img>');
            $data = str_replace("\r\n", ' ', $data);
            $data = str_replace("\n", ' ', $data);
            $data = str_replace("\r", ' ', $data);
            $data = str_replace("\t", '', $data);
            $data = preg_replace('#[ ]+#', ' ', $data);
        }

        return $data;
    }

    public function getDataForSearchIndex(DataObject\Localizedfield|DataObject\Fieldcollection\Data\AbstractData|DataObject\Objectbrick\Data\AbstractData|DataObject\Concrete $object, array $params = []): string
    {
        if ($this->isExcludeFromSearchIndex()) {
            return '';
        } else {
            return parent::getDataForSearchIndex($object, $params);
        }
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return string|null
     *
     * @see Data::getDataForEditmode
     *
     */
    public function getDataForEditmode(mixed $data, DataObject\Concrete $object = null, array $params = []): ?string
    {
        return $this->getDataForResource($data, $object, $params);
    }

    /**
     * @param mixed $data
     * @param null|DataObject\Concrete $object
     * @param array $params
     *
     * @return string
     *
     * @see Data::getDataFromEditmode
     *
     */
    public function getDataFromEditmode(mixed $data, DataObject\Concrete $object = null, array $params = []): string
    {
        return $data ?? '';
    }

    public function resolveDependencies(mixed $data): array
    {
        return Text::getDependenciesOfWysiwygText($data);
    }

    public function getCacheTags(mixed $data, $tags = []): array
    {
        return Text::getCacheTagsOfWysiwygText($data, $tags);
    }

    /**
     * {@inheritdoc}
     */
    public function checkValidity(mixed $data, bool $omitMandatoryCheck = false, array $params = [])
    {
        if (!$omitMandatoryCheck && $this->getMandatory() && empty($data)) {
            throw new Element\ValidationException('Empty mandatory field [ '.$this->getName().' ]');
        }
        $dependencies = Text::getDependenciesOfWysiwygText($data);
        if (is_array($dependencies)) {
            foreach ($dependencies as $key => $value) {
                $el = Element\Service::getElementById($value['type'], $value['id']);
                if (!$el) {
                    throw new Element\ValidationException('Invalid dependency in wysiwyg text');
                }
            }
        }
    }

    public function preGetData(mixed $container, array $params = []): ?string
    {
        $data = '';
        if ($container instanceof DataObject\Concrete) {
            $data = $container->getObjectVar($this->getName());
        } elseif ($container instanceof DataObject\Localizedfield || $container instanceof DataObject\Classificationstore) {
            $data = $params['data'];
        } elseif ($container instanceof DataObject\Fieldcollection\Data\AbstractData) {
            $data = $container->getObjectVar($this->getName());
        } elseif ($container instanceof DataObject\Objectbrick\Data\AbstractData) {
            $data = $container->getObjectVar($this->getName());
        }

        return Text::wysiwygText($data, [
                'object' => $container,
                'context' => $this,
            ]);
    }

    /** Generates a pretty version preview (similar to getVersionPreview) can be either html or
     * a image URL. See the https://github.com/pimcore/object-merger bundle documentation for details
     *
     * @param string|null $data
     * @param DataObject\Concrete|null $object
     * @param array $params
     *
     * @return array|string
     */
    public function getDiffVersionPreview(?string $data, DataObject\Concrete $object = null, array $params = []): array|string
    {
        if ($data) {
            $value = [];
            $value['html'] = $data;
            $value['type'] = 'html';

            return $value;
        } else {
            return '';
        }
    }

    /**
     * { @inheritdoc }
     */
    public function rewriteIds(mixed $container, array $idMapping, array $params = []): mixed
    {
        $data = $this->getDataFromObjectParam($container, $params);

        if ($data) {
            $html = new DomCrawler($data);
            $es = $html->filter('a[pimcore_id], img[pimcore_id]');

            /** @var \DOMElement $el */
            foreach ($es as $el) {
                if ($el->hasAttribute('href') || $el->hasAttribute('src')) {
                    $type = $el->getAttribute('pimcore_type');
                    $id = (int) $el->getAttribute('pimcore_id');

                    if ($idMapping[$type][$id] ?? false) {
                        $el->setAttribute('pimcore_id', strtr($el->getAttribute('pimcore_id'), $idMapping[$type]));
                    }
                }
            }

            $data = $html->html();

            $html->clear();
            unset($html);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function isFilterable(): bool
    {
        return true;
    }

    public function getParameterTypeDeclaration(): ?string
    {
        return '?string';
    }

    public function getReturnTypeDeclaration(): ?string
    {
        return '?string';
    }

    public function getPhpdocInputType(): ?string
    {
        return 'string|null';
    }

    public function getPhpdocReturnType(): ?string
    {
        return 'string|null';
    }
}
