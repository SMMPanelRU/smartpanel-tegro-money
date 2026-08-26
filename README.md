# SmartPanel — Tegro.Money

Модуль оплаты **Tegro.Money** (tegro.money) для SmartPanel (CodeCanyon SmartPanel v4.x, CodeIgniter 3 HMVC, PHP 7.4+). Добавляет карты/СБП-пополнения баланса пользователям SMM-панели.

Ниже — русская версия (основная аудитория проекта), затем полная версия на английском.

## Возможности

- Пополнение баланса картой / через СБП через платёжную страницу Tegro.Money.
- Зачисление проверяется не подписью вебхука, а повторным запросом статуса заказа в API провайдера — подделать вебхук недостаточно, чтобы получить деньги.
- Атомарный claim в отдельной InnoDB-таблице — при повторных вебхуках зачисление происходит ровно один раз.
- Fail-closed: без полного набора ключей метод не активируется.
- Никаких секретов в коде или в git — ключи вводятся в админке и хранятся в БД панели.

## Установка

1. Скопируйте два PHP-файла в те же пути внутри корня панели (без изменения ядра):
   ```
   app/modules/add_funds/controllers/tegro.php
   app/modules/admin/views/payments/integrations/tegro.php
   ```
   Роут `/tegro_ipn` уже обрабатывается движком через общее правило `$route['(:any)_ipn']` — правки `routes.php` не требуются.

2. Выполните `sql/install.sql` на базе данных панели (предварительно сделайте бэкап БД). Скрипт идемпотентен — повторный запуск ничего не ломает и не дублирует метод оплаты.

3. В админке: **Settings → Payments → «Карты / СБП»** — укажите:
   - Shop ID, Secret Key, API Key (из личного кабинета вашего магазина tegro.money);
   - Environment (`live` для боевого режима);
   - Currency code — валюта магазина (например, RUB);
   - Currency rate — сколько единиц валюты магазина в 1 единице валюты баланса панели.

4. В настройках магазина tegro.money укажите:
   - Notify URL: `https://<your-panel>/tegro_ipn`
   - Success URL: `https://<your-panel>/add_funds/success`
   - Fail URL: `https://<your-panel>/add_funds/unsuccess`

   Магазин должен быть активен.

5. Включите метод оплаты, сделайте минимальное боевое пополнение, убедитесь, что баланс зачислился — после этого модуль готов к продакшену.

## Требования

- SmartPanel v4.x (CodeIgniter 3 HMVC)
- PHP 7.4+
- MySQL/MariaDB с поддержкой JSON-функций (MySQL 5.7+ / MariaDB 10.2+)
- Домен панели по HTTPS
- Активный магазин (shop) в tegro.money

## Безопасность денежного пути

Это главная причина, по которой стоит использовать именно этот модуль, а не писать интеграцию с нуля.

- **Гейт зачисления — не подпись вебхука.** Подпись `notify`-запроса проверяется и логируется, но деньги начисляются только после отдельного подписанного (hmac-sha256, API Key) запроса `POST https://tegro.money/api/order/`, подтверждающего статус заказа. Подделать этот ответ атакующий не может.
- **Атомарный claim.** Заявка на зачисление берётся ровно один раз через `UPDATE ... WHERE status='pending' OR (status='crediting' AND updated < NOW() - 120s)` в таблице `tegro_orders` (InnoDB). Параллельные повторные вебхуки не приводят к двойному зачислению; прерванная на середине попытка донабирается следующим ретраем провайдера.
- **Ожидаемая сумма фиксируется заранее.** Она записывается в `tegro_orders` до редиректа на оплату; если оплаченная сумма меньше ожидаемой — HTTP 409, зачисления нет.
- **Fail-closed.** Если хотя бы один из ключей (Shop ID / Secret Key / API Key) не заполнен, метод недоступен для оплаты, а вебхук отвечает 500.
- **Семантика ретраев.** Любая внутренняя ошибка — HTTP 500 (провайдер повторит попытку). HTTP 200 возвращается только когда платёж зачислен, уже был зачислен ранее, либо ещё не оплачен.
- **Тестовые заказы.** Заказ с флагом `test_order=1`, пришедший на боевом (`live`) окружении, отклоняется с HTTP 403.
- **Секретов в коде нет.** Ключи хранятся только в БД панели и вводятся владельцем через админку.

### Мониторинг

Запрос для поиска зависших заказов (не добрались до `paid`):

```sql
SELECT reference, uid, amount_base, amount_shop, status, created
FROM tegro_orders
WHERE status = 'crediting' OR (status = 'pending' AND created < NOW() - INTERVAL 1 DAY);
```

### Откат

Отключите метод оплаты в админке. Файлы модуля и таблицу `tegro_orders` можно оставить — само по себе их наличие ни на что не влияет, пока метод выключен.

## Лицензия

MIT — см. [LICENSE](LICENSE).

## Участие в разработке

См. [CONTRIBUTING.md](CONTRIBUTING.md).

---

# English

Tegro.Money payment gateway module for SmartPanel (CodeCanyon SmartPanel v4.x, CodeIgniter 3 HMVC, PHP 7.4+). Adds card / SBP (Russia's Faster Payments System) balance top-ups to an SMM panel.

## Features

- Card / SBP top-ups via the Tegro.Money hosted payment page.
- Credit is gated by a server-to-server API re-check of the order status, not by webhook signature — forging the webhook alone cannot move money.
- Atomic claim in a dedicated InnoDB table guarantees exactly one credit per payment even under concurrent webhook retries.
- Fail-closed: the method is inactive until a full set of API keys is configured.
- No secrets in code or git — keys are entered via the admin UI and stored in the panel's database.

## Installation

1. Copy the two PHP files into the same paths inside your panel webroot (no core files are touched):
   ```
   app/modules/add_funds/controllers/tegro.php
   app/modules/admin/views/payments/integrations/tegro.php
   ```
   The engine's generic route `$route['(:any)_ipn']` already maps `/tegro_ipn` to this controller — no `routes.php` edits needed.

2. Run `sql/install.sql` against your panel database (take a DB backup first). The script is idempotent — safe to re-run.

3. In the admin panel: **Settings → Payments → "Карты / СБП"** ("Cards / SBP") — fill in:
   - Shop ID, Secret Key, API Key (from your tegro.money merchant dashboard);
   - Environment (`live` for production);
   - Currency code — your shop's settlement currency (e.g. RUB);
   - Currency rate — units of shop currency per 1 unit of the panel's balance currency.

4. In your tegro.money shop settings, set:
   - Notify URL: `https://<your-panel>/tegro_ipn`
   - Success URL: `https://<your-panel>/add_funds/success`
   - Fail URL: `https://<your-panel>/add_funds/unsuccess`

   The shop must be active.

5. Enable the payment method, run a minimal live top-up, and confirm the balance is credited. It is then ready for production traffic.

## Requirements

- SmartPanel v4.x (CodeIgniter 3 HMVC)
- PHP 7.4+
- MySQL/MariaDB with JSON function support (MySQL 5.7+ / MariaDB 10.2+)
- Panel domain served over HTTPS
- An active tegro.money merchant shop

## Money-path safety design

This is the reason to use this module instead of a from-scratch integration.

- **The credit gate is not the webhook signature.** The `notify` request's signature is verified and logged, but a payment is only credited after a separate signed (hmac-sha256, API Key) `POST https://tegro.money/api/order/` request confirms the order's status server-to-server. An attacker cannot forge that response.
- **Atomic claim.** A payment is claimed exactly once via `UPDATE ... WHERE status='pending' OR (status='crediting' AND updated < NOW() - 120s)` against the `tegro_orders` table (InnoDB). Concurrent duplicate webhooks cannot double-credit; an attempt interrupted mid-flight is completed by the provider's next retry.
- **Expected amount is fixed upfront.** It is written to `tegro_orders` before the customer is redirected to pay. If the confirmed paid amount is less than expected, the webhook returns HTTP 409 and nothing is credited.
- **Fail-closed.** If any of Shop ID / Secret Key / API Key is missing, the method is unavailable to customers and the webhook responds with HTTP 500.
- **Retry semantics.** Any internal failure returns HTTP 500 so the provider retries. HTTP 200 is returned only when the payment is credited, was already credited, or is not yet paid.
- **Test orders.** An order flagged `test_order=1` received on the `live` environment is rejected with HTTP 403.
- **No secrets in code.** Keys live only in the panel's database, entered by the owner through the admin UI.

### Monitoring

Query to find orders stuck before reaching `paid`:

```sql
SELECT reference, uid, amount_base, amount_shop, status, created
FROM tegro_orders
WHERE status = 'crediting' OR (status = 'pending' AND created < NOW() - INTERVAL 1 DAY);
```

### Rollback

Disable the payment method in the admin panel. The module files and the `tegro_orders` table can stay in place — they have no effect while the method is disabled.

## License

MIT — see [LICENSE](LICENSE).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
