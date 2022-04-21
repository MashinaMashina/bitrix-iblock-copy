<?php

class Content
{
    protected $lastContentId = 0;
    protected $lastSectionId = 0;
    protected $sections = [];

    protected $idFile;
    protected $sectionsFile;

    protected $fileFields;
    protected $enum_lists;

    public function __construct()
    {
        \CModule::IncludeModule('iblock');

        $this->idFile = __DIR__ . '/../last-content-id.tmp';
        $this->sectionsFile = __DIR__ . '/../sections.tmp';
    }

    public function copySections($arFilter, $toIblockId)
    {
        $this->loadSections();

        $res = \CIBlockSection::GetList(['DEPTH_LEVEL' => 'ASC'], $arFilter, false, ['*', 'UF_*']);

        $CIBlockSection = new CIBlockSection;
        while ($arSection = $res->Fetch()) {
            $oldId = $arSection['ID'];

            // этот раздел уже скопирован в данный инфоблок
            if (isset($this->sections[$toIblockId][$oldId])) {
                continue;
            }

            unset($arSection['ID'], $arSection['TIMESTAMP_X'], $arSection['LEFT_MARGIN'], $arSection['RIGHT_MARGIN'], $arSection['DEPTH_LEVEL'], $arSection['TMP_ID']);

            $arSection['IBLOCK_ID'] = $toIblockId;

            foreach ($arSection as $k => $v) {
                if (!empty($v) and $this->isFileField($k, $toIblockId)) {
                    $arSection[$k] = \CFile::MakeFileArray($v);
                }
            }

            if (! empty($arSection['IBLOCK_SECTION_ID'])) {
                $arSection['IBLOCK_SECTION_ID'] = $this->sections[$toIblockId][$arSection['IBLOCK_SECTION_ID']];
            }

            $sectionId = $CIBlockSection->Add($arSection);

            if (! $sectionId) {
                echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlockSection->LAST_ERROR . PHP_EOL;
                return false;
            }

            $this->sections[$toIblockId][$oldId] = $sectionId;
        }

        $this->saveSections();
    }

    /*
     * Повторный запуск copyElements() не приведет к дублированию записей.
     * При повторном запуске будет продолжено копирование с того места, где остановился скрипт в прошлый раз.
     * Это обеспечивается запоминанием ID в файле last-content-id.tmp
     *
     * Чтобы начать сначала, вызовите метод clear()
     */
    public function copyElements($arFilter, $toIblockId, $chunkSize = 100)
    {
        $this->loadSections();
        $this->loadContentId();

        $startTime = time();

        $i = 0;
        while ($n = $this->copyElementsChunk($arFilter, $toIblockId, $chunkSize)){
            $i += $n;
            $spent = time() - $startTime;
            echo "Copied: $i elements; Time spent: $spent sec\n";
        }

        if ($i === 0) {
            echo 'not found elements to copy' . PHP_EOL;
        }
    }

    /*
     * Переносим не всё разом, а по $chunkSize штук.
     */
    protected function copyElementsChunk($arFilter, $toIblockId, $chunkSize = 100)
    {
        if ($this->lastContentId) {
            $arFilter['>ID'] = $this->lastContentId;
        }

        $res = \CIBlockElement::GetList(['ID' => 'ASC'], $arFilter, false, ['nTopCount' => $chunkSize]);

        $CIBlockElement = new \CIBlockElement;
        while ($ob = $res->GetNextElement()) {
            $arItem = $ob->GetFields();
            $arItem['PROPERTIES'] = $ob->GetProperties();

            $oldElementId = $arItem['ID'];

            $data = [];

            if (! empty($arItem['IBLOCK_SECTION_ID'])) {
                $data['IBLOCK_SECTION_ID'] = $this->sections[$toIblockId][$arItem['IBLOCK_SECTION_ID']];
            }

            $data['IBLOCK_ID'] = $toIblockId;
            foreach ($arItem as $k => $v) {
                if (substr($k, 0, 1) === '~') {
                    if (strpos($k, '~IBLOCK_') === 0) {
                        continue;
                    }

                    $data[substr($k, 1)] = $v;
                }
            }

            unset($data['TIMESTAMP_X'], $data['TIMESTAMP_X_UNIX'], $data['DATE_CREATE'], $data['DATE_CREATE_UNIX']);

            if (! empty($data['PREVIEW_PICTURE'])) {
                $data['PREVIEW_PICTURE'] = \CFile::MakeFileArray($data['PREVIEW_PICTURE']);
            }
            if (! empty($data['DETAIL_PICTURE'])) {
                $data['DETAIL_PICTURE'] = \CFile::MakeFileArray($data['DETAIL_PICTURE']);
            }

            $props = [];
            foreach ($arItem['PROPERTIES'] as $prop) {
                if (empty($prop['~VALUE'])) {
                    continue;
                }

                if (is_array($prop['VALUE'])) {
                    foreach ($prop['~VALUE'] as $k => $val) {
                        if ($prop['PROPERTY_TYPE'] === 'F') {
                            $props[$prop['CODE']][] = \CFile::MakeFileArray($val);
                        } else {
                            if($prop['PROPERTY_TYPE'] === 'L') {
                                $val = $this->replaceEnum($prop['VALUE_ENUM_ID'][$k], $toIblockId);
                            }

                            $props[$prop['CODE']][] = [
                                'VALUE' => $val,
                                'DESCRIPTION' => $prop['~DESCRIPTION'][$k],
                            ];
                        }
                    }
                } else {
                    if ($prop['PROPERTY_TYPE'] === 'F') {
                        $props[$prop['CODE']] = \CFile::MakeFileArray($prop['~VALUE']);
                    } else {
                        if($prop['PROPERTY_TYPE'] === 'L') {
                            $prop['~VALUE'] = $this->replaceEnum($prop['VALUE_ENUM_ID'], $toIblockId);
                        }

                        $props[$prop['CODE']] = [
                            'VALUE' => $prop['~VALUE'],
                            'DESCRIPTION' => $prop['~DESCRIPTION'],
                        ];
                    }
                }
            }

            $elementId = $CIBlockElement->Add($data);

            if (!$elementId) {
                echo __FILE__ . '::' . __LINE__ . ' - '. $CIBlockElement->LAST_ERROR . PHP_EOL;
                return false;
            }

            \CIBlockElement::SetPropertyValues($elementId, $toIblockId, $props);

            $this->lastContentId = $oldElementId;
            $this->saveContentId();
        }

        return $res->SelectedRowsCount();
    }

    public function isEnumField($name, $iblockId)
    {
        $this->loadUfFields($iblockId);

        return $this->uf_types[$iblockId][$name] === 'enum';
    }

    public function isFileField($name, $iblockId)
    {
        $this->loadUfFields($iblockId);

        return $this->uf_types[$iblockId][$name] === 'file';
    }

    protected function loadUfFields($iblockId)
    {
        if (! isset($this->uf_types[$iblockId])) {
            $this->uf_types[$iblockId]['PICTURE'] = 'file';
            $this->uf_types[$iblockId]['DETAIL_PICTURE'] = 'file';

            $res = \CUserTypeEntity::GetList([], [
                'ENTITY_ID' => 'IBLOCK_'.$iblockId.'_SECTION'
            ]);

            while ($uf = $res->fetch()) {
                $this->uf_types[$iblockId][$uf['FIELD_NAME']] = $uf['USER_TYPE_ID'];
            }
        }
    }

    protected function replaceEnum($val, $iblockId)
    {
        $this->loadLists($iblockId);

        if (isset($this->enum_lists[$val])) {
            return $this->enum_lists[$val];
        }

        return $val;
    }

    protected function loadLists($iblockId)
    {
        if (! isset($this->enum_lists)) {
            $file = __DIR__ .'/../lists.tmp';
            if (file_exists($file)) {
                $this->enum_lists = json_decode(file_get_contents($file), true);
            } else {
                $this->enum_lists = [];
            }
        }
    }

    public function clear()
    {
        if (file_exists($this->idFile)) {
            unlink($this->idFile);
        }
        if (file_exists($this->sectionsFile)) {
            unlink($this->sectionsFile);
        }
    }

    protected function loadContentId()
    {
        $this->lastContentId = 0;

        if (file_exists($this->idFile)) {
            $this->lastContentId = file_get_contents($this->idFile);
        }
    }

    protected function saveContentId()
    {
        file_put_contents($this->idFile, $this->lastContentId);
    }

    protected function loadSections()
    {
        $this->sections = [];

        if (file_exists($this->sectionsFile)) {
            $this->sections = json_decode(file_get_contents($this->sectionsFile), true);
        }
    }

    protected function saveSections()
    {
        file_put_contents($this->sectionsFile, json_encode($this->sections));
    }

}