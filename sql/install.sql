-- your-panel.example — касса Tegro.Money, установка.
-- БД: smartpanel (контейнер mariadb).
-- Выполнять ТОЛЬКО по явному «да» владельца и ТОЛЬКО после свежего дампа БД.
--
--   docker exec -i mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" smartpanel < install.sql
--
-- Идемпотентно: повторный прогон ничего не ломает и не дублирует метод.

-- 1. Собственная таблица заказов шлюза: источник истины по ожидаемой сумме
--    и точка атомарного claim'а (одно зачисление на один платёж).
CREATE TABLE IF NOT EXISTS `tegro_orders` (
  `reference`   VARCHAR(64)   NOT NULL,
  `uid`         INT UNSIGNED  NOT NULL,
  `amount_base` DECIMAL(14,4) NOT NULL COMMENT 'сумма в валюте панели (USD) — её зачисляем',
  `amount_shop` DECIMAL(14,2) NOT NULL COMMENT 'ожидаемая сумма в валюте магазина',
  `amount_paid` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `currency`    VARCHAR(8)    NOT NULL,
  `status`      VARCHAR(16)   NOT NULL DEFAULT 'pending',
  `remote_id`   VARCHAR(64)   NOT NULL DEFAULT '',
  `created`     DATETIME      NOT NULL,
  `updated`     DATETIME      NOT NULL,
  PRIMARY KEY (`reference`),
  KEY `idx_uid` (`uid`),
  KEY `idx_status` (`status`),
  KEY `idx_remote` (`remote_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Метод оплаты. Создаётся ВЫКЛЮЧЕННЫМ (status=0) и с пустыми ключами:
--    ключи владелец вводит в админке, включение — отдельным действием после смоука.
--    Имя метода — клиентское, провайдер публично не светится.
INSERT INTO `payments` (`type`, `name`, `min`, `max`, `new_users`, `sort`, `status`, `params`)
SELECT 'tegro',
       'Карты / СБП',
       5,
       0,
       1,
       10,
       0,
       JSON_OBJECT(
         'type', 'tegro',
         'name', 'Карты / СБП',
         'min', 5,
         'max', 0,
         'new_users', 1,
         'status', 0,
         'take_fee_from_user', 0,
         'option', JSON_OBJECT(
           'shop_id', '',
           'secret_key', '',
           'api_key', '',
           'environment', 'live',
           'currency_code', 'RUB',
           'rate_to_usd', '',
           'tnx_fee', 0
         )
       )
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `payments` WHERE `type` = 'tegro');

-- Проверка после прогона:
--   SELECT id, type, name, status, sort FROM payments WHERE type='tegro';
--   SHOW CREATE TABLE tegro_orders;
