<?php

/*
 * Копируем инфоблок, разделы и элементы
 */

if (!isset($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}

include $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

spl_autoload_register(function ($class) {
    $class = str_replace('\\', '/', $class);

    $file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        include $file;
    }
});

$fromIblockId = 9; // берем инфоблок 9
$toIblockType = 'books'; // меняем ему тип. Так как инфоблока с таким ID и типом уже нет, он создается заново

$content = new Content;

if (! file_exists(__DIR__ . '/to_iblock.tmp')) {
    $structure = new Structure;
    $structure->load($fromIblockId);
    $structure->iblock['IBLOCK_TYPE_ID'] = $toIblockType;
    $structure->save();

    $newIblockId = $structure->iblock['ID']; // ID нового инфоблока

    echo 'New iblock ID: ' . $newIblockId . PHP_EOL;

    file_put_contents(__DIR__ . '/to_iblock.tmp', $newIblockId);

    $content->copySections([
        'IBLOCK_ID' => $fromIblockId,
    ], $newIblockId);

    echo 'Sections copy: success' . PHP_EOL;
} else {
    $newIblockId = file_get_contents(__DIR__ . '/to_iblock.tmp');
}

//$content->clear();
$content->copyElements([
    'IBLOCK_ID' => $fromIblockId,
], $newIblockId, 1);

include $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php';