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

use Pimcore\Cache\RuntimeCache;
use Pimcore\Event\Model\SiteEvent;
use Pimcore\Event\SiteEvents;
use Pimcore\Event\Traits\RecursionBlockingEventDispatchHelperTrait;
use Pimcore\Logger;
use Pimcore\Model\Exception\NotFoundException;

/**
 * @method \Pimcore\Model\Site\Dao getDao()
 */
final class Site extends AbstractModel
{
    use RecursionBlockingEventDispatchHelperTrait;

    protected static ?Site $currentSite = null;

    protected ?int $id = null;

    protected array $domains;

    /**
     * Contains the ID to the Root-Document
     *
     * @var int
     */
    protected int $rootId;

    protected ?Document\Page $rootDocument = null;

    protected ?string $rootPath = null;

    protected string $mainDomain = '';

    protected string $errorDocument = '';

    protected array $localizedErrorDocuments;

    protected bool $redirectToMainDomain = false;

    protected ?int $creationDate = null;

    protected ?int $modificationDate = null;

    /**
     * @param int $id
     *
     * @return Site|string|null
     *
     * @throws \Exception
     */
    public static function getById(int $id): Site|string|null
    {
        $cacheKey = 'site_id_'. $id;

        if (RuntimeCache::isRegistered($cacheKey)) {
            $site = RuntimeCache::get($cacheKey);
        } elseif (!$site = \Pimcore\Cache::load($cacheKey)) {
            try {
                $site = new self();
                $site->getDao()->getById((int)$id);
            } catch (NotFoundException $e) {
                $site = 'failed';
            }

            \Pimcore\Cache::save($site, $cacheKey, ['system', 'site'], null, 999);
        }

        if ($site === 'failed' || !$site) {
            $site = null;
        }

        RuntimeCache::set($cacheKey, $site);

        return $site;
    }

    public static function getByRootId(int $id): ?Site
    {
        try {
            $site = new self();
            $site->getDao()->getByRootId((int)$id);

            return $site;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * @param string $domain
     *
     * @return Site|string|null
     *
     * @throws \Exception
     */
    public static function getByDomain(string $domain): Site|string|null
    {
        // cached because this is called in the route
        $cacheKey = 'site_domain_'. md5($domain);

        if (RuntimeCache::isRegistered($cacheKey)) {
            $site = RuntimeCache::get($cacheKey);
        } elseif (!$site = \Pimcore\Cache::load($cacheKey)) {
            try {
                $site = new self();
                $site->getDao()->getByDomain($domain);
            } catch (NotFoundException $e) {
                $site = 'failed';
            }

            \Pimcore\Cache::save($site, $cacheKey, ['system', 'site'], null, 999);
        }

        if ($site === 'failed' || !$site) {
            $site = null;
        }

        RuntimeCache::set($cacheKey, $site);

        return $site;
    }

    /**
     * @param mixed $mixed
     *
     * @return Site|string|null
     *
     * @throws \Exception
     */
    public static function getBy(mixed $mixed): Site|string|null
    {
        $site = null;

        if (is_numeric($mixed)) {
            $site = self::getById($mixed);
        } elseif (is_string($mixed)) {
            $site = self::getByDomain($mixed);
        } elseif ($mixed instanceof Site) {
            $site = $mixed;
        }

        return $site;
    }

    public static function create(array $data): Site
    {
        $site = new self();
        self::checkCreateData($data);
        $site->setValues($data);

        return $site;
    }

    /**
     * returns true if the current process/request is inside a site
     *
     * @return bool
     */
    public static function isSiteRequest(): bool
    {
        if (null !== self::$currentSite) {
            return true;
        }

        return false;
    }

    /**
     * @return Site
     *
     * @throws \Exception
     */
    public static function getCurrentSite(): Site
    {
        if (null !== self::$currentSite) {
            return self::$currentSite;
        }

        throw new \Exception('This request/process is not inside a subsite');
    }

    /**
     * Register the current site
     *
     * @param Site $site
     */
    public static function setCurrentSite(Site $site): void
    {
        self::$currentSite = $site;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomains(): array
    {
        return $this->domains;
    }

    public function getRootId(): int
    {
        return $this->rootId;
    }

    public function getRootDocument(): ?Document\Page
    {
        return $this->rootDocument;
    }

    public function setId(int $id): static
    {
        $this->id = (int) $id;

        return $this;
    }

    public function setDomains(mixed $domains): static
    {
        if (is_string($domains)) {
            $domains = \Pimcore\Tool\Serialize::unserialize($domains);
        }
        $this->domains = $domains;

        return $this;
    }

    public function setRootId(int $rootId): static
    {
        $this->rootId = (int) $rootId;

        $rd = Document\Page::getById($this->rootId);
        $this->setRootDocument($rd);

        return $this;
    }

    public function setRootDocument(?Document\Page $rootDocument): static
    {
        $this->rootDocument = $rootDocument;

        return $this;
    }

    public function setRootPath(?string $path): static
    {
        $this->rootPath = $path;

        return $this;
    }

    public function getRootPath(): ?string
    {
        if (!$this->rootPath && $this->getRootDocument()) {
            return $this->getRootDocument()->getRealFullPath();
        }

        return $this->rootPath;
    }

    public function setErrorDocument(string $errorDocument)
    {
        $this->errorDocument = $errorDocument;
    }

    public function getErrorDocument(): string
    {
        return $this->errorDocument;
    }

    public function setLocalizedErrorDocuments(mixed $localizedErrorDocuments): static
    {
        if (is_string($localizedErrorDocuments)) {
            $localizedErrorDocuments = \Pimcore\Tool\Serialize::unserialize($localizedErrorDocuments);
        }
        $this->localizedErrorDocuments = $localizedErrorDocuments;

        return $this;
    }

    public function getLocalizedErrorDocuments(): array
    {
        return $this->localizedErrorDocuments;
    }

    public function setMainDomain(string $mainDomain)
    {
        $this->mainDomain = $mainDomain;
    }

    public function getMainDomain(): string
    {
        return $this->mainDomain;
    }

    public function setRedirectToMainDomain(bool $redirectToMainDomain)
    {
        $this->redirectToMainDomain = (bool) $redirectToMainDomain;
    }

    public function getRedirectToMainDomain(): bool
    {
        return $this->redirectToMainDomain;
    }

    /**
     * @internal
     */
    public function clearDependentCache()
    {
        // this is mostly called in Site\Dao not here
        try {
            \Pimcore\Cache::clearTag('site');
        } catch (\Exception $e) {
            Logger::crit((string) $e);
        }
    }

    public function setModificationDate(int $modificationDate): static
    {
        $this->modificationDate = (int) $modificationDate;

        return $this;
    }

    public function getModificationDate(): ?int
    {
        return $this->modificationDate;
    }

    public function setCreationDate(int $creationDate): static
    {
        $this->creationDate = (int) $creationDate;

        return $this;
    }

    public function getCreationDate(): ?int
    {
        return $this->creationDate;
    }

    public function save(): void
    {
        $preSaveEvent = new SiteEvent($this);
        $this->dispatchEvent($preSaveEvent, SiteEvents::PRE_SAVE);

        $this->getDao()->save();

        $postSaveEvent = new SiteEvent($this);
        $this->dispatchEvent($postSaveEvent, SiteEvents::POST_SAVE);
    }

    public function delete(): void
    {
        $preDeleteEvent = new SiteEvent($this);
        $this->dispatchEvent($preDeleteEvent, SiteEvents::PRE_DELETE);

        $this->getDao()->delete();

        $postDeleteEvent = new SiteEvent($this);
        $this->dispatchEvent($postDeleteEvent, SiteEvents::POST_DELETE);
    }
}
