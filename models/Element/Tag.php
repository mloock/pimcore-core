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

use Pimcore\Event\Model\TagEvent;
use Pimcore\Event\TagEvents;
use Pimcore\Event\Traits\RecursionBlockingEventDispatchHelperTrait;
use Pimcore\Model;

/**
 * @method \Pimcore\Model\Element\Tag\Dao getDao()
 */
final class Tag extends Model\AbstractModel
{
    use RecursionBlockingEventDispatchHelperTrait;

    /**
     * @internal
     */
    protected ?int $id = null;

    /**
     * @internal
     *
     * @var string
     */
    protected string $name;

    /**
     * @internal
     */
    protected int $parentId = 0;

    /**
     * @internal
     *
     * @var string
     */
    protected string $idPath = '';

    /**
     * @internal
     *
     * @var Tag[]|null
     */
    protected ?array $children = null;

    /**
     * @internal
     *
     * @var Tag|null
     */
    protected ?Tag $parent = null;

    /**
     * @static
     *
     * @param int $id
     *
     * @return Tag|null
     */
    public static function getById(int $id): ?Tag
    {
        try {
            $tag = new self();
            $tag->getDao()->getById($id);

            return $tag;
        } catch (Model\Exception\NotFoundException $e) {
            return null;
        }
    }

    /**
     * returns all assigned tags for element
     *
     * @param string $cType
     * @param int $cId
     *
     * @return Tag[]
     */
    public static function getTagsForElement(string $cType, int $cId): array
    {
        $tag = new Tag();

        return $tag->getDao()->getTagsForElement($cType, $cId);
    }

    /**
     * adds given tag to element
     *
     * @param string $cType
     * @param int $cId
     * @param Tag $tag
     */
    public static function addTagToElement(string $cType, int $cId, Tag $tag)
    {
        $event = new TagEvent($tag, [
            'elementType' => $cType,
            'elementId' => $cId,
        ]);
        $tag->dispatchEvent($event, TagEvents::PRE_ADD_TO_ELEMENT);

        $tag->getDao()->addTagToElement($cType, $cId);

        $tag->dispatchEvent($event, TagEvents::POST_ADD_TO_ELEMENT);
    }

    /**
     * removes given tag from element
     *
     * @param string $cType
     * @param int $cId
     * @param Tag $tag
     */
    public static function removeTagFromElement(string $cType, int $cId, Tag $tag)
    {
        $event = new TagEvent($tag, [
            'elementType' => $cType,
            'elementId' => $cId,
        ]);
        $tag->dispatchEvent($event, TagEvents::PRE_REMOVE_FROM_ELEMENT);

        $tag->getDao()->removeTagFromElement($cType, $cId);

        $tag->dispatchEvent($event, TagEvents::POST_REMOVE_FROM_ELEMENT);
    }

    /**
     * sets given tags to element and removes all other tags
     * to remove all tags from element, provide empty array of tags
     *
     * @param string $cType
     * @param int $cId
     * @param Tag[] $tags
     */
    public static function setTagsForElement(string $cType, int $cId, array $tags)
    {
        $tag = new Tag();
        $tag->getDao()->setTagsForElement($cType, $cId, $tags);
    }

    public static function batchAssignTagsToElement(string $cType, array $cIds, array $tagIds, bool $replace = false)
    {
        $tag = new Tag();
        $tag->getDao()->batchAssignTagsToElement($cType, $cIds, $tagIds, $replace);
    }

    /**
     * Retrieves all elements that have a specific tag or one of its child tags assigned
     *
     * @param Tag    $tag               The tag to search for
     * @param string $type              The type of elements to search for: 'document', 'asset' or 'object'
     * @param array  $subtypes          Filter by subtypes, eg. page, object, email, folder etc.
     * @param array $classNames        For objects only: filter by classnames
     * @param bool $considerChildTags Look for elements having one of $tag's children assigned
     *
     * @return array
     */
    public static function getElementsForTag(
        Tag $tag,
        string $type,
        array $subtypes = [],
        array $classNames = [],
        bool $considerChildTags = false
    ): array {
        return $tag->getDao()->getElementsForTag($tag, $type, $subtypes, $classNames, $considerChildTags);
    }

    /**
     * @param string $path name path of tags
     *
     * @return Tag|null
     */
    public static function getByPath(string $path): ?Tag
    {
        try {
            return (new self)->getDao()->getByPath($path);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function save()
    {
        $isUpdate = $this->exists();

        if ($isUpdate) {
            $this->dispatchEvent(new TagEvent($this), TagEvents::PRE_UPDATE);
        } else {
            $this->dispatchEvent(new TagEvent($this), TagEvents::PRE_ADD);
        }

        $this->correctPath();
        $this->getDao()->save();

        if ($isUpdate) {
            $this->dispatchEvent(new TagEvent($this), TagEvents::POST_UPDATE);
        } else {
            $this->dispatchEvent(new TagEvent($this), TagEvents::POST_ADD);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getParentId(): int
    {
        return $this->parentId;
    }

    public function setParentId(int $parentId): static
    {
        $this->parentId = $parentId;
        $this->parent = null;
        $this->correctPath();

        return $this;
    }

    public function getParent(): ?Tag
    {
        if ($this->parent === null && $parentId = $this->getParentId()) {
            $this->parent = self::getById($parentId);
        }

        return $this->parent;
    }

    public function getIdPath(): string
    {
        return $this->idPath;
    }

    public function getFullIdPath(): string
    {
        return $this->getIdPath() . $this->getId() . '/';
    }

    public function getNamePath(bool $includeOwnName = true): string
    {
        //set id path to correct value
        $parentNames = [];
        if ($includeOwnName) {
            $parentNames[] = $this->getName();
        }
        $parent = $this->getParent();
        while ($parent) {
            $parentNames[] = $parent->getName();
            $parent = $parent->getParent();
        }

        $parentNames = array_reverse($parentNames);

        return '/' . implode('/', $parentNames);
    }

    public function __toString()
    {
        return $this->getNamePath();
    }

    /**
     * @return Tag[]
     */
    public function getChildren(): array
    {
        if ($this->children == null) {
            if ($this->getId()) {
                $listing = new Tag\Listing();
                $listing->setCondition('parentId = ?', $this->getId());
                $listing->setOrderKey('name');
                $this->children = $listing->load();
            } else {
                $this->children = [];
            }
        }

        return $this->children;
    }

    public function hasChildren(): bool
    {
        return count($this->getChildren()) > 0;
    }

    public function correctPath()
    {
        //set id path to correct value
        $parentIds = [];
        $parent = $this->getParent();
        while ($parent) {
            $parentIds[] = $parent->getId();
            $parent = $parent->getParent();
        }

        $parentIds = array_reverse($parentIds);
        if ($parentIds) {
            $this->idPath = '/' . implode('/', $parentIds) . '/';
        } else {
            $this->idPath = '/';
        }
    }

    /**
     * Deletes a tag
     *
     * @throws \Exception
     */
    public function delete()
    {
        $this->dispatchEvent(new TagEvent($this), TagEvents::PRE_DELETE);

        $this->getDao()->delete();

        $this->dispatchEvent(new TagEvent($this), TagEvents::POST_DELETE);
    }

    public function exists(): bool
    {
        return $this->getDao()->exists();
    }
}
