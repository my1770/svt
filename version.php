<?php
declare(strict_types=1);

/* Версионирование (отдельно от остальной конфигурации):
   APP_VERSION — версия программы (показывается в заголовках страниц);
   SCHEMA_VERSION — версия схемы БД: при её повышении db() прогоняет
   migrate() (functions.php), где описываются инкрементальные изменения. */

define('APP_VERSION', '0.1');
define('SCHEMA_VERSION', 2);
