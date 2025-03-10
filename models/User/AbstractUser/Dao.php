<?php

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

namespace Pimcore\Model\User\AbstractUser;

use Pimcore\Logger;
use Pimcore\Model;

/**
 * @internal
 *
 * @property \Pimcore\Model\User\AbstractUser $model
 */
class Dao extends Model\Dao\AbstractDao
{
    /**
     * @param int $id
     *
     * @throws Model\Exception\NotFoundException
     */
    public function getById(int $id)
    {
        if ($this->model->getType()) {
            $data = $this->db->fetchAssociative('SELECT * FROM users WHERE `type` = ? AND id = ?', [$this->model->getType(), $id]);
        } else {
            $data = $this->db->fetchAssociative('SELECT * FROM users WHERE `id` = ?', [$id]);
        }

        if ($data) {
            $this->assignVariablesToModel($data);
        } else {
            throw new Model\Exception\NotFoundException("user doesn't exist");
        }
    }

    /**
     * @param string $name
     *
     * @throws Model\Exception\NotFoundException
     */
    public function getByName(string $name)
    {
        $data = $this->db->fetchAssociative('SELECT * FROM users WHERE `type` = ? AND `name` = ?', [$this->model->getType(), $name]);

        if ($data) {
            $this->assignVariablesToModel($data);
        } else {
            throw new Model\Exception\NotFoundException(sprintf('User with name "%s" does not exist', $name));
        }
    }

    public function create()
    {
        $this->db->insert('users', [
            'name' => $this->model->getName(),
            'type' => $this->model->getType(),
        ]);

        $this->model->setId((int) $this->db->lastInsertId());
    }

    /**
     * Quick test if there are children
     *
     * @return bool
     */
    public function hasChildren(): bool
    {
        if (!$this->model->getId()) {
            return false;
        }

        $c = $this->db->fetchOne('SELECT id FROM users WHERE parentId = ?', [$this->model->getId()]);

        return (bool) $c;
    }

    /**
     * @throws \Exception
     */
    public function update()
    {
        if (strlen($this->model->getName()) < 2) {
            throw new \Exception('Name of user/role must be at least 2 characters long');
        }

        $data = [];
        $dataRaw = $this->model->getObjectVars();
        foreach ($dataRaw as $key => $value) {
            if (in_array($key, $this->getValidTableColumns('users'))) {
                if (is_bool($value)) {
                    $value = (int) $value;
                } elseif (in_array($key, ['permissions', 'roles', 'docTypes', 'classes', 'perspectives', 'websiteTranslationLanguagesEdit', 'websiteTranslationLanguagesView'])) {
                    // permission and roles are stored as csv
                    if (is_array($value)) {
                        $value = implode(',', $value);
                    }
                } elseif (in_array($key, ['twoFactorAuthentication'])) {
                    $value = json_encode($value);
                }
                $data[$key] = $value;
            }
        }

        $this->db->update('users', $data, ['id' => $this->model->getId()]);
    }

    /**
     * @throws \Exception
     */
    public function delete()
    {
        $userId = $this->model->getId();
        Logger::debug('delete user with ID: ' . $userId);

        $this->db->delete('users', ['id' => $userId]);
    }

    /**
     * @throws \Exception
     */
    public function setLastLoginDate()
    {
        $data['lastLogin'] = (new \DateTime())->getTimestamp();
        $this->db->update('users', $data, ['id' => $this->model->getId()]);
    }
}
