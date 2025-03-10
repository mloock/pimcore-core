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

namespace Pimcore\Model\Document\Editable;

use Pimcore\Document\Editable\EditableHandler;
use Pimcore\Logger;
use Pimcore\Model;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Element;
use Pimcore\Targeting\Document\DocumentTargetingConfigurator;

/**
 * @method \Pimcore\Model\Document\Editable\Dao getDao()
 */
class Renderlet extends Model\Document\Editable implements IdRewriterInterface, EditmodeDataInterface, LazyLoadingInterface
{
    /**
     * Contains the ID of the linked object
     *
     * @internal
     *
     * @var int|null
     */
    protected ?int $id = null;

    /**
     * Contains the object
     *
     * @internal
     */
    protected Document|Asset|null|DataObject|Element\ElementDescriptor $o = null;

    /**
     * Contains the type
     *
     * @internal
     *
     * @var string|null
     */
    protected ?string $type = null;

    /**
     * Contains the subtype
     *
     * @internal
     *
     * @var string|null
     */
    protected ?string $subtype = null;

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'renderlet';
    }

    /**
     * {@inheritdoc}
     */
    public function getData(): mixed
    {
        return [
            'id' => $this->id,
            'type' => $this->getObjectType(),
            'subtype' => $this->subtype,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getDataEditmode(): ?array
    {
        if ($this->o instanceof Element\ElementInterface) {
            return [
                'id' => $this->id,
                'type' => $this->getObjectType(),
                'subtype' => $this->subtype,
            ];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function frontend()
    {
        // TODO inject services via DI when editables are built through container
        $container = \Pimcore::getContainer();
        $editableHandler = $container->get(EditableHandler::class);

        if (!is_array($this->config)) {
            $this->config = [];
        }

        if (empty($this->config['controller']) && !empty($this->config['template'])) {
            $this->config['controller'] = $container->getParameter('pimcore.documents.default_controller');
        }

        if (empty($this->config['controller'])) {
            // this can be the case e.g. in \Pimcore\Model\Search\Backend\Data::setDataFromElement() where
            // this method is called without the config, so it would just render the default controller with the default template
            return '';
        }

        $this->load();

        if ($this->o instanceof Element\ElementInterface) {
            if (method_exists($this->o, 'isPublished')) {
                if (!$this->o->isPublished()) {
                    return '';
                }
            }

            // apply best matching target group (if any)
            if ($this->o instanceof Document\Targeting\TargetingDocumentInterface) {
                $targetingConfigurator = $container->get(DocumentTargetingConfigurator::class);
                $targetingConfigurator->configureTargetGroup($this->o);
            }

            $blockparams = ['controller', 'template'];

            $params = [
                'template' => isset($this->config['template']) ? $this->config['template'] : null,
                'id' => $this->id,
                'type' => $this->type,
                'subtype' => $this->subtype,
                'pimcore_request_source' => 'renderlet',
            ];

            foreach ($this->config as $key => $value) {
                if (!array_key_exists($key, $params) && !in_array($key, $blockparams)) {
                    $params[$key] = $value;
                }
            }

            return $editableHandler->renderAction(
                $this->config['controller'],
                $params
            );
        }

        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFromResource(mixed $data): static
    {
        $data = \Pimcore\Tool\Serialize::unserialize($data);

        $this->id = $data['id'];
        $this->type = (string) $data['type'];
        $this->subtype = $data['subtype'];

        $this->setElement();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setDataFromEditmode(mixed $data): static
    {
        if (is_array($data) && isset($data['id'])) {
            $this->id = $data['id'];
            $this->type = $data['type'];
            $this->subtype = $data['subtype'];

            $this->setElement();
        }

        return $this;
    }

    /**
     * Sets the element by the data stored for the object
     *
     * @return $this
     */
    public function setElement(): static
    {
        if ($this->type && $this->id) {
            $this->o = Element\Service::getElementById($this->type, $this->id);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveDependencies(): array
    {
        $this->load();

        $dependencies = [];

        if ($this->o instanceof Element\ElementInterface) {
            $elementType = Element\Service::getElementType($this->o);
            $key = $elementType . '_' . $this->o->getId();

            $dependencies[$key] = [
                'id' => $this->o->getId(),
                'type' => $elementType,
            ];
        }

        return $dependencies;
    }

    /**
     * get correct type of object as string
     */
    private function getObjectType(Element\ElementInterface $object = null): ?string
    {
        $this->load();

        if (!$object) {
            $object = $this->o;
        }
        if ($object instanceof Element\ElementInterface) {
            return Element\Service::getElementType($object);
        }

        return null;
    }

    public function isEmpty(): bool
    {
        $this->load();

        if ($this->o instanceof Element\ElementInterface) {
            return false;
        }

        return true;
    }

    public function checkValidity(): bool
    {
        $sane = true;
        if ($this->id) {
            $el = Element\Service::getElementById($this->type, $this->id);
            if (!$el instanceof Element\ElementInterface) {
                $sane = false;
                Logger::notice('Detected insane relation, removing reference to non existent '.$this->type.' with id ['.$this->id.']');
                $this->id = null;
                $this->type = null;
                $this->o = null;
                $this->subtype = null;
            }
        }

        return $sane;
    }

    /**
     * {@inheritdoc}
     */
    public function __sleep()
    {
        $finalVars = [];
        $parentVars = parent::__sleep();
        $blockedVars = ['o'];
        foreach ($parentVars as $key) {
            if (!in_array($key, $blockedVars)) {
                $finalVars[] = $key;
            }
        }

        return $finalVars;
    }

    /**
     * {@inheritdoc}
     */
    public function load(): void
    {
        if (!$this->o) {
            $this->setElement();
        }
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function setO(DataObject|Asset|Document|null $o): static
    {
        $this->o = $o;

        return $this;
    }

    public function getO(): DataObject|Asset|Document|null
    {
        return $this->o;
    }

    public function setSubtype(string $subtype): static
    {
        $this->subtype = $subtype;

        return $this;
    }

    public function getSubtype(): ?string
    {
        return $this->subtype;
    }

    /**
     * { @inheritdoc }
     */
    public function rewriteIds(array $idMapping): void
    {
        $type = (string) $this->type;
        if ($type && array_key_exists($this->type, $idMapping) && array_key_exists($this->getId(), $idMapping[$this->type])) {
            $this->setId($idMapping[$this->type][$this->getId()]);
            $this->setO(null);
        }
    }
}
