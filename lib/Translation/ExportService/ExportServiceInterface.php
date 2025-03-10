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

namespace Pimcore\Translation\ExportService;

use Pimcore\Translation\ExportService\Exporter\ExporterInterface;
use Pimcore\Translation\TranslationItemCollection\TranslationItemCollection;

interface ExportServiceInterface
{
    /**
     * @param TranslationItemCollection $translationItems
     * @param string $sourceLanguage
     * @param array $targetLanguages
     * @param string|null $exportId
     *
     * @return string
     *
     * @throws \Exception
     */
    public function exportTranslationItems(TranslationItemCollection $translationItems, string $sourceLanguage, array $targetLanguages, string $exportId = null): string;

    public function getTranslationExporter(): ExporterInterface;
}
