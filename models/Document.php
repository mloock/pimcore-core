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

namespace Pimcore\Model;

use Doctrine\DBAL\Exception\DeadlockException;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Event\DocumentEvents;
use Pimcore\Event\FrontendEvents;
use Pimcore\Event\Model\DocumentEvent;
use Pimcore\Logger;
use Pimcore\Model\Document\Hardlink\Wrapper\WrapperInterface;
use Pimcore\Model\Document\Listing;
use Pimcore\Model\Element\DuplicateFullPathException;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Exception\NotFoundException;
use Pimcore\Tool;
use Pimcore\Tool\Frontend as FrontendTool;
use Symfony\Cmf\Bundle\RoutingBundle\Routing\DynamicRouter;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * @method \Pimcore\Model\Document\Dao getDao()
 * @method bool __isBasedOnLatestData()
 * @method int getChildAmount($user = null)
 * @method string getCurrentFullPath()
 */
class Document extends Element\AbstractElement
{
    private static bool $hideUnpublished = false;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $fullPathCache = null;

    /**
     * @internal
     */
    protected string $type = '';

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $key = null;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $path = null;

    /**
     * @internal
     */
    protected ?int $index = null;

    /**
     * @internal
     */
    protected bool $published = true;

    /**
     * @internal
     */
    protected ?int $userModification = null;

    /**
     * @internal
     *
     * @var array
     */
    protected array $children = [];

    /**
     * @internal
     *
     * @var bool[]
     */
    protected array $hasChildren = [];

    /**
     * @internal
     *
     * @var array
     */
    protected array $siblings = [];

    /**
     * @internal
     *
     * @var bool[]
     */
    protected array $hasSiblings = [];

    /**
     * {@inheritdoc}
     */
    protected function getBlockedVars(): array
    {
        $blockedVars = ['hasChildren', 'versions', 'scheduledTasks', 'parent', 'fullPathCache'];

        if (!$this->isInDumpState()) {
            // this is if we want to cache the object
            $blockedVars = array_merge($blockedVars, ['children', 'properties']);
        }

        return $blockedVars;
    }

    /**
     * get possible types
     *
     * @return array
     */
    public static function getTypes(): array
    {
        $documentsConfig = \Pimcore\Config::getSystemConfiguration('documents');

        return $documentsConfig['types'];
    }

    /**
     * @internal
     *
     * @param string $path
     *
     * @return string
     */
    protected static function getPathCacheKey(string $path): string
    {
        return 'document_path_' . md5($path);
    }

    public static function getByPath(string $path, array $params = []): static|null
    {
        if (!$path) {
            return null;
        }

        $path = Element\Service::correctPath($path);

        $cacheKey = self::getPathCacheKey($path);
        $params = Element\Service::prepareGetByIdParams($params);

        if (!$params['force'] && RuntimeCache::isRegistered($cacheKey)) {
            $document = RuntimeCache::get($cacheKey);
            if ($document && static::typeMatch($document)) {
                return $document;
            }
        }

        try {
            $helperDoc = new Document();
            $helperDoc->getDao()->getByPath($path);
            $doc = static::getById($helperDoc->getId(), $params);
            RuntimeCache::set($cacheKey, $doc);
        } catch (NotFoundException $e) {
            $doc = null;
        }

        return $doc;
    }

    /**
     * @internal
     *
     * @param Document $document
     *
     * @return bool
     */
    protected static function typeMatch(Document $document): bool
    {
        $staticType = static::class;
        if ($staticType !== Document::class) {
            if (!$document instanceof $staticType) {
                return false;
            }
        }

        return true;
    }

    public static function getById(int|string $id, array $params = []): ?static
    {
        if (!is_numeric($id) || $id < 1) {
            return null;
        }

        $id = (int)$id;
        $cacheKey = self::getCacheKey($id);
        $params = Element\Service::prepareGetByIdParams($params);

        if (!$params['force'] && RuntimeCache::isRegistered($cacheKey)) {
            $document = RuntimeCache::get($cacheKey);
            if ($document && static::typeMatch($document)) {
                return $document;
            }
        }

        if ($params['force'] || !($document = \Pimcore\Cache::load($cacheKey))) {
            $reflectionClass = new \ReflectionClass(static::class);
            if ($reflectionClass->isAbstract()) {
                $document = new Document();
            } else {
                $document = new static();
            }

            try {
                $document->getDao()->getById($id);
            } catch (NotFoundException $e) {
                return null;
            }

            $className = 'Pimcore\\Model\\Document\\' . ucfirst($document->getType());

            // this is the fallback for custom document types using prefixes
            // so we need to check if the class exists first
            if (!Tool::classExists($className)) {
                $oldStyleClass = 'Document_' . ucfirst($document->getType());
                if (Tool::classExists($oldStyleClass)) {
                    $className = $oldStyleClass;
                }
            }
            /** @var Document $newDocument */
            $newDocument = self::getModelFactory()->build($className);

            if (get_class($document) !== get_class($newDocument)) {
                $document = $newDocument;
                $document->getDao()->getById($id);
            }

            RuntimeCache::set($cacheKey, $document);
            if ($document->getModificationDate() !== null) {
                $document->__setDataVersionTimestamp($document->getModificationDate());
            }

            $document->resetDirtyMap();

            \Pimcore\Cache::save($document, $cacheKey);
        } else {
            RuntimeCache::set($cacheKey, $document);
        }

        if (!$document || !static::typeMatch($document)) {
            return null;
        }

        \Pimcore::getEventDispatcher()->dispatch(
            new DocumentEvent($document, ['params' => $params]),
            DocumentEvents::POST_LOAD
        );

        return $document;
    }

    public static function create(int $parentId, array $data = [], bool $save = true): static
    {
        $document = new static();
        $document->setParentId($parentId);
        self::checkCreateData($data);
        $document->setValues($data);

        if ($save) {
            $document->save();
        }

        return $document;
    }

    /**
     * @param array $config
     *
     * @return Listing
     *
     * @throws \Exception
     */
    public static function getList(array $config = []): Listing
    {
        /** @var Listing $list */
        $list = self::getModelFactory()->build(Listing::class);
        $list->setValues($config);

        return $list;
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $parameters = []): static
    {
        $isUpdate = false;

        try {
            $preEvent = new DocumentEvent($this, $parameters);
            if ($this->getId()) {
                $isUpdate = true;
                $this->dispatchEvent($preEvent, DocumentEvents::PRE_UPDATE);
            } else {
                $this->dispatchEvent($preEvent, DocumentEvents::PRE_ADD);
            }

            $parameters = $preEvent->getArguments();

            $this->correctPath();
            $differentOldPath = null;

            // we wrap the save actions in a loop here, so that we can restart the database transactions in the case it fails
            // if a transaction fails it gets restarted $maxRetries times, then the exception is thrown out
            // this is especially useful to avoid problems with deadlocks in multi-threaded environments (forked workers, ...)
            $maxRetries = 5;
            for ($retries = 0; $retries < $maxRetries; $retries++) {
                $this->beginTransaction();

                try {
                    $this->updateModificationInfos();

                    if (!$isUpdate) {
                        $this->getDao()->create();
                    }

                    // get the old path from the database before the update is done
                    $oldPath = null;
                    if ($isUpdate) {
                        $oldPath = $this->getDao()->getCurrentFullPath();
                    }

                    $this->update($parameters);

                    // if the old path is different from the new path, update all children
                    $updatedChildren = [];
                    if ($oldPath && $oldPath !== $newPath = $this->getRealFullPath()) {
                        $differentOldPath = $oldPath;
                        $this->getDao()->updateWorkspaces();
                        $updatedChildren = array_map(
                            static function (array $doc) use ($oldPath, $newPath): array {
                                $doc['oldPath'] = substr_replace($doc['path'], $oldPath, 0, strlen($newPath));

                                return $doc;
                            },
                            $this->getDao()->updateChildPaths($oldPath),
                        );
                    }

                    $this->commit();

                    break; // transaction was successfully completed, so we cancel the loop here -> no restart required
                } catch (\Exception $e) {
                    try {
                        $this->rollBack();
                    } catch (\Exception $er) {
                        // PDO adapter throws exceptions if rollback fails
                        Logger::error((string) $er);
                    }

                    // we try to start the transaction $maxRetries times again (deadlocks, ...)
                    if ($e instanceof DeadlockException && $retries < ($maxRetries - 1)) {
                        $run = $retries + 1;
                        $waitTime = rand(1, 5) * 100000; // microseconds
                        Logger::warn('Unable to finish transaction (' . $run . ". run) because of the following reason '" . $e->getMessage() . "'. --> Retrying in " . $waitTime . ' microseconds ... (' . ($run + 1) . ' of ' . $maxRetries . ')');

                        usleep($waitTime); // wait specified time until we restart the transaction
                    } else {
                        // if the transaction still fail after $maxRetries retries, we throw out the exception
                        throw $e;
                    }
                }
            }

            $additionalTags = [];
            if (isset($updatedChildren) && is_array($updatedChildren)) {
                foreach ($updatedChildren as $updatedDocument) {
                    $tag = self::getCacheKey($updatedDocument['id']);
                    $additionalTags[] = $tag;

                    // remove the child also from registry (internal cache) to avoid path inconsistencies during long-running scripts, such as CLI
                    RuntimeCache::set($tag, null);
                    RuntimeCache::set(self::getPathCacheKey($updatedDocument['oldPath']), null);
                }
            }
            $this->clearDependentCache($additionalTags);

            $postEvent = new DocumentEvent($this, $parameters);
            if ($isUpdate) {
                if ($differentOldPath) {
                    $postEvent->setArgument('oldPath', $differentOldPath);
                }
                $this->dispatchEvent($postEvent, DocumentEvents::POST_UPDATE);
            } else {
                $this->dispatchEvent($postEvent, DocumentEvents::POST_ADD);
            }

            return $this;
        } catch (\Exception $e) {
            $failureEvent = new DocumentEvent($this, $parameters);
            $failureEvent->setArgument('exception', $e);
            if ($isUpdate) {
                $this->dispatchEvent($failureEvent, DocumentEvents::POST_UPDATE_FAILURE);
            } else {
                $this->dispatchEvent($failureEvent, DocumentEvents::POST_ADD_FAILURE);
            }

            throw $e;
        }
    }

    /**
     * @throws \Exception|DuplicateFullPathException
     */
    private function correctPath(): void
    {
        // set path
        if ($this->getId() != 1) { // not for the root node
            // check for a valid key, home has no key, so omit the check
            if (!Element\Service::isValidKey($this->getKey(), 'document')) {
                throw new \Exception('invalid key for document with id [ ' . $this->getId() . ' ] key is: [' . $this->getKey() . ']');
            }

            if (!$this->getParentId()) {
                throw new \Exception('ParentID is mandatory and can´t be null. If you want to add the element as a child to the tree´s root node, consider setting ParentID to 1.');
            }

            if ($this->getParentId() == $this->getId()) {
                throw new \Exception("ParentID and ID are identical, an element can't be the parent of itself in the tree.");
            }

            $parent = Document::getById($this->getParentId());
            if (!$parent) {
                throw new \Exception('ParentID not found.');
            }

            // use the parent's path from the database here (getCurrentFullPath), to ensure the path really exists and does not rely on the path
            // that is currently in the parent object (in memory), because this might have changed but wasn't not saved
            $this->setPath(str_replace('//', '/', $parent->getCurrentFullPath() . '/'));

            if (strlen($this->getKey()) < 1) {
                throw new \Exception('Document requires key, generated key automatically');
            }
        } elseif ($this->getId() == 1) {
            // some data in root node should always be the same
            $this->setParentId(0);
            $this->setPath('/');
            $this->setKey('');
            $this->setType('page');
        }

        if (Document\Service::pathExists($this->getRealFullPath())) {
            $duplicate = Document::getByPath($this->getRealFullPath());
            if ($duplicate instanceof Document && $duplicate->getId() != $this->getId()) {
                $duplicateFullPathException = new DuplicateFullPathException('Duplicate full path [ ' . $this->getRealFullPath() . ' ] - cannot save document');
                $duplicateFullPathException->setDuplicateElement($duplicate);
                $duplicateFullPathException->setCauseElement($this);

                throw $duplicateFullPathException;
            }
        }

        $this->validatePathLength();
    }

    /**
     * @param array $params additional parameters (e.g. "versionNote" for the version note)
     *
     * @throws \Exception
     *
     *@internal
     *
     */
    protected function update(array $params = [])
    {
        $disallowedKeysInFirstLevel = ['install', 'admin', 'plugin'];
        if ($this->getParentId() == 1 && in_array($this->getKey(), $disallowedKeysInFirstLevel)) {
            throw new \Exception('Key: ' . $this->getKey() . ' is not allowed in first level (root-level)');
        }

        // set index if null
        if ($this->getIndex() === null) {
            $this->setIndex($this->getDao()->getNextIndex());
        }

        // save properties
        $this->getProperties();
        $this->getDao()->deleteAllProperties();
        if (is_array($this->getProperties()) && count($this->getProperties()) > 0) {
            foreach ($this->getProperties() as $property) {
                if (!$property->getInherited()) {
                    $property->setDao(null);
                    $property->setCid($this->getId());
                    $property->setCtype('document');
                    $property->setCpath($this->getRealFullPath());
                    $property->save();
                }
            }
        }

        // save dependencies
        $d = new Dependency();
        $d->setSourceType('document');
        $d->setSourceId($this->getId());

        foreach ($this->resolveDependencies() as $requirement) {
            if ($requirement['id'] == $this->getId() && $requirement['type'] == 'document') {
                // dont't add a reference to yourself
                continue;
            } else {
                $d->addRequirement((int) $requirement['id'], $requirement['type']);
            }
        }
        $d->save();

        $this->getDao()->update();

        //set document to registry
        RuntimeCache::set(self::getCacheKey($this->getId()), $this);
    }

    /**
     * @param int $index
     *
     *@internal
     *
     */
    public function saveIndex(int $index)
    {
        $this->getDao()->saveIndex($index);
        $this->clearDependentCache();
    }

    public function clearDependentCache(array $additionalTags = [])
    {
        try {
            $tags = [$this->getCacheTag(), 'document_properties', 'output'];
            $tags = array_merge($tags, $additionalTags);

            \Pimcore\Cache::clearTags($tags);
        } catch (\Exception $e) {
            Logger::crit((string) $e);
        }
    }

    /**
     * set the children of the document
     *
     * @param Document[]|null $children
     * @param bool $includingUnpublished
     *
     * @return $this
     */
    public function setChildren(?array $children, bool $includingUnpublished = false): static
    {
        if ($children === null) {
            // unset all cached children
            $this->hasChildren = [];
            $this->children = [];
        } elseif (is_array($children)) {
            $cacheKey = $this->getListingCacheKey([$includingUnpublished]);
            $this->children[$cacheKey] = $children;
            $this->hasChildren[$cacheKey] = (bool) count($children);
        }

        return $this;
    }

    /**
     * Get a list of the children (not recursivly)
     *
     * @param bool $includingUnpublished
     *
     * @return self[]
     */
    public function getChildren(bool $includingUnpublished = false): array
    {
        $cacheKey = $this->getListingCacheKey(func_get_args());

        if (!isset($this->children[$cacheKey])) {
            if ($this->getId()) {
                $list = new Document\Listing();
                $list->setUnpublished($includingUnpublished);
                $list->setCondition('parentId = ?', $this->getId());
                $list->setOrderKey('index');
                $list->setOrder('asc');
                $this->children[$cacheKey] = $list->load();
            } else {
                $this->children[$cacheKey] = [];
            }
        }

        return $this->children[$cacheKey];
    }

    /**
     * Returns true if the document has at least one child
     *
     * @param bool $includingUnpublished
     *
     * @return bool
     */
    public function hasChildren(bool $includingUnpublished = false): bool
    {
        $cacheKey = $this->getListingCacheKey(func_get_args());

        if (isset($this->hasChildren[$cacheKey])) {
            return $this->hasChildren[$cacheKey];
        }

        return $this->hasChildren[$cacheKey] = $this->getDao()->hasChildren($includingUnpublished);
    }

    /**
     * Get a list of the sibling documents
     *
     * @param bool $includingUnpublished
     *
     * @return array
     */
    public function getSiblings(bool $includingUnpublished = false): array
    {
        $cacheKey = $this->getListingCacheKey(func_get_args());

        if (!isset($this->siblings[$cacheKey])) {
            if ($this->getParentId()) {
                $list = new Document\Listing();
                $list->setUnpublished($includingUnpublished);
                $list->addConditionParam('parentId = ?', $this->getParentId());
                if ($this->getId()) {
                    $list->addConditionParam('id != ?', $this->getId());
                }
                $list->setOrderKey('index');
                $list->setOrder('asc');
                $this->siblings[$cacheKey] = $list->load();
                $this->hasSiblings[$cacheKey] = (bool) count($this->siblings[$cacheKey]);
            } else {
                $this->siblings[$cacheKey] = [];
                $this->hasSiblings[$cacheKey] = false;
            }
        }

        return $this->siblings[$cacheKey];
    }

    /**
     * Returns true if the document has at least one sibling
     *
     * @param bool|null $includingUnpublished
     *
     * @return bool
     */
    public function hasSiblings(bool $includingUnpublished = null): bool
    {
        $cacheKey = $this->getListingCacheKey(func_get_args());

        if (isset($this->hasSiblings[$cacheKey])) {
            return $this->hasSiblings[$cacheKey];
        }

        return $this->hasSiblings[$cacheKey] = $this->getDao()->hasSiblings($includingUnpublished);
    }

    /**
     * @internal
     *
     * @throws \Exception
     */
    protected function doDelete()
    {
        // remove children
        if ($this->hasChildren()) {
            // delete also unpublished children
            $unpublishedStatus = self::doHideUnpublished();
            self::setHideUnpublished(false);
            foreach ($this->getChildren(true) as $child) {
                if (!$child instanceof WrapperInterface) {
                    $child->delete();
                }
            }
            self::setHideUnpublished($unpublishedStatus);
        }

        // remove all properties
        $this->getDao()->deleteAllProperties();

        // remove dependencies
        $d = $this->getDependencies();
        $d->cleanAllForElement($this);

        // remove translations
        $service = new Document\Service;
        $service->removeTranslation($this);
    }

    public function delete()
    {
        $this->dispatchEvent(new DocumentEvent($this), DocumentEvents::PRE_DELETE);

        $this->beginTransaction();

        try {
            if ($this->getId() == 1) {
                throw new \Exception('root-node cannot be deleted');
            }

            $this->doDelete();
            $this->getDao()->delete();

            $this->commit();

            //clear parent data from registry
            $parentCacheKey = self::getCacheKey($this->getParentId());
            if (RuntimeCache::isRegistered($parentCacheKey)) {
                /** @var Document $parent */
                $parent = RuntimeCache::get($parentCacheKey);
                if ($parent instanceof self) {
                    $parent->setChildren(null);
                }
            }
        } catch (\Exception $e) {
            $this->rollBack();
            $failureEvent = new DocumentEvent($this);
            $failureEvent->setArgument('exception', $e);
            $this->dispatchEvent($failureEvent, DocumentEvents::POST_DELETE_FAILURE);
            Logger::error((string) $e);

            throw $e;
        }

        // clear cache
        $this->clearDependentCache();

        //clear document from registry
        RuntimeCache::set(self::getCacheKey($this->getId()), null);
        RuntimeCache::set(self::getPathCacheKey($this->getRealFullPath()), null);

        $this->dispatchEvent(new DocumentEvent($this), DocumentEvents::POST_DELETE);
    }

    public function getFullPath(bool $force = false): string
    {
        $link = $force ? null : $this->fullPathCache;

        // check if this document is also the site root, if so return /
        try {
            if (!$link && \Pimcore\Tool::isFrontend() && Site::isSiteRequest()) {
                $site = Site::getCurrentSite();
                if ($site instanceof Site) {
                    if ($site->getRootDocument()->getId() == $this->getId()) {
                        $link = '/';
                    }
                }
            }
        } catch (\Exception $e) {
            Logger::error((string) $e);
        }

        $requestStack = \Pimcore::getContainer()->get('request_stack');
        $mainRequest = $requestStack->getMainRequest();

        // @TODO please forgive me, this is the dirtiest hack I've ever made :(
        // if you got confused by this functionality drop me a line and I'll buy you some beers :)

        // this is for the case that a link points to a document outside of the current site
        // in this case we look for a hardlink in the current site which points to the current document
        // why this could happen: we have 2 sites, in one site there's a hardlink to the other site and on a page inside
        // the hardlink there are snippets embedded and this snippets have links pointing to a document which is also
        // inside the hardlink scope, but this is an ID link, so we cannot rewrite the link the usual way because in the
        // snippet / link we don't know anymore that whe a inside a hardlink wrapped document
        if (!$link && \Pimcore\Tool::isFrontend() && Site::isSiteRequest() && !FrontendTool::isDocumentInCurrentSite($this)) {
            if ($mainRequest && ($masterDocument = $mainRequest->get(DynamicRouter::CONTENT_KEY))) {
                if ($masterDocument instanceof WrapperInterface) {
                    $hardlinkPath = '';
                    $hardlink = $masterDocument->getHardLinkSource();
                    $hardlinkTarget = $hardlink->getSourceDocument();

                    if ($hardlinkTarget) {
                        $hardlinkPath = preg_replace('@^' . preg_quote(Site::getCurrentSite()->getRootPath(), '@') . '@', '', $hardlink->getRealFullPath());

                        $link = preg_replace('@^' . preg_quote($hardlinkTarget->getRealFullPath(), '@') . '@',
                            $hardlinkPath, $this->getRealFullPath());
                    }

                    if (strpos($this->getRealFullPath(), Site::getCurrentSite()->getRootDocument()->getRealFullPath()) === false && strpos($link, $hardlinkPath) === false) {
                        $link = null;
                    }
                }
            }

            if (!$link) {
                $config = \Pimcore\Config::getSystemConfiguration('general');
                $request = $requestStack->getCurrentRequest();
                $scheme = 'http://';
                if ($request) {
                    $scheme = $request->getScheme() . '://';
                }

                /** @var Site $site */
                if ($site = FrontendTool::getSiteForDocument($this)) {
                    if ($site->getMainDomain()) {
                        // check if current document is the root of the different site, if so, preg_replace below doesn't work, so just return /
                        if ($site->getRootDocument()->getId() == $this->getId()) {
                            $link = $scheme . $site->getMainDomain() . '/';
                        } else {
                            $link = $scheme . $site->getMainDomain() .
                                preg_replace('@^' . $site->getRootPath() . '/@', '/', $this->getRealFullPath());
                        }
                    }
                }

                if (!$link && !empty($config['domain']) && !($this instanceof WrapperInterface)) {
                    $link = $scheme . $config['domain'] . $this->getRealFullPath();
                }
            }
        }

        if (!$link) {
            $link = $this->getPath() . $this->getKey();
        }

        if ($mainRequest) {
            // caching should only be done when master request is available as it is done for performance reasons
            // of the web frontend, without a request object there's no need to cache anything
            // for details also see https://github.com/pimcore/pimcore/issues/5707
            $this->fullPathCache = $link;
        }

        $link = $this->prepareFrontendPath($link);

        return $link;
    }

    private function prepareFrontendPath(string $path): string
    {
        if (\Pimcore\Tool::isFrontend()) {
            $path = urlencode_ignore_slash($path);

            $event = new GenericEvent($this, [
                'frontendPath' => $path,
            ]);
            $this->dispatchEvent($event, FrontendEvents::DOCUMENT_PATH);
            $path = $event->getArgument('frontendPath');
        }

        return $path;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getPath(): ?string
    {
        // check for site, if so rewrite the path for output
        try {
            if (\Pimcore\Tool::isFrontend() && Site::isSiteRequest()) {
                $site = Site::getCurrentSite();
                if ($site instanceof Site) {
                    if ($site->getRootDocument() instanceof Document\Page && $site->getRootDocument() !== $this) {
                        $rootPath = $site->getRootPath();
                        $rootPath = preg_quote($rootPath, '@');
                        $link = preg_replace('@^' . $rootPath . '@', '', $this->path);

                        return $link;
                    }
                }
            }
        } catch (\Exception $e) {
            Logger::error((string) $e);
        }

        return $this->path;
    }

    public function getRealPath(): ?string
    {
        return $this->path;
    }

    public function getRealFullPath(): string
    {
        $path = $this->getRealPath() . $this->getKey();

        return $path;
    }

    public function setKey(string $key): static
    {
        $this->key = (string)$key;

        return $this;
    }

    /**
     * Set the parent id of the document.
     *
     * @param int|null $id
     *
     * @return $this
     */
    public function setParentId(?int $id): static
    {
        parent::setParentId($id);

        $this->siblings = [];
        $this->hasSiblings = [];

        return $this;
    }

    /**
     * Returns the document index.
     *
     * @return int|null
     */
    public function getIndex(): ?int
    {
        return $this->index;
    }

    /**
     * Set the document index.
     *
     * @param int $index
     *
     * @return $this
     */
    public function setIndex(int $index): static
    {
        $this->index = (int) $index;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the document type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->getPublished();
    }

    public function getPublished(): bool
    {
        return (bool) $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;

        return $this;
    }

    public function getParent(): ?Document
    {
        $parent = parent::getParent();

        return $parent instanceof Document ? $parent : null;
    }

    /**
     * Set the parent document instance.
     *
     * @param ElementInterface|null $parent
     *
     * @return $this
     */
    public function setParent(?ElementInterface $parent): static
    {
        /** @var Document $parent */
        $this->parent = $parent;
        if ($parent instanceof Document) {
            $this->parentId = $parent->getId();
        }

        return $this;
    }

    /**
     * Set true if want to hide documents.
     *
     * @param bool $hideUnpublished
     */
    public static function setHideUnpublished(bool $hideUnpublished)
    {
        self::$hideUnpublished = $hideUnpublished;
    }

    /**
     * Checks if unpublished documents should be hidden.
     *
     * @return bool
     */
    public static function doHideUnpublished(): bool
    {
        return self::$hideUnpublished;
    }

    /**
     * @internal
     *
     * @param array $args
     *
     * @return string
     */
    protected function getListingCacheKey(array $args = []): string
    {
        $includingUnpublished = (bool)($args[0] ?? false);

        return 'document_list_' . ($includingUnpublished ? '1' : '0');
    }

    public function __clone()
    {
        parent::__clone();
        $this->parent = null;
        $this->hasSiblings = [];
        $this->siblings = [];
        $this->fullPathCache = null;
    }
}
