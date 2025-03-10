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

namespace Pimcore\Bundle\AdminBundle;

use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ContentSecurityPolicyUrlsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\GDPRDataProviderPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\ImportExportLocatorsPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\SerializerPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\TranslationServicesPass;
use Pimcore\Bundle\AdminBundle\DependencyInjection\Compiler\TranslatorPass;
use Pimcore\Bundle\AdminBundle\GDPR\DataProvider\DataProviderInterface;
use Pimcore\Bundle\AdminBundle\Security\Factory\PreAuthenticatedAdminSessionFactory;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @internal
 */
class PimcoreAdminBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        // auto-tag GDPR data providers
        $container
            ->registerForAutoconfiguration(DataProviderInterface::class)
            ->addTag('pimcore.gdpr.data-provider');

        $container->addCompilerPass(new SerializerPass());
        $container->addCompilerPass(new GDPRDataProviderPass());
        $container->addCompilerPass(new ImportExportLocatorsPass());
        $container->addCompilerPass(new TranslationServicesPass());
        $container->addCompilerPass(new TranslatorPass());
        $container->addCompilerPass(new ContentSecurityPolicyUrlsPass());

        /** @var SecurityExtension $extension */
        $extension = $container->getExtension('security');
        $extension->addAuthenticatorFactory(new PreAuthenticatedAdminSessionFactory());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
