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

namespace Pimcore\Translation;

use Pimcore\Cache;
use Pimcore\Model\Translation;
use Pimcore\Tool;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Translation\Exception\InvalidArgumentException;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Translator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    protected TranslatorInterface|WarmableInterface|TranslatorBagInterface $translator;

    protected array $initializedCatalogues = [];

    protected string $adminPath = '';

    protected array $adminTranslationMapping = [];

    /**
     * If true, the translator will just return the translation key instead of actually translating
     * the message. Can be useful for debugging and to get an overview over used translation keys on
     * a page.
     *
     * @var bool
     */
    protected bool $disableTranslations = false;

    protected Kernel $kernel;

    public function __construct(TranslatorInterface $translator)
    {
        if (!$translator instanceof TranslatorBagInterface) {
            throw new InvalidArgumentException(sprintf('The Translator "%s" must implement TranslatorInterface and TranslatorBagInterface.', get_class($translator)));
        }

        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function trans(string $id, array $parameters = [], string $domain = null, string $locale = null): string
    {
        $id = trim($id);

        if ($this->disableTranslations) {
            return $id;
        }

        if (null === $domain) {
            $domain = Translation::DOMAIN_DEFAULT;
        }

        if ($domain === Translation::DOMAIN_ADMIN && !empty($this->adminTranslationMapping)) {
            if (null === $locale) {
                $locale = $this->getLocale();
            }

            if (array_key_exists($locale, $this->adminTranslationMapping)) {
                $locale = $this->adminTranslationMapping[$locale];
            }
        }

        $catalogue = $this->getCatalogue($locale);
        $locale = $catalogue->getLocale();

        $this->lazyInitialize($domain, $locale);

        $originalId = $id;
        $term = $this->translator->trans($id, $parameters, $domain, $locale);

        // only check for empty translation on original ID - we don't want to create empty
        // translations for normalized IDs when case insensitive
        $term = $this->checkForEmptyTranslation($originalId, $term, $parameters, $domain, $locale);

        // check for an indexed array, that used the ZF1 vsprintf() notation for parameters
        if (isset($parameters[0])) {
            $term = vsprintf($term, $parameters);
        }

        $term = $this->updateLinks($term);

        return $term;
    }

    /**
     * {@inheritdoc}
     */
    public function setLocale(string $locale)
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getLocale(): string
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            return $this->translator->getLocale();
        }

        return \Pimcore\Tool::getDefaultLanguage();
    }

    /**
     * {@inheritdoc}
     *
     * @return MessageCatalogueInterface
     */
    public function getCatalogue(string $locale = null): MessageCatalogueInterface
    {
        return $this->translator->getCatalogue($locale);
    }

    /**
     * @return MessageCatalogueInterface[]
     */
    public function getCatalogues(): array
    {
        return $this->translator->getCatalogues();
    }

    /**
     * @internal
     *
     * @param string $domain
     * @param string $locale
     */
    public function lazyInitialize(string $domain, string $locale)
    {
        $cacheKey = $this->getCacheKey($domain, $locale);

        if (isset($this->initializedCatalogues[$cacheKey])) {
            return;
        }

        $this->initializedCatalogues[$cacheKey] = true;

        if (Translation::isAValidDomain($domain)) {
            $catalogue = null;

            if (!$catalogue = Cache::load($cacheKey)) {
                $data = ['__pimcore_dummy' => 'only_a_dummy'];
                $dataIntl = ['__pimcore_dummy' => 'only_a_dummy'];

                if ($domain == 'admin') {
                    $jsonFiles = [
                        $locale . '.json' => 'en.json',
                        $locale . '.extended.json' => 'en.extended.json',
                    ];

                    foreach ($jsonFiles as $sourceFile => $fallbackFile) {
                        try {
                            $jsonPath = $this->getKernel()->locateResource($this->getAdminPath() . '/' . $sourceFile);
                        } catch (\Exception $e) {
                            $jsonPath = $this->getKernel()->locateResource($this->getAdminPath() . '/' . $fallbackFile);
                        }

                        $jsonTranslations = json_decode(file_get_contents($jsonPath), true);
                        if (is_array($jsonTranslations)) {
                            $defaultCatalog = $this->getCatalogue($locale);

                            foreach ($jsonTranslations as $translationKey => $translationValue) {
                                if (!$defaultCatalog->has($translationKey, 'admin')) {
                                    $data[$translationKey] = $translationValue;
                                }
                            }
                        }
                    }
                }

                $list = new Translation\Listing();
                $list->setDomain($domain);

                $debugAdminTranslations = \Pimcore\Config::getSystemConfiguration('general')['debug_admin_translations'] ?? false;
                $list->setCondition('language = ?', [$locale]);
                $translations = $list->loadRaw();

                foreach ($translations as $translation) {
                    $translationTerm = Tool\Text::removeLineBreaks($translation['text']);
                    if (
                        (!isset($data[$translation['key']]) && !$this->getCatalogue($locale)->has($translation['key'], $domain)) ||
                        !empty($translationTerm)) {
                        $translationKey = $translation['key'];

                        if (empty($translationTerm) && $debugAdminTranslations) {
                            //wrap non-translated keys with "+", if debug admin translations is enabled
                            $translationTerm = '+' . $translationKey. '+';
                        }

                        if (empty($translation['type']) || $translation['type'] === 'simple') {
                            $data[$translationKey] = $translationTerm;
                        } else {
                            $dataIntl[$translationKey] = $translationTerm;
                        }
                    }
                }

                // aliases support
                if ($domain == 'admin') {
                    $aliasesPath = $this->getKernel()->locateResource($this->getAdminPath() . '/aliases.json');
                    $aliases = json_decode(file_get_contents($aliasesPath), true);
                    foreach ($aliases as $aliasTarget => $aliasSource) {
                        if (isset($data[$aliasSource]) && (!isset($data[$aliasTarget]) || empty($data[$aliasTarget]))) {
                            $data[$aliasTarget] = $data[$aliasSource];
                        }
                    }
                }

                $data = [
                    $domain => $data,
                    $domain.MessageCatalogue::INTL_DOMAIN_SUFFIX => $dataIntl,
                ];
                $catalogue = new MessageCatalogue($locale, $data);

                Cache::save($catalogue, $cacheKey, ['translator', 'translator_website', 'translate'], null, 999);
            }

            if ($catalogue) {
                $this->getCatalogue($locale)->addCatalogue($catalogue);
            }
        }
    }

    /**
     * Resets the initialization of a specific catalogue
     *
     * @param string $domain
     * @param string $locale
     *
     * @return void
     */
    public function resetInitialization(string $domain, string $locale): void
    {
        $cacheKey = $this->getCacheKey($domain, $locale);
        unset($this->initializedCatalogues[$cacheKey]);
    }

    /**
     * Reset Catalogues initialization
     */
    public function resetCache()
    {
        $this->initializedCatalogues = [];
    }

    /**
     * @throws \Exception
     */
    private function checkForEmptyTranslation(string $id, string $translated, array $parameters, string $domain, string $locale): string
    {
        if (empty($id)) {
            return $translated;
        }

        $normalizedId = $id;

        //translate only plural form(seperated by pipe "|") with count param
        if (isset($parameters['%count%']) && $translated && strpos($normalizedId, '|') !== false) {
            $normalizedId = $id = $translated;
            $translated = $this->translator->trans($normalizedId, $parameters, $domain, $locale);
        }

        $lookForFallback = empty($translated);
        if ($normalizedId != $translated && $translated) {
            return $translated;
        } elseif ($normalizedId == $translated) {
            if ($this->getCatalogue($locale)->has($normalizedId, $domain)) {
                $translated = $this->getCatalogue($locale)->get($normalizedId, $domain);
                if ($normalizedId != $translated && $translated) {
                    return $translated;
                }
            } elseif (Translation::isAValidDomain($domain)) {
                if (strlen($id) > 190) {
                    throw new \Exception("Message ID's longer than 190 characters are invalid!");
                }

                // no translation found create key
                if (Translation::IsAValidLanguage($domain, $locale)) {
                    $t = Translation::getByKey($id, $domain);
                    if ($t) {
                        if (!$t->hasTranslation($locale)) {
                            $t->addTranslation($locale, '');
                        } else {
                            // return the original not lowercased ID
                            return $id;
                        }
                    } else {
                        $t = new Translation();
                        $t->setDomain($domain);
                        $t->setKey($id);

                        // add all available languages
                        $availableLanguages = (array)Translation::getValidLanguages();
                        foreach ($availableLanguages as $language) {
                            $t->addTranslation($language, '');
                        }
                    }

                    TranslationEntriesDumper::addToSaveQueue($t);
                }

                // put it into the catalogue, otherwise when there are more calls to the same key during one process
                // the key would be inserted/updated several times, what would be redundant
                $this->getCatalogue($locale)->set($normalizedId, $id, $domain);
            }
        }

        // now check for custom fallback locales, only for shared translations
        if ($lookForFallback && $domain == 'messages') {
            foreach (Tool::getFallbackLanguagesFor($locale) as $fallbackLanguage) {
                $this->lazyInitialize($domain, $fallbackLanguage);
                $catalogue = $this->getCatalogue($fallbackLanguage);

                $fallbackValue = '';

                if ($catalogue->has($normalizedId, $domain)) {
                    $fallbackValue = $catalogue->get($normalizedId, $domain);
                }

                if ($fallbackValue && $normalizedId != $fallbackValue) {
                    // update fallback value in original catalogue otherwise multiple calls to the same id will not work
                    $this->getCatalogue($locale)->set($normalizedId, $fallbackValue, $domain);

                    return strtr($fallbackValue, $parameters);
                }
            }
        }

        return !empty($translated) ? $translated : $id;
    }

    /**
     * @internal
     *
     * @return string
     */
    public function getAdminPath(): string
    {
        return $this->adminPath;
    }

    /**
     * @param string $adminPath
     *
     *@internal
     *
     */
    public function setAdminPath(string $adminPath)
    {
        $this->adminPath = $adminPath;
    }

    /**
     * @internal
     *
     * @return array
     */
    public function getAdminTranslationMapping(): array
    {
        return $this->adminTranslationMapping;
    }

    /**
     * @internal
     *
     * @param array $adminTranslationMapping
     */
    public function setAdminTranslationMapping(array $adminTranslationMapping): void
    {
        $this->adminTranslationMapping = $adminTranslationMapping;
    }

    /**
     * @internal
     *
     * @return Kernel
     */
    public function getKernel(): Kernel
    {
        return $this->kernel;
    }

    /**
     * @param Kernel $kernel
     *
     *@internal
     *
     */
    public function setKernel(Kernel $kernel)
    {
        $this->kernel = $kernel;
    }

    public function getDisableTranslations(): bool
    {
        return $this->disableTranslations;
    }

    public function setDisableTranslations(bool $disableTranslations)
    {
        $this->disableTranslations = $disableTranslations;
    }

    private function updateLinks(string $text): string
    {
        if (strpos($text, 'pimcore_id')) {
            $text = Tool\Text::wysiwygText($text);
        }

        return $text;
    }

    /**
     * Passes through all unknown calls onto the translator object.
     *
     * @param string $method
     * @param array $args
     *
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        return call_user_func_array([$this->translator, $method], $args);
    }

    private function getCacheKey(string $domain, string $locale): string
    {
        return 'translation_data_' . md5($domain . '_' . $locale);
    }

    /**
     * @param string $cacheDir
     *
     * @return string[]
     */
    public function warmUp(string $cacheDir): array
    {
        return $this->translator->warmUp($cacheDir);
    }
}
