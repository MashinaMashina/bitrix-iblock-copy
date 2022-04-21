<?php

class Structure
{
    public $iblock;
    public $fields = [];
    public $properties = [];
    public $permissions = [];
    public $sites = [];
    public $uf_fields = [];

    public function __construct()
    {
        \CModule::IncludeModule('iblock');
    }

    public function loadId($iblockId)
    {
        $this->iblock = \CIBlock::GetByID($iblockId)->fetch();

        if (empty($this->iblock['ID'])) {
            $this->iblock = null;
            return false;
        }

        $res = \CIBlock::GetProperties($iblockId);
        while ($prop = $res->fetch()) {
            $code = empty($prop['CODE']) ? $prop['ID'] : $prop['CODE'];

            $this->properties[$code] = $prop;
        }
        $res = \CIBlock::GetSite($iblockId);
        while ($site = $res->fetch()) {
            $this->sites[] = $site['SITE_ID'];
        }

        $this->fields = \CIBlock::GetFields($iblockId);
        $this->permissions = \CIBlock::GetGroupPermissions($iblockId);

        $res = \CUserTypeEntity::GetList([], [
            'ENTITY_ID' => 'IBLOCK_'.$iblockId.'_SECTION'
        ]);

        while ($uf = $res->fetch()) {
            $this->uf_fields[] = $uf;
        }

        return true;
    }

    public function save()
    {
        if (empty($this->iblock)) {
            return false;
        }

        if (! empty($this->iblock['ID'])) {
            $oldIblock = \CIBlock::GetByID($this->iblock['ID'])->fetch();

            if ($oldIblock['IBLOCK_TYPE_ID'] === $this->iblock['IBLOCK_TYPE_ID']) {
                return $this->saveIblockDiff($oldIblock, $this->iblock);
            }
        }

        return $this->addIblock();
    }

    protected function addIblock()
    {
        $data = $this->iblock;
        $data['SITE_ID'] = $this->sites;
        $data['FIELDS'] = $this->fields;
        $data['GROUP_ID'] = $this->permissions;

        $CIBlock = new \CIBlock;
        $id = $CIBlock->Add($data);

        if (! $id) {
            echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlock->LAST_ERROR;
            return false;
        }

        $this->iblock['ID'] = $id;

        $CIBlockProperty = new \CIBlockProperty;
        foreach ($this->properties as $k => $property) {
            unset($property['ID'], $property['TIMESTAMP_X']);

            $property['IBLOCK_ID'] = $this->iblock['ID'];
            $propId = $CIBlockProperty->Add($property);

            if (!$propId) {
                echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlockProperty->LAST_ERROR . PHP_EOL;
                return false;
            }

            $this->properties[$k]['ID'] = $id;
        }

        $CUserTypeEntity = new CUserTypeEntity;
        foreach ($this->uf_fields as $uf) {
            unset($uf['ID']);
            $uf['ENTITY_ID'] = preg_replace('#IBLOCK_\d+#', 'IBLOCK_' . $this->iblock['ID'], $uf['ENTITY_ID']);

            $CUserTypeEntity->add($uf);
        }
        return true;
    }

    protected function saveIblockDiff($oldIblock)
    {
        echo 'I can\'t keep the difference';
        die(__FILE__ . '::' . __LINE__);
        $iblockId = $this->iblock['ID'];
        $oldIblockId = $oldIblock['ID'];

        unset($this->iblock['ID'], $this->iblock['TIMESTAMP_X'], $this->iblock['TMP_ID']);
        unset($oldIblock['ID'], $oldIblock['TIMESTAMP_X'], $oldIblock['TMP_ID']);

        $data = [];
        foreach ($this->iblock as $k => $v) {
            if ($oldIblock[$k] !== $v) {
                $data[$k] = $v;
            }
        }

        $oldFields = \CIBlock::GetFields($oldIblockId);
        $oldPermissions = \CIBlock::GetGroupPermissions($oldIblockId);

        $res = \CIBlock::GetSite($iblockId);
        $oldSites = [];
        while ($site = $res->fetch()) {
            $oldSites[] = $site['SITE_ID'];
        }

        if ($oldFields != $this->fields) {
            $data['FIELDS'] = $this->fields;
        }
        if ($oldPermissions != $this->permissions) {
            $data['GROUP_ID'] = $this->permissions;
        }
        if ($oldSites != $this->sites) {
            $data['SITE_ID'] = $oldSites;
        }

        $CIBlockProperty = new \CIBlockProperty;
        $res = \CIBlock::GetProperties($iblockId);
        $oldProperties = [];
        while ($prop = $res->fetch()) {
            $code = empty($prop['CODE']) ? $prop['ID'] : $prop['CODE'];

            $oldProperties[$code] = $prop;
        }

        foreach ($this->properties as $k => $property) {
            unset($property['ID'], $property['TIMESTAMP_X'], $property['IBLOCK_ID'], $property['TMP_ID']);

            if (! isset($oldProperties[$k])) {
                $property['IBLOCK_ID'] = $this->iblock['ID'];
                $propId = $CIBlockProperty->Add($property);

                if (!$propId) {
                    echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlockProperty->LAST_ERROR . PHP_EOL;
                    return false;
                }
            }

            $oldProp = $oldProperties[$k];
            $propId = $oldProp['ID'];
            unset($oldProp['ID'], $oldProp['TIMESTAMP_X'], $oldProp['IBLOCK_ID'], $oldProp['TMP_ID']);

            if ($oldProp !== $property) {
                $property['IBLOCK_ID'] = $this->iblock['ID'];

                $res = $CIBlockProperty->Update($propId);

                if (!$res) {
                    echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlockProperty->LAST_ERROR . PHP_EOL;
                    return false;
                }
            }
        }

        if (count($data)) {
            $CIBlock = new \CIBlock;

            $res = $CIBlock->Update($iblockId, $data);

            if (! $res) {
                echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlock->LAST_ERROR;
            }

            return $res;
        }

        return true;
    }
}