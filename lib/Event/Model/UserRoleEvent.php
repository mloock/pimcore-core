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

namespace Pimcore\Event\Model;

use Pimcore\Model\User\AbstractUser;
use Symfony\Contracts\EventDispatcher\Event;

class UserRoleEvent extends Event
{
    protected AbstractUser $userRole;

    /**
     * DocumentEvent constructor.
     *
     * @param AbstractUser $userRole
     */
    public function __construct(AbstractUser $userRole)
    {
        $this->userRole = $userRole;
    }

    public function getUserRole(): AbstractUser
    {
        return $this->userRole;
    }

    public function setUserRole(AbstractUser $userRole)
    {
        $this->userRole = $userRole;
    }
}
