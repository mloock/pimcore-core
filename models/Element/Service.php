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

namespace Pimcore\Model\Element;

use DeepCopy\DeepCopy;
use DeepCopy\Filter\Doctrine\DoctrineCollectionFilter;
use DeepCopy\Filter\SetNullFilter;
use DeepCopy\Matcher\PropertyNameMatcher;
use DeepCopy\Matcher\PropertyTypeMatcher;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Query\QueryBuilder as DoctrineQueryBuilder;
use League\Csv\EscapeFormula;
use Pimcore;
use Pimcore\Db;
use Pimcore\Event\SystemEvents;
use Pimcore\File;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Dependency;
use Pimcore\Model\Document;
use Pimcore\Model\Element;
use Pimcore\Model\Element\DeepCopy\MarshalMatcher;
use Pimcore\Model\Element\DeepCopy\PimcoreClassDefinitionMatcher;
use Pimcore\Model\Element\DeepCopy\PimcoreClassDefinitionReplaceFilter;
use Pimcore\Model\Element\DeepCopy\UnmarshalMatcher;
use Pimcore\Model\Tool\TmpStore;
use Pimcore\Tool\Serialize;
use Pimcore\Tool\Session;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @method \Pimcore\Model\Element\Dao getDao()
 */
class Service extends Model\AbstractModel
{
    private static ?EscapeFormula $formatter = null;

    /**
     * @internal
     *
     * @param ElementInterface $element
     *
     * @return string
     */
    public static function getIdPath(ElementInterface $element): string
    {
        $path = '';
        $elementType = self::getElementType($element);
        $parentId = $element->getParentId();
        if (isset($parentId)) {
            $parentElement = self::getElementById($elementType, $parentId);

            if ($parentElement) {
                $path = self::getIdPath($parentElement);
            }
        }

        $path .= '/' . $element->getId();

        return $path;
    }

    /**
     * @internal
     *
     * @param ElementInterface $element
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function getTypePath(ElementInterface $element): string
    {
        $path = '';
        $elementType = self::getElementType($element);
        $parentId = $element->getParentId();
        $parentElement = self::getElementById($elementType, $parentId);

        if ($parentElement) {
            $path = self::getTypePath($parentElement);
        }

        $type = $element->getType();
        if ($type !== DataObject::OBJECT_TYPE_FOLDER) {
            if ($element instanceof Document) {
                $type = 'document';
            } elseif ($element instanceof DataObject\AbstractObject) {
                $type = 'object';
            } elseif ($element instanceof Asset) {
                $type = 'asset';
            } else {
                throw new \Exception('unknown type');
            }
        }
        $path .= '/' . $type;

        return $path;
    }

    /**
     * @internal
     *
     * @param ElementInterface $element
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function getSortIndexPath(ElementInterface $element): string
    {
        $path = '';
        $elementType = self::getElementType($element);
        $parentId = $element->getParentId();
        $parentElement = self::getElementById($elementType, $parentId);

        if ($parentElement) {
            $path = self::getSortIndexPath($parentElement);
        }

        $sortIndex = method_exists($element, 'getIndex') ? (int) $element->getIndex() : 0;
        $path .= '/' . $sortIndex;

        return $path;
    }

    /**
     * @param array|Model\Listing\AbstractListing $list
     * @param string $idGetter
     *
     * @return int[]
     *
     *@internal
     *
     */
    public static function getIdList(Model\Listing\AbstractListing|array $list, string $idGetter = 'getId'): array
    {
        $ids = [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_object($entry) && method_exists($entry, $idGetter)) {
                    $ids[] = $entry->$idGetter();
                } elseif (is_scalar($entry)) {
                    $ids[] = $entry;
                }
            }
        }

        if ($list instanceof Model\Listing\AbstractListing && method_exists($list, 'loadIdList')) {
            $ids = $list->loadIdList();
        }
        $ids = array_unique($ids);

        return $ids;
    }

    /**
     * @param Dependency $d
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return array
     *
     * @internal
     *
     */
    public static function getRequiredByDependenciesForFrontend(Dependency $d, ?int $offset, ?int $limit): array
    {
        $dependencies['hasHidden'] = false;
        $dependencies['requiredBy'] = [];

        // requiredBy
        foreach ($d->getRequiredBy($offset, $limit) as $r) {
            if ($e = self::getDependedElement($r)) {
                if ($e->isAllowed('list')) {
                    $dependencies['requiredBy'][] = self::getDependencyForFrontend($e);
                } else {
                    $dependencies['hasHidden'] = true;
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param Dependency $d
     * @param int|null $offset
     * @param int|null $limit
     *
     * @return array
     *
     * @internal
     *
     */
    public static function getRequiresDependenciesForFrontend(Dependency $d, ?int $offset, ?int $limit): array
    {
        $dependencies['hasHidden'] = false;
        $dependencies['requires'] = [];

        // requires
        foreach ($d->getRequires($offset, $limit) as $r) {
            if ($e = self::getDependedElement($r)) {
                if ($e->isAllowed('list')) {
                    $dependencies['requires'][] = self::getDependencyForFrontend($e);
                } else {
                    $dependencies['hasHidden'] = true;
                }
            }
        }

        return $dependencies;
    }

    private static function getDependencyForFrontend(ElementInterface $element): array
    {
        return [
            'id' => $element->getId(),
            'path' => $element->getRealFullPath(),
            'type' => self::getElementType($element),
            'subtype' => $element->getType(),
            'published' => self::isPublished($element),
        ];
    }

    private static function getDependedElement(array $config): Asset|Document|AbstractObject|null
    {
        if ($config['type'] == 'object') {
            return DataObject::getById($config['id']);
        } elseif ($config['type'] == 'asset') {
            return Asset::getById($config['id']);
        } elseif ($config['type'] == 'document') {
            return Document::getById($config['id']);
        }

        return null;
    }

    /**
     * @static
     *
     */
    public static function doHideUnpublished($element): bool
    {
        return ($element instanceof AbstractObject && DataObject::doHideUnpublished())
            || ($element instanceof Document && Document::doHideUnpublished());
    }

    /**
     * determines whether an element is published
     *
     * @param  ElementInterface|null $element
     *
     * @return bool
     *
     *@internal
     *
     */
    public static function isPublished(ElementInterface $element = null): bool
    {
        if ($element instanceof ElementInterface) {
            if (method_exists($element, 'isPublished')) {
                return $element->isPublished();
            } else {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array|null $data
     *
     * @return array
     *
     * @throws \Exception
     *
     *@internal
     *
     */
    public static function filterUnpublishedAdvancedElements(?array $data): array
    {
        if (DataObject::doHideUnpublished() && is_array($data)) {
            $publishedList = [];
            $mapping = [];
            foreach ($data as $advancedElement) {
                if (!$advancedElement instanceof DataObject\Data\ObjectMetadata
                    && !$advancedElement instanceof DataObject\Data\ElementMetadata) {
                    throw new \Exception('only supported for advanced many-to-many (+object) relations');
                }

                $elementId = null;
                if ($advancedElement instanceof DataObject\Data\ObjectMetadata) {
                    $elementId = $advancedElement->getObjectId();
                    $elementType = 'object';
                } else {
                    $elementId = $advancedElement->getElementId();
                    $elementType = $advancedElement->getElementType();
                }

                if (!$elementId) {
                    continue;
                }
                if ($elementType == 'asset') {
                    // there is no published flag for assets
                    continue;
                }
                $mapping[$elementType][$elementId] = true;
            }

            $db = Db::get();
            $publishedMapping = [];

            // now do the query;
            foreach ($mapping as $elementType => $idList) {
                $idList = array_keys($mapping[$elementType]);
                $query = 'SELECT id FROM ' . $elementType . 's WHERE published=1 AND id IN (' . implode(',', $idList) . ');';
                $publishedIds = $db->fetchFirstColumn($query);
                $publishedMapping[$elementType] = $publishedIds;
            }

            foreach ($data as $advancedElement) {
                $elementId = null;
                if ($advancedElement instanceof DataObject\Data\ObjectMetadata) {
                    $elementId = $advancedElement->getObjectId();
                    $elementType = 'object';
                } else {
                    $elementId = $advancedElement->getElementId();
                    $elementType = $advancedElement->getElementType();
                }

                if ($elementType == 'asset') {
                    $publishedList[] = $advancedElement;
                }

                if (isset($publishedMapping[$elementType]) && in_array($elementId, $publishedMapping[$elementType])) {
                    $publishedList[] = $advancedElement;
                }
            }

            return $publishedList;
        }

        return is_array($data) ? $data : [];
    }

    public static function getElementByPath(string $type, string $path): ?ElementInterface
    {
        $element = null;

        if ($type == 'asset') {
            $element = Asset::getByPath($path);
        } elseif ($type == 'object') {
            $element = DataObject::getByPath($path);
        } elseif ($type == 'document') {
            $element = Document::getByPath($path);
        }

        return $element;
    }

    /**
     * @param string|ElementInterface $element
     *
     * @return string
     *
     * @throws \Exception
     *
     *@internal
     *
     */
    public static function getBaseClassNameForElement(string|ElementInterface $element): string
    {
        if ($element instanceof ElementInterface) {
            $elementType = self::getElementType($element);
        } elseif (is_string($element)) {
            $elementType = $element;
        } else {
            throw new \Exception('Wrong type given for getBaseClassNameForElement(), ElementInterface and string are allowed');
        }

        $baseClass = ucfirst($elementType);
        if ($elementType == 'object') {
            $baseClass = 'DataObject';
        }

        return $baseClass;
    }

    /**
     * @param string $type
     * @param string $sourceKey
     * @param ElementInterface $target
     *
     * @return string
     *
     *@deprecated will be removed in Pimcore 11, use getSafeCopyName() instead
     *
     */
    public static function getSaveCopyName(string $type, string $sourceKey, ElementInterface $target): string
    {
        return self::getSafeCopyName($sourceKey, $target);
    }

    /**
     * Returns a uniqe key for the element in the $target-Path (recursive)
     *
     * @return string
     *
     * @param string $sourceKey
     * @param ElementInterface $target
     */
    public static function getSafeCopyName(string $sourceKey, ElementInterface $target): string
    {
        $type = self::getElementType($target);
        if (self::pathExists($target->getRealFullPath() . '/' . $sourceKey, $type)) {
            // only for assets: add the prefix _copy before the file extension (if exist) not after to that source.jpg will be source_copy.jpg and not source.jpg_copy
            if ($type == 'asset' && $fileExtension = File::getFileExtension($sourceKey)) {
                $sourceKey = preg_replace('/\.' . $fileExtension . '$/i', '_copy.' . $fileExtension, $sourceKey);
            } elseif (preg_match("/_copy(|_\d*)$/", $sourceKey) === 1) {
                // If key already ends with _copy or copy_N, append a digit to avoid _copy_copy_copy naming
                $keyParts = explode('_', $sourceKey);
                $counterKey = array_key_last($keyParts);
                if ((int)$keyParts[$counterKey] > 0) {
                    $keyParts[$counterKey] = (int)$keyParts[$counterKey] + 1;
                } else {
                    $keyParts[] = 1;
                }
                $sourceKey = implode('_', $keyParts);
            } else {
                $sourceKey .= '_copy';
            }

            return self::getSafeCopyName($sourceKey, $target);
        }

        return $sourceKey;
    }

    /**
     * @param string $path
     * @param string|null $type
     *
     * @return bool
     */
    public static function pathExists(string $path, string $type = null): bool
    {
        if ($type == 'asset') {
            return Asset\Service::pathExists($path);
        } elseif ($type == 'document') {
            return Document\Service::pathExists($path);
        } elseif ($type == 'object') {
            return DataObject\Service::pathExists($path);
        }

        return false;
    }

    public static function getElementById(string $type, int|string $id, array $params = []): Asset|Document|AbstractObject|null
    {
        $element = null;
        $params = self::prepareGetByIdParams($params);
        if ($type === 'asset') {
            $element = Asset::getById($id, $params);
        } elseif ($type === 'object') {
            $element = DataObject::getById($id, $params);
        } elseif ($type === 'document') {
            $element = Document::getById($id, $params);
        }

        return $element;
    }

    /**
     * @internal
     *
     * @param array $params
     *
     * @return array
     */
    public static function prepareGetByIdParams(array $params): array
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'force' => false,
        ]);

        $resolver->setAllowedTypes('force', 'bool');

        return $resolver->resolve($params);
    }

    /**
     * @static
     *
     * @param ElementInterface $element
     *
     * @return string|null
     */
    public static function getElementType(ElementInterface $element): ?string
    {
        if ($element instanceof DataObject\AbstractObject) {
            return 'object';
        }

        if ($element instanceof Document) {
            return 'document';
        }

        if ($element instanceof Asset) {
            return 'asset';
        }

        return null;
    }

    /**
     * @internal
     *
     * @param string $className
     *
     * @return string|null
     */
    public static function getElementTypeByClassName(string $className): ?string
    {
        $className = trim($className, '\\');
        if (is_a($className, AbstractObject::class, true)) {
            return 'object';
        }
        if (is_a($className, Asset::class, true)) {
            return 'asset';
        }
        if (is_a($className, Document::class, true)) {
            return 'document';
        }

        return null;
    }

    /**
     * @internal
     *
     * @param ElementInterface $element
     *
     * @return string|null
     */
    public static function getElementHash(ElementInterface $element): ?string
    {
        $elementType = self::getElementType($element);
        if ($elementType === null) {
            return null;
        }

        return $elementType . '-' . $element->getId();
    }

    /**
     * determines the type of an element (object,asset,document)
     *
     * @param  ElementInterface $element
     *
     * @return string|null
     *
     *@deprecated use getElementType() instead, will be removed in Pimcore 11
     *
     */
    public static function getType(ElementInterface $element): ?string
    {
        trigger_deprecation(
            'pimcore/pimcore',
            '10.0',
            'The Service::getType() method is deprecated, use Service::getElementType() instead.'
        );

        return self::getElementType($element);
    }

    /**
     * @param array $props
     *
     * @return array
     *
     *@internal
     *
     */
    public static function minimizePropertiesForEditmode(array $props): array
    {
        $properties = [];
        foreach ($props as $key => $p) {
            //$p = object2array($p);
            $allowedProperties = [
                'key',
                'filename',
                'path',
                'id',
                'type',
            ];

            if ($p->getData() instanceof Document || $p->getData() instanceof Asset || $p->getData() instanceof DataObject\AbstractObject) {
                $pa = [];

                $vars = $p->getData()->getObjectVars();

                foreach ($vars as $k => $value) {
                    if (in_array($k, $allowedProperties)) {
                        $pa[$k] = $value;
                    }
                }

                // clone it because of caching
                $tmp = clone $p;
                $tmp->setData($pa);
                $properties[$key] = $tmp->getObjectVars();
            } else {
                $properties[$key] = $p->getObjectVars();
            }

            // add config from predefined properties
            if ($p->getName() && $p->getType()) {
                $predefined = Model\Property\Predefined::getByKey($p->getName());

                if ($predefined && $predefined->getType() == $p->getType()) {
                    $properties[$key]['config'] = $predefined->getConfig();
                    $properties[$key]['predefinedName'] = $predefined->getName();
                    $properties[$key]['description'] = $predefined->getDescription();
                }
            }
        }

        return $properties;
    }

    /**
     * @param DataObject|Document|Asset\Folder $target the parent element
     * @param ElementInterface $new the newly inserted child
     *
     *@internal
     *
     */
    protected function updateChildren(DataObject|Document|Asset\Folder $target, ElementInterface $new)
    {
        //check in case of recursion
        $found = false;
        foreach ($target->getChildren() as $child) {
            if ($child->getId() == $new->getId()) {
                $found = true;

                break;
            }
        }
        if (!$found) {
            $newElement = Element\Service::getElementById($new->getType(), $new->getId());
            $target->setChildren(array_merge($target->getChildren(), [$newElement]));
        }
    }

    /**
     * @internal
     *
     * @param  ElementInterface $element
     *
     * @return array
     */
    public static function gridElementData(ElementInterface $element): array
    {
        $data = [
            'id' => $element->getId(),
            'fullpath' => $element->getRealFullPath(),
            'type' => self::getElementType($element),
            'subtype' => $element->getType(),
            'filename' => $element->getKey(),
            'creationDate' => $element->getCreationDate(),
            'modificationDate' => $element->getModificationDate(),
        ];

        if (method_exists($element, 'isPublished')) {
            $data['published'] = $element->isPublished();
        } else {
            $data['published'] = true;
        }

        return $data;
    }

    /**
     * find all elements which the user may not list and therefore may never be shown to the user.
     * A user may have custom workspaces and/or may inherit those from their role(s), if any.
     *
     * @param string $type asset|object|document
     * @param Model\User $user
     *
     * @return array{forbidden: array, allowed: array}
     *
     *@internal
     *
     */
    public static function findForbiddenPaths(string $type, Model\User $user): array
    {
        $db = Db::get();

        if ($user->isAdmin()) {
            return ['forbidden' => [], 'allowed' => ['/']];
        }

        $workspaceCids = [];
        $userWorkspaces = $db->fetchAllAssociative('SELECT cpath, cid, list FROM users_workspaces_' . $type . ' WHERE userId = ?', [$user->getId()]);
        if ($userWorkspaces) {
            // this collects the array that are on user-level, which have top priority
            foreach ($userWorkspaces as $userWorkspace) {
                $workspaceCids[] = $userWorkspace['cid'];
            }
        }

        if ($userRoleIds = $user->getRoles()) {
            $roleWorkspacesSql = 'SELECT cpath, userid, max(list) as list FROM users_workspaces_' . $type . ' WHERE userId IN (' . implode(',', $userRoleIds) . ')';
            if ($workspaceCids) {
                $roleWorkspacesSql .= ' AND cid NOT IN (' . implode(',', $workspaceCids) . ')';
            }
            $roleWorkspacesSql .= ' GROUP BY cpath';

            $roleWorkspaces = $db->fetchAllAssociative($roleWorkspacesSql);
        }

        $uniquePaths = [];
        foreach (array_merge($userWorkspaces, $roleWorkspaces ?? []) as $workspace) {
            $uniquePaths[$workspace['cpath']] = $workspace['list'];
        }
        ksort($uniquePaths);

        //TODO: above this should be all in one query (eg. instead of ksort, use sql sort) but had difficulties making the `group by` working properly to let user permissions take precedence

        $totalPaths = count($uniquePaths);

        $forbidden = [];
        $allowed = [];
        if ($totalPaths > 0) {
            $uniquePathsKeys = array_keys($uniquePaths);
            for ($index = 0; $index < $totalPaths; $index++) {
                $path = $uniquePathsKeys[$index];
                if ($uniquePaths[$path] == 0) {
                    $forbidden[$path] = [];
                    for ($findIndex = $index + 1; $findIndex < $totalPaths; $findIndex++) { //NB: the starting index is the last index we got
                        $findPath = $uniquePathsKeys[$findIndex];
                        if (str_contains($findPath, $path)) { //it means that we found a children
                            if ($uniquePaths[$findPath] == 1) {
                                array_push($forbidden[$path], $findPath); //adding list=1 children
                            }
                        } else {
                            break;
                        }
                    }
                } else {
                    $allowed[] = $path;
                }
            }
        } else {
            $forbidden['/'] = [];
        }

        return ['forbidden' => $forbidden, 'allowed' => $allowed];
    }

    /**
     * renews all references, for example after unserializing an ElementInterface
     *
     * @param mixed $data
     * @param bool $initial
     * @param string|null $key
     *
     * @return mixed
     *
     *@internal
     *
     */
    public static function renewReferences(mixed $data, bool $initial = true, string $key = null): mixed
    {
        if ($data instanceof \__PHP_Incomplete_Class) {
            Logger::err(sprintf('Renew References: Cannot read data (%s) of incomplete class.', is_null($key) ? 'not available' : $key));

            return null;
        }

        if (is_array($data)) {
            foreach ($data as $dataKey => &$value) {
                $value = self::renewReferences($value, false, (string)$dataKey);
            }

            return $data;
        }
        if (is_object($data)) {
            if ($data instanceof ElementInterface && !$initial) {
                return self::getElementById(self::getElementType($data), $data->getId());
            }

            // if this is the initial element set the correct path and key
            if ($data instanceof ElementInterface && !DataObject\AbstractObject::doNotRestoreKeyAndPath()) {
                $originalElement = self::getElementById(self::getElementType($data), $data->getId());

                if ($originalElement) {
                    //do not override filename for Assets https://github.com/pimcore/pimcore/issues/8316
//                    if ($data instanceof Asset) {
//                        /** @var Asset $originalElement */
//                        $data->setFilename($originalElement->getFilename());
//                    } else
                    if ($data instanceof Document) {
                        /** @var Document $originalElement */
                        $data->setKey($originalElement->getKey());
                    } elseif ($data instanceof DataObject\AbstractObject) {
                        /** @var AbstractObject $originalElement */
                        $data->setKey($originalElement->getKey());
                    }

                    $data->setPath($originalElement->getRealPath());
                }
            }

            if ($data instanceof Model\AbstractModel) {
                $properties = $data->getObjectVars();
                foreach ($properties as $name => $value) {
                    $data->setObjectVar($name, self::renewReferences($value, false, $name), true);
                }
            } else {
                $properties = method_exists($data, 'getObjectVars') ? $data->getObjectVars() : get_object_vars($data);
                foreach ($properties as $name => $value) {
                    if (method_exists($data, 'setObjectVar')) {
                        $data->setObjectVar($name, self::renewReferences($value, false, $name), true);
                    } else {
                        $data->$name = self::renewReferences($value, false, $name);
                    }
                }
            }

            return $data;
        }

        return $data;
    }

    /**
     * @internal
     *
     * @param string $path
     *
     * @return string
     */
    public static function correctPath(string $path): string
    {
        // remove trailing slash
        if ($path !== '/') {
            $path = rtrim($path, '/ ');
        }

        // correct wrong path (root-node problem)
        $path = str_replace('//', '/', $path);

        if (str_contains($path, '%')) {
            $path = rawurldecode($path);
        }

        return $path;
    }

    /**
     * @internal
     *
     * @param ElementInterface $element
     *
     * @return ElementInterface
     */
    public static function loadAllFields(ElementInterface $element): ElementInterface
    {
        if ($element instanceof Document) {
            Document\Service::loadAllDocumentFields($element);
        } elseif ($element instanceof DataObject\Concrete) {
            DataObject\Service::loadAllObjectFields($element);
        } elseif ($element instanceof Asset) {
            Asset\Service::loadAllFields($element);
        }

        return $element;
    }

    /**
     * Callback for array_filter function.
     *
     * @param string $var value
     *
     * @return bool true if value is accepted
     */
    private static function filterNullValues(string $var): bool
    {
        return strlen($var) > 0;
    }

    /**
     * @param string $path
     * @param array $options
     *
     * @return Asset\Folder|DataObject\Folder|Document\Folder|null
     *
     * @throws \Exception
     */
    public static function createFolderByPath(string $path, array $options = []): Asset\Folder|DataObject\Folder|Document\Folder|null
    {
        $calledClass = static::class;
        if ($calledClass === __CLASS__) {
            throw new \Exception('This method must be called from a extended class. e.g Asset\\Service, DataObject\\Service, Document\\Service');
        }

        $type = str_replace('\Service', '', $calledClass);
        $type = '\\' . ltrim($type, '\\');
        $folderType = $type . '\Folder';

        $lastFolder = null;
        $pathsArray = [];
        $parts = explode('/', $path);
        $parts = array_filter($parts, '\\Pimcore\\Model\\Element\\Service::filterNullValues');

        $sanitizedPath = '/';

        $itemType = self::getElementType(new $type);

        foreach ($parts as $part) {
            $sanitizedPath = $sanitizedPath . self::getValidKey($part, $itemType) . '/';
        }

        if (self::pathExists($sanitizedPath, $itemType)) {
            return $type::getByPath($sanitizedPath);
        }

        foreach ($parts as $part) {
            $pathPart = $pathsArray[count($pathsArray) - 1] ?? '';
            $pathsArray[] = $pathPart . '/' . self::getValidKey($part, $itemType);
        }

        for ($i = 0; $i < count($pathsArray); $i++) {
            $currentPath = $pathsArray[$i];
            if (!self::pathExists($currentPath, $itemType)) {
                $parentFolderPath = ($i == 0) ? '/' : $pathsArray[$i - 1];

                $parentFolder = $type::getByPath($parentFolderPath);

                $folder = new $folderType();
                $folder->setParent($parentFolder);
                if ($parentFolder) {
                    $folder->setParentId($parentFolder->getId());
                } else {
                    $folder->setParentId(1);
                }

                $key = substr($currentPath, strrpos($currentPath, '/') + 1, strlen($currentPath));

                if (method_exists($folder, 'setKey')) {
                    $folder->setKey($key);
                }

                if (method_exists($folder, 'setFilename')) {
                    $folder->setFilename($key);
                }

                if (method_exists($folder, 'setType')) {
                    $folder->setType('folder');
                }

                $folder->setPath($currentPath);
                $folder->setUserModification(0);
                $folder->setUserOwner(1);
                $folder->setCreationDate(time());
                $folder->setModificationDate(time());
                $folder->setValues($options);
                $folder->save();
                $lastFolder = $folder;
            }
        }

        return $lastFolder;
    }

    /**
     * Changes the query according to the custom view config
     *
     * @param array $cv
     * @param Model\Asset\Listing|Model\DataObject\Listing|Model\Document\Listing $childrenList
     *
     * @internal
     *
     */
    public static function addTreeFilterJoins(array $cv, Asset\Listing|DataObject\Listing|Document\Listing $childrenList)
    {
        if ($cv) {
            $childrenList->onCreateQueryBuilder(static function (DoctrineQueryBuilder $select) use ($cv) {
                $where = $cv['where'] ?? null;
                if ($where) {
                    $select->andWhere($where);
                }

                $fromAlias = $select->getQueryPart('from')[0]['alias'] ?? $select->getQueryPart('from')[0]['table'] ;

                $customViewJoins = $cv['joins'] ?? null;
                if ($customViewJoins) {
                    foreach ($customViewJoins as $joinConfig) {
                        $type = $joinConfig['type'];
                        $method = $type == 'left' || $type == 'right' ? $method = $type . 'Join' : 'join';

                        $joinAlias = array_keys($joinConfig['name']);
                        $joinAlias = reset($joinAlias);
                        $joinTable = $joinConfig['name'][$joinAlias];

                        $condition = $joinConfig['condition'];
                        $columns = $joinConfig['columns'];
                        $select->addSelect($columns);
                        $select->$method($fromAlias, $joinTable, $joinAlias, $condition);
                    }
                }

                if (!empty($cv['having'])) {
                    $select->having($cv['having']);
                }
            });
        }
    }

    /**
     * @param string $id
     *
     * @return array|null
     *
     *@internal
     *
     */
    public static function getCustomViewById(string $id): ?array
    {
        $customViews = \Pimcore\CustomView\Config::get();
        if ($customViews) {
            foreach ($customViews as $customView) {
                if ($customView['id'] == $id) {
                    return $customView;
                }
            }
        }

        return null;
    }

    public static function getValidKey(string $key, string $type): string
    {
        $event = new GenericEvent(null, [
            'key' => $key,
            'type' => $type,
        ]);
        \Pimcore::getEventDispatcher()->dispatch($event, SystemEvents::SERVICE_PRE_GET_VALID_KEY);
        $key = $event->getArgument('key');
        $key = trim($key);

        // replace all control/unassigned and invalid characters
        $key = preg_replace('/[^\PCc^\PCn^\PCs]/u', '', $key);
        // replace all 4 byte unicode characters
        $key = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '-', $key);
        // replace left to right marker characters ( lrm )
        $key = preg_replace('/(\x{200e}|\x{200f})/u', '-', $key);
        // replace slashes with a hyphen
        $key = str_replace('/', '-', $key);

        // replace some other special characters
        $key = preg_replace('/[\t\n\r\f\v]/', '', $key);

        if ($type === 'object') {
            $key = preg_replace('/[<>]/', '-', $key);
        } elseif ($type === 'document') {
            // replace URL reserved characters with a hyphen
            $key = preg_replace('/[#\?\*\:\\\\<\>\|"%&@=;\+]/', '-', $key);
        } elseif ($type === 'asset') {
            // keys shouldn't start with a "." (=hidden file) *nix operating systems
            // keys shouldn't end with a "." - Windows issue: filesystem API trims automatically . at the end of a folder name (no warning ... et al)
            $key = trim($key, '. ');

            // windows forbidden filenames + URL reserved characters (at least the ones which are problematic)
            $key = preg_replace('/[#\?\*\:\\\\<\>\|"%\+]/', '-', $key);
        } else {
            $key = ltrim($key, '. ');
        }

        $key = mb_substr($key, 0, 255);

        return $key;
    }

    public static function isValidKey(string $key, string $type): bool
    {
        return self::getValidKey($key, $type) == $key;
    }

    public static function isValidPath(string $path, string $type): bool
    {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (!self::isValidKey($part, $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * returns a unique key for an element
     *
     * @param ElementInterface $element
     * @param int $nr
     *
     * @return string|null
     *
     * @throws \Exception
     */
    public static function getUniqueKey(ElementInterface $element, int $nr = 0): ?string
    {
        if ($element instanceof DataObject\AbstractObject) {
            return DataObject\Service::getUniqueKey($element);
        }

        if ($element instanceof Document) {
            return Document\Service::getUniqueKey($element);
        }

        if ($element instanceof Asset) {
            return Asset\Service::getUniqueKey($element);
        }

        return null;
    }

    /**
     * @param array $data
     * @param string $type
     *
     * @return array
     *
     *@internal
     *
     */
    public static function fixAllowedTypes(array $data, string $type): array
    {
        // this is the new method with Ext.form.MultiSelect
        if (is_array($data) && count($data)) {
            $first = reset($data);
            if (!is_array($first)) {
                $parts = $data;
                $data = [];
                foreach ($parts as $elementType) {
                    $data[] = [$type => $elementType];
                }
            } else {
                $newList = [];
                foreach ($data as $key => $item) {
                    if ($item) {
                        if (is_array($item)) {
                            foreach ($item as $itemKey => $itemValue) {
                                if ($itemValue) {
                                    $newList[$key][$itemKey] = $itemValue;
                                }
                            }
                        } else {
                            $newList[$key] = $item;
                        }
                    }
                }

                $data = $newList;
            }
        }

        return $data ? $data : [];
    }

    /**
     * @param Model\Version[] $versions
     *
     * @return array
     *
     *@internal
     *
     */
    public static function getSafeVersionInfo(array $versions): array
    {
        $indexMap = [];
        $result = [];

        if (is_array($versions)) {
            foreach ($versions as $versionObj) {
                $version = [
                    'id' => $versionObj->getId(),
                    'cid' => $versionObj->getCid(),
                    'ctype' => $versionObj->getCtype(),
                    'note' => $versionObj->getNote(),
                    'date' => $versionObj->getDate(),
                    'public' => $versionObj->getPublic(),
                    'versionCount' => $versionObj->getVersionCount(),
                    'autoSave' => $versionObj->isAutoSave(),
                ];

                $version['user'] = ['name' => '', 'id' => ''];
                if ($user = $versionObj->getUser()) {
                    $version['user'] = [
                        'name' => $user->getName(),
                        'id' => $user->getId(),
                    ];
                }

                $versionKey = $versionObj->getDate() . '-' . $versionObj->getVersionCount();
                if (!isset($indexMap[$versionKey])) {
                    $indexMap[$versionKey] = 0;
                }
                $version['index'] = $indexMap[$versionKey];
                $indexMap[$versionKey] = $indexMap[$versionKey] + 1;

                $result[] = $version;
            }
        }

        return $result;
    }

    public static function cloneMe(ElementInterface $element): ElementInterface
    {
        $deepCopy = new \DeepCopy\DeepCopy();
        $deepCopy->addFilter(new \DeepCopy\Filter\KeepFilter(), new class() implements \DeepCopy\Matcher\Matcher {
            /**
             * {@inheritdoc}
             */
            public function matches($object, $property): bool
            {
                try {
                    $reflectionProperty = new \ReflectionProperty($object, $property);
                    $reflectionProperty->setAccessible(true);
                    $myValue = $reflectionProperty->getValue($object);
                } catch (\Throwable $e) {
                    return false;
                }

                return $myValue instanceof ElementInterface;
            }
        });

        if ($element instanceof Concrete) {
            $deepCopy->addFilter(
                new PimcoreClassDefinitionReplaceFilter(
                    function (Concrete $object, Data $fieldDefinition, $property, $currentValue) {
                        if ($fieldDefinition instanceof Data\CustomDataCopyInterface) {
                            return $fieldDefinition->createDataCopy($object, $currentValue);
                        }

                        return $currentValue;
                    }
                ),
                new PimcoreClassDefinitionMatcher(Data\CustomDataCopyInterface::class)
            );
        }

        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('dao'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('resource'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('writeResource'));
        $deepCopy->addFilter(new \DeepCopy\Filter\Doctrine\DoctrineCollectionFilter(), new \DeepCopy\Matcher\PropertyTypeMatcher(
            Collection::class
        ));

        if ($element instanceof DataObject\Concrete) {
            DataObject\Service::loadAllObjectFields($element);
        }

        $theCopy = $deepCopy->copy($element);
        $theCopy->setId(null);
        $theCopy->setParent(null);

        return $theCopy;
    }

    /**
     * @template T
     *
     * @param T $properties
     *
     * @return T
     */
    public static function cloneProperties(mixed $properties): mixed
    {
        $deepCopy = new \DeepCopy\DeepCopy();
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('cid'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('ctype'));
        $deepCopy->addFilter(new SetNullFilter(), new PropertyNameMatcher('cpath'));

        return $deepCopy->copy($properties);
    }

    /**
     * @internal
     *
     * @param Note $note
     *
     * @return array
     */
    public static function getNoteData(Note $note): array
    {
        $cpath = '';
        if ($note->getCid() && $note->getCtype()) {
            if ($element = Service::getElementById($note->getCtype(), $note->getCid())) {
                $cpath = $element->getRealFullPath();
            }
        }

        $e = [
            'id' => $note->getId(),
            'type' => $note->getType(),
            'cid' => $note->getCid(),
            'ctype' => $note->getCtype(),
            'cpath' => $cpath,
            'date' => $note->getDate(),
            'title' => Pimcore::getContainer()->get(TranslatorInterface::class)->trans($note->getTitle(), [], 'admin'),
            'description' => $note->getDescription(),
            'locked' => $note->getLocked(),
        ];

        // prepare key-values
        $keyValues = [];
        if (is_array($note->getData())) {
            foreach ($note->getData() as $name => $d) {
                $type = $d['type'];
                $data = $d['data'];

                if ($type == 'document' || $type == 'object' || $type == 'asset') {
                    if ($d['data'] instanceof ElementInterface) {
                        $data = [
                            'id' => $d['data']->getId(),
                            'path' => $d['data']->getRealFullPath(),
                            'type' => $d['data']->getType(),
                        ];
                    }
                } elseif ($type == 'date') {
                    if (is_object($d['data'])) {
                        $data = $d['data']->getTimestamp();
                    }
                }

                $keyValue = [
                    'type' => $type,
                    'name' => $name,
                    'data' => $data,
                ];

                $keyValues[] = $keyValue;
            }
        }

        $e['data'] = $keyValues;

        // prepare user data
        if ($note->getUser()) {
            $user = Model\User::getById($note->getUser());
            if ($user) {
                $e['user'] = [
                    'id' => $user->getId(),
                    'name' => $user->getName(),
                ];
            } else {
                $e['user'] = '';
            }
        }

        return $e;
    }

    /**
     * @param string $type
     * @param int $elementId
     * @param string|null $postfix
     *
     * @return string
     *
     *@internal
     *
     */
    public static function getSessionKey(string $type, int $elementId, ?string $postfix = ''): string
    {
        $sessionId = Session::getSessionId();
        $tmpStoreKey = $type . '_session_' . $elementId . '_' . $sessionId . $postfix;

        return $tmpStoreKey;
    }

    public static function getElementFromSession(string $type, int $elementId, ?string $postfix = ''): Asset|Document|AbstractObject|null
    {
        $element = null;
        $tmpStoreKey = self::getSessionKey($type, $elementId, $postfix);

        $tmpStore = TmpStore::get($tmpStoreKey);
        if ($tmpStore) {
            $data = $tmpStore->getData();
            if ($data) {
                $element = Serialize::unserialize($data);

                $context = [
                    'source' => __METHOD__,
                    'conversion' => 'unmarshal',
                ];

                $copier = Self::getDeepCopyInstance($element, $context);

                if ($element instanceof Concrete) {
                    $copier->addFilter(
                        new PimcoreClassDefinitionReplaceFilter(
                            function (Concrete $object, Data $fieldDefinition, $property, $currentValue) {
                                if ($fieldDefinition instanceof Data\CustomVersionMarshalInterface) {
                                    return $fieldDefinition->unmarshalVersion($object, $currentValue);
                                }

                                return $currentValue;
                            }
                        ),
                        new PimcoreClassDefinitionMatcher(Data\CustomVersionMarshalInterface::class)
                    );
                }

                return $copier->copy($element);
            }
        }

        return $element;
    }

    /**
     * @param ElementInterface $element
     * @param string $postfix
     * @param bool $clone save a copy
     *
     *@internal
     *
     */
    public static function saveElementToSession(ElementInterface $element, string $postfix = '', bool $clone = true)
    {
        if ($clone) {
            $context = [
                'source' => __METHOD__,
                'conversion' => 'marshal',
            ];
            $copier = self::getDeepCopyInstance($element, $context);

            if ($element instanceof Concrete) {
                $copier->addFilter(
                    new PimcoreClassDefinitionReplaceFilter(
                        function (Concrete $object, Data $fieldDefinition, $property, $currentValue) {
                            if ($fieldDefinition instanceof Data\CustomVersionMarshalInterface) {
                                return $fieldDefinition->marshalVersion($object, $currentValue);
                            }

                            return $currentValue;
                        }
                    ),
                    new PimcoreClassDefinitionMatcher(Data\CustomVersionMarshalInterface::class)
                );
            }

            $element = $copier->copy($element);
        }

        $elementType = Service::getElementType($element);
        $tmpStoreKey = self::getSessionKey($elementType, $element->getId(), $postfix);
        $tag = $elementType . '-session' . $postfix;

        self::loadAllFields($element);
        $element->setInDumpState(true);
        $serializedData = Serialize::serialize($element);

        TmpStore::set($tmpStoreKey, $serializedData, $tag);
    }

    /**
     * @param string $type
     * @param int $elementId
     * @param string $postfix
     *
     *@internal
     *
     */
    public static function removeElementFromSession(string $type, int $elementId, string $postfix = '')
    {
        $tmpStoreKey = self::getSessionKey($type, $elementId, $postfix);
        TmpStore::delete($tmpStoreKey);
    }

    /**
     * @internal
     *
     * @param mixed $element
     * @param array|null $context
     *
     * @return DeepCopy
     */
    public static function getDeepCopyInstance(mixed $element, ?array $context = []): DeepCopy
    {
        $copier = new DeepCopy();
        $copier->skipUncloneable(true);

        if ($element instanceof ElementInterface) {
            if (($context['conversion'] ?? false) === 'marshal') {
                $sourceType = Service::getElementType($element);
                $sourceId = $element->getId();

                $copier->addTypeFilter(
                    new \DeepCopy\TypeFilter\ReplaceFilter(
                        function ($currentValue) {
                            if ($currentValue instanceof ElementInterface) {
                                $elementType = Service::getElementType($currentValue);
                                $descriptor = new ElementDescriptor($elementType, $currentValue->getId());

                                return $descriptor;
                            }

                            return $currentValue;
                        }
                    ),
                    new MarshalMatcher($sourceType, $sourceId)
                );
            } elseif (($context['conversion'] ?? false) === 'unmarshal') {
                $copier->addTypeFilter(
                    new \DeepCopy\TypeFilter\ReplaceFilter(
                        function ($currentValue) {
                            if ($currentValue instanceof ElementDescriptor) {
                                $value = Service::getElementById($currentValue->getType(), $currentValue->getId());

                                return $value;
                            }

                            return $currentValue;
                        }
                    ),
                    new UnmarshalMatcher()
                );
            }
        }

        if ($context['defaultFilters'] ?? false) {
            $copier->addFilter(new DoctrineCollectionFilter(), new PropertyTypeMatcher('Doctrine\Common\Collections\Collection'));
            $copier->addFilter(new SetNullFilter(), new PropertyTypeMatcher('Psr\Container\ContainerInterface'));
            $copier->addFilter(new SetNullFilter(), new PropertyTypeMatcher('Pimcore\Model\DataObject\ClassDefinition'));
        }

        $event = new GenericEvent(null, [
            'copier' => $copier,
            'element' => $element,
            'context' => $context,
        ]);

        \Pimcore::getEventDispatcher()->dispatch($event, SystemEvents::SERVICE_PRE_GET_DEEP_COPY);

        return $event->getArgument('copier');
    }

    /**
     * @internal
     *
     * @param array $rowData
     *
     * @return array
     */
    public static function escapeCsvRecord(array $rowData): array
    {
        if (self::$formatter === null) {
            self::$formatter = new EscapeFormula("'", ['=', '-', '+', '@']);
        }
        $rowData = self::$formatter->escapeRecord($rowData);

        return $rowData;
    }

    /**
     * @param string $type
     * @param int|string|null $id
     *
     * @return string
     *
     *@internal
     *
     */
    public static function getElementCacheTag(string $type, int|string|null $id): string
    {
        if (isset($id)) {
            return $type . '_' . $id;
        } else {
            return $type . '_';
        }
    }
}
