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

use Pimcore\Model\Exception\NotFoundException;

/**
 * @method \Pimcore\Model\Glossary\Dao getDao()
 * @method void delete()
 * @method void save()
 */
class Glossary extends AbstractModel
{
    /**
     * @internal
     *
     * @var int|null
     */
    protected ?int $id = null;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $text = null;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $link = null;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $abbr = null;

    /**
     * @internal
     *
     * @var string|null
     */
    protected ?string $language = null;

    /**
     * @internal
     *
     * @var bool
     */
    protected bool $casesensitive = false;

    /**
     * @internal
     *
     * @var bool
     */
    protected bool $exactmatch = false;

    /**
     * @internal
     *
     * @var int|null
     */
    protected ?int $site = null;

    /**
     * @internal
     *
     * @var int|null
     */
    protected ?int $creationDate = null;

    /**
     * @internal
     *
     * @var int|null
     */
    protected ?int $modificationDate = null;

    public static function getById(int $id): ?Glossary
    {
        try {
            $glossary = new self();
            $glossary->setId((int)$id);
            $glossary->getDao()->getById();

            return $glossary;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    public static function create(): Glossary
    {
        $glossary = new self();
        $glossary->save();

        return $glossary;
    }

    public function setId(int $id): static
    {
        $this->id = (int) $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setLink(string $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function setAbbr(string $abbr): static
    {
        $this->abbr = $abbr;

        return $this;
    }

    public function getAbbr(): ?string
    {
        return $this->abbr;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setCasesensitive(bool $casesensitive): static
    {
        $this->casesensitive = (bool) $casesensitive;

        return $this;
    }

    public function getCasesensitive(): bool
    {
        return $this->casesensitive;
    }

    public function setExactmatch(bool $exactmatch): static
    {
        $this->exactmatch = (bool) $exactmatch;

        return $this;
    }

    public function getExactmatch(): bool
    {
        return $this->exactmatch;
    }

    public function setSite(Site|int $site): static
    {
        if ($site instanceof Site) {
            $site = $site->getId();
        }
        $this->site = (int) $site;

        return $this;
    }

    public function getSite(): ?int
    {
        return $this->site;
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
}
