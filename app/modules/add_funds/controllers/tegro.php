<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Tegro.Money — приём пополнений баланса на your-panel.example (SmartPanel / CI3 HMVC).
 *
 * Флоу: redirect-only. Карточных данных движок не видит.
 *   1. Клиент выбирает метод на /add_funds → create_payment()
 *      → строка в tegro_orders (pending) + строка в general_transaction_logs (status=0)
 *      → 302 на https://tegro.money/pay/?...&sign=md5(ksort(params).secret)
 *   2. Оплата на стороне Tegro.Money.
 *   3. Tegro шлёт notify → ipn()
 *      → подпись проверяется И ЛОГИРУЕТСЯ, но гейт зачисления — НЕ она,
 *        а повторный запрос статуса заказа в API (hmac-sha256, наш api_key).
 *        Подделать ответ api.tegro.money атакующий не может.
 *      → атомарный claim (UPDATE ... WHERE status='pending') → зачисление ровно один раз.
 *      → любой сбой = HTTP 500, Tegro ретраит; ретрай ДОВОДИТ зачисление до конца
 *        (заказ, зависший в 'crediting' дольше 2 минут, забирается заново).
 *
 * Ключи живут в БД (payments.params.option), вводятся владельцем в админке.
 * В коде и в git секретов нет.
 */
class tegro extends MX_Controller
{
    const PAY_URL       = 'https://tegro.money/pay/';
    const API_ORDER_URL = 'https://tegro.money/api/order/';
    const AMOUNT_EPS    = 0.01;
    const STALE_CLAIM_SEC = 120;  // через сколько зависший 'crediting' можно доводить ретраем

    public $tb_users;
    public $tb_transaction_logs;
    public $tb_payments;
    public $tb_payments_bonuses;
    public $tb_orders;
    public $payment_type;
    public $payment_id;
    public $currency_code;      // валюта панели (USD)
    public $shop_currency;      // валюта магазина Tegro (RUB по умолчанию)
    public $rate_to_usd;        // сколько единиц валюты магазина в 1 USD
    public $payment_fee;
    public $take_fee_from_user;
    public $shop_id;
    public $secret_key;
    public $api_key;
    public $mode;

    public function __construct($payment = "")
    {
        parent::__construct();
        $this->load->model('add_funds_model', 'model');

        $this->tb_users            = USERS;
        $this->tb_transaction_logs = TRANSACTION_LOGS;
        $this->tb_payments         = PAYMENTS_METHOD;
        $this->tb_payments_bonuses = PAYMENTS_BONUSES;
        $this->tb_orders           = 'tegro_orders';
        if (!$this->payment_type) {
            $this->payment_type = 'tegro'; // подклассы (второй метод на том же шлюзе) задают свой type до parent::__construct
        }

        $this->currency_code = get_option("currency_code", "USD");
        if ($this->currency_code == "") {
            $this->currency_code = 'USD';
        }

        if (!$payment) {
            $payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
        }
        if (!$payment) {
            return;
        }

        $this->payment_id         = $payment->id;
        $params                   = $payment->params;
        $option                   = get_value($params, 'option');
        $this->take_fee_from_user = get_value($params, 'take_fee_from_user');
        $this->payment_fee        = (double) get_value($option, 'tnx_fee');
        $this->shop_id            = trim((string) get_value($option, 'shop_id'));
        $this->secret_key         = (string) get_value($option, 'secret_key');
        $this->api_key            = (string) get_value($option, 'api_key');
        $this->mode               = get_value($option, 'environment') ?: 'live';

        $this->shop_currency = strtoupper((string) get_value($option, 'currency_code'));
        if (!$this->shop_currency) {
            $this->shop_currency = 'RUB';
        }
        $this->rate_to_usd = (double) get_value($option, 'rate_to_usd');
    }

    public function index()
    {
        redirect(cn("add_funds"));
    }

    /* ------------------------------------------------------------------ */
    /*  Шаг 1 — создание платежа                                           */
    /* ------------------------------------------------------------------ */

    public function create_payment($data_payment = "")
    {
        _is_ajax($data_payment['module']);

        $amount = (double) $data_payment['amount'];
        if ($amount <= 0) {
            _validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
        }

        // fail-closed: без полного комплекта ключей метод не работает
        if (!$this->shop_id || !$this->secret_key || !$this->api_key) {
            _validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail'));
        }

        $amount_shop = $this->to_shop_currency($amount);
        if ($amount_shop === null) {
            _validation('error', lang('this_payment_is_not_active_please_choose_another_payment_or_contact_us_for_more_detail'));
        }

        $uid       = (int) session('uid');
        $reference = $this->new_reference($uid);

        // durable-запись до редиректа: она источник истины по ожидаемой сумме
        // Обе записи обязаны лечь ДО выдачи платёжной ссылки: иначе клиент заплатит
        // по заказу, которого у нас нет. Транзакцией не обернуть — general_transaction_logs
        // это MyISAM. Поэтому проверяем каждый insert и подчищаем за собой.
        $ok_order = $this->db->insert($this->tb_orders, [
            'reference'   => $reference,
            'uid'         => $uid,
            'amount_base' => $amount,
            'amount_shop' => $amount_shop,
            'currency'    => $this->shop_currency,
            'status'      => 'pending',
            'created'     => NOW,
            'updated'     => NOW,
        ]);
        if (!$ok_order) {
            $this->log_event('error', 'cannot insert order row', $reference);
            _validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
        }

        $ok_tnx = $this->db->insert($this->tb_transaction_logs, [
            'ids'            => ids(),
            'uid'            => $uid,
            'type'           => $this->payment_type,
            'transaction_id' => $reference,
            'amount'         => $amount,
            'txn_fee'        => 0,
            'status'         => 0,
            'created'        => NOW,
        ]);
        if (!$ok_tnx) {
            $this->db->delete($this->tb_orders, ['reference' => $reference, 'status' => 'pending']);
            $this->log_event('error', 'cannot insert transaction row', $reference);
            _validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
        }

        $sign_data = [
            'shop_id'  => $this->shop_id,
            'amount'   => $this->fmt($amount_shop),
            'currency' => $this->shop_currency,
            'order_id' => $reference,
        ];
        if ($this->mode === 'sandbox') {
            $sign_data['test'] = '1';
        }
        ksort($sign_data);
        $sign = md5(http_build_query($sign_data) . $this->secret_key);

        $query = array_merge($sign_data, [
            'sign'        => $sign,
            'lang'        => (strtolower((string) get_option('language', 'ru')) === 'en') ? 'en' : 'ru',
            'success_url' => cn('add_funds/success'),
            'fail_url'    => cn('add_funds/unsuccess'),
            'notify_url'  => cn($this->payment_type . '_ipn'),
            // состав заказа: у магазина может быть включена обязательная передача чека,
            // без него провайдер отклоняет платёж («Не указан обязательный параметр receipt»).
            // В подпись receipt не входит; сумма позиций обязана равняться amount.
            'receipt'     => ['items' => [[
                'name'  => 'Пополнение баланса',
                'count' => 1,
                'price' => $this->fmt($amount_shop),
            ]]],
        ]);

        // e-mail пользователя — чтобы форма провайдера не спрашивала его заново (fields[email], вне подписи)
        $user_row = $this->db->select('email')->get_where($this->tb_users, ['id' => $uid])->row();
        if ($user_row && filter_var((string) $user_row->email, FILTER_VALIDATE_EMAIL)) {
            $query['fields'] = ['email' => (string) $user_row->email];
        }

        $redirect_url = self::PAY_URL . '?' . http_build_query($query);

        if ($this->input->is_ajax_request()) {
            ms(['status' => 'success', 'redirect_url' => $redirect_url]);
        }
        redirect($redirect_url);
    }

    /* ------------------------------------------------------------------ */
    /*  Шаг 2 — уведомление об оплате                                      */
    /* ------------------------------------------------------------------ */

    public function ipn()
    {
        // Сырой $_POST, без xss_clean: фильтр движка меняет строки и ломает подпись.
        // В запросы значения уходят только через active record (плейсхолдеры).
        $post = $_POST;
        if (!is_array($post) || !$post) {
            $this->fail('empty payload', 400);
        }

        $reference = isset($post['order_id']) ? (string) $post['order_id'] : '';
        if ($reference === '') {
            $this->fail('no order_id', 400);
        }

        if (!$this->shop_id || !$this->secret_key || !$this->api_key) {
            $this->fail('gateway not configured: ' . $reference, 500);
        }

        // Подпись хука: проверяем и логируем, но зачисление на ней НЕ держим.
        $sign_ok = $this->verify_hook_sign($post);
        if (!$sign_ok) {
            $this->log_event('warn', 'bad hook sign', $reference);
        }

        $order = $this->model->get('*', $this->tb_orders, ['reference' => $reference]);
        if (!$order) {
            $this->fail('unknown reference: ' . $reference, 404);
        }

        if ($order->status === 'paid') {
            // повторный хук по уже зачисленному платежу — норма, отвечаем 200
            echo 'OK';
            return;
        }

        // Гейт зачисления: подписанный запрос статуса в API Tegro.
        $remote = $this->api_order_status($reference);
        if (!$remote) {
            $this->fail('api re-check failed: ' . $reference, 500);
        }

        if ((int) $remote->status !== 1) {
            $this->log_event('info', 'order not paid yet (status=' . $remote->status . ')', $reference);
            echo 'OK';
            return;
        }

        if ($this->mode !== 'sandbox' && !empty($remote->test_order)) {
            $this->fail('test order on live gateway: ' . $reference, 403);
        }

        if (!empty($post['currency']) && strtoupper((string) $post['currency']) !== strtoupper($order->currency)) {
            $this->log_event('warn', 'currency in hook ' . $post['currency'] . ' != order ' . $order->currency, $reference);
        }

        // amount из API — сумма заказа, fee — комиссия провайдера (в ответе отдельным полем).
        // Логируем оба: на боевом смоуке сразу видно фактическую семантику полей.
        $paid = (double) $remote->amount;
        $remote_fee = isset($remote->fee) ? (double) $remote->fee : 0;
        if ($paid + self::AMOUNT_EPS < (double) $order->amount_shop) {
            $this->log_event('warn', 'underpay: api amount=' . $paid . ' fee=' . $remote_fee
                . ' expected=' . $order->amount_shop . ' ' . $order->currency, $reference);
            $this->fail('amount mismatch: ' . $reference, 409);
        }

        // Claim работы. Транзакции невозможны в принципе: и general_users, и
        // general_transaction_logs в этой сборке — MyISAM. Поэтому единственная точка
        // сериализации — наша InnoDB-таблица: атомарный UPDATE ... WHERE.
        //
        // Берём заказ либо свежим ('pending'), либо ПРОТУХШИМ ('crediting' старше 2 минут —
        // значит предыдущая попытка оборвалась). Это и есть докат: ретрай провайдера
        // доводит зачисление до конца вместо того, чтобы платёж завис навсегда.
        // Параллельный второй хук получит 0 строк и уйдёт с 500 — вернётся ретраем позже.
        $this->db->where('reference', $reference)
            ->group_start()
                ->where('status', 'pending')
                ->or_group_start()
                    ->where('status', 'crediting')
                    ->where('updated <', date('Y-m-d H:i:s', time() - self::STALE_CLAIM_SEC))
                ->group_end()
            ->group_end()
            ->update($this->tb_orders, [
                'status'      => 'crediting',
                'remote_id'   => (string) $remote->id,
                'amount_paid' => $paid,
                'updated'     => NOW,
            ]);

        if ((int) $this->db->affected_rows() !== 1) {
            // заказ прямо сейчас обрабатывает другой процесс — пусть провайдер повторит
            $this->fail('claim busy: ' . $reference, 500);
        }

        $transaction = $this->model->get('*', $this->tb_transaction_logs, [
            'transaction_id' => $reference,
            'type'           => $this->payment_type,
        ]);
        if (!$transaction) {
            $this->fail('no transaction row: ' . $reference, 500);
        }

        // Комиссия зажата в [0, 90] %: кривая настройка не должна давать
        // отрицательное начисление (amount - txn_fee) и списывать баланс клиента.
        $fee_pct = min(max((double) $this->payment_fee, 0), 90);
        $fee = ($this->take_fee_from_user && $fee_pct > 0)
            ? round(((double) $transaction->amount) * ($fee_pct / 100), 4)
            : 0;

        $this->db->update($this->tb_transaction_logs, [
            'status'  => 1,
            'txn_fee' => $fee,
        ], ['id' => $transaction->id, 'status' => 0]);

        if ((int) $this->db->affected_rows() !== 1) {
            // Журнал уже закрыт предыдущей попыткой. Значит она дошла как минимум сюда,
            // и повторное начисление было бы двойным кредитом — баланс не трогаем,
            // только закрываем заказ. Узкое окно (kill ровно между этими двумя запросами)
            // остаётся ручным: строка попадёт в лог как REVIEW.
            $this->log_event('error', 'REVIEW: журнал закрыт, зачисление не подтверждено — проверить баланс', $reference);
            $this->db->update($this->tb_orders, ['status' => 'paid', 'updated' => NOW], ['reference' => $reference]);
            echo 'OK';
            return;
        }

        $transaction->txn_fee = $fee;
        $this->model->add_funds_bonus_email($transaction, $this->payment_id);

        // Закрываем заказ только после фактического зачисления.
        $this->db->update($this->tb_orders, ['status' => 'paid', 'updated' => NOW], ['reference' => $reference]);

        $this->log_event('info', 'credited ' . $transaction->amount . ' ' . $this->currency_code, $reference);
        echo 'OK';
    }

    /* ------------------------------------------------------------------ */
    /*  Внутреннее                                                         */
    /* ------------------------------------------------------------------ */

    private function to_shop_currency($amount)
    {
        if ($this->shop_currency === $this->currency_code) {
            return round($amount, 2);
        }
        if ($this->rate_to_usd <= 0) {
            return null;
        }
        return round($amount * $this->rate_to_usd, 2);
    }

    private function new_reference($uid)
    {
        return 'SMMP-' . $uid . '-' . strtoupper(bin2hex(random_bytes(6)));
    }

    private function fmt($amount)
    {
        return number_format((double) $amount, 2, '.', '');
    }

    private function verify_hook_sign($post)
    {
        if (empty($post['sign'])) {
            return false;
        }
        $given = (string) $post['sign'];
        $data  = $post;
        unset($data['sign']);
        ksort($data);
        $expected = md5(http_build_query($data) . $this->secret_key);
        return hash_equals($expected, $given);
    }

    /**
     * Источник истины по оплате: POST /api/order/ с подписью тела (hmac-sha256, api_key).
     * Возвращает объект data или false.
     */
    private function api_order_status($reference)
    {
        $body = json_encode([
            'shop_id'    => $this->shop_id,
            'nonce'      => time() . random_int(100, 999),
            'payment_id' => $reference,
        ]);
        $sign = hash_hmac('sha256', $body, $this->api_key);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::API_ORDER_URL,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $sign,
                'Content-Type: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            $this->log_event('error', 'api http ' . $code . ' ' . $err, $reference);
            return false;
        }

        $json = json_decode($raw);
        if (!$json || !isset($json->type) || $json->type !== 'success' || !isset($json->data)) {
            $this->log_event('error', 'api bad response: ' . substr($raw, 0, 300), $reference);
            return false;
        }
        return $json->data;
    }

    private function log_event($level, $message, $reference = '')
    {
        log_message($level === 'warn' ? 'error' : $level, '[tegro] ' . $message . ($reference ? ' ref=' . $reference : ''));
    }

    private function fail($message, $code)
    {
        $this->log_event('error', $message);
        $this->output->set_status_header($code);
        echo 'ERROR';
        exit;
    }
}
