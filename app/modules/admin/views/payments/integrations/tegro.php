<?php
  $form_payment_curreyncy_codes = [
    'RUB' => "RUB - Российский рубль",
    'USD' => "USD - US Dollar",
    'EUR' => "EUR - Euro",
  ];
  $form_environment_modes = [
    'live'    => "Live (боевой)",
    'sandbox' => "Sandbox (test=1)",
  ];
  $payment_elements = [
    [
      'label'      => form_label('Shop ID'),
      'element'    => form_input(['name' => "payment_params[option][shop_id]", 'value' => @$payment_option->shop_id, 'type' => 'text', 'class' => $class_element]),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
    ],
    [
      'label'      => form_label('Secret Key (подпись формы и хука)'),
      'element'    => form_input(['name' => "payment_params[option][secret_key]", 'value' => @$payment_option->secret_key, 'type' => 'text', 'class' => $class_element]),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
    ],
    [
      'label'      => form_label('API Key (проверка статуса заказа)'),
      'element'    => form_input(['name' => "payment_params[option][api_key]", 'value' => @$payment_option->api_key, 'type' => 'text', 'class' => $class_element]),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
    ],
    [
      'label'      => form_label('Mode'),
      'element'    => form_dropdown('payment_params[option][environment]', $form_environment_modes, @$payment_option->environment, ['class' => $class_element]),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
    ],
    [
      'label'      => form_label('Currency code'),
      'element'    => form_dropdown('payment_params[option][currency_code]', $form_payment_curreyncy_codes, @$payment_option->currency_code, ['class' => $class_element . ' ajaxChangeCurrencyCode']),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
    ],
    [
      'label'      => form_label('Currency rate'),
      'element'    => form_input(['name' => "payment_params[option][rate_to_usd]", 'value' => @$payment_option->rate_to_usd, 'type' => 'text', 'class' => $class_element . ' text-right']),
      'class_main' => "col-md-12 col-sm-12 col-xs-12",
      'type'       => "exchange_option",
      'item1'      => ['name' => get_option('currency_code', 'USD'), 'value' => 1],
      'item2'      => ['name' => @$payment_option->currency_code, 'value' => @$payment_option->rate_to_usd],
    ],
  ];
  echo render_elements_form($payment_elements);
?>

<div class="form-group">
  <label class="form-label"><strong>Настройка магазина</strong></label>
  <ul>
    <li>Открыть <strong>настройки магазина</strong> в кабинете провайдера.</li>
    <li>Поле <strong>URL уведомлений (notify)</strong>: <code class="text-info"><?php echo cn('tegro_ipn'); ?></code></li>
    <li>Поле <strong>Success URL</strong>: <code class="text-info"><?php echo cn('add_funds/success'); ?></code></li>
    <li>Поле <strong>Fail URL</strong>: <code class="text-info"><?php echo cn('add_funds/unsuccess'); ?></code></li>
    <li>Скопировать <strong>Shop ID</strong> и <strong>секретный ключ</strong> в поля выше.</li>
    <li>Сгенерировать <strong>API-ключ</strong> на той же странице и вставить в поле API Key — без него зачисление не работает: платёж подтверждается повторным запросом статуса, а не подписью уведомления.</li>
    <li><strong>Currency rate</strong> — сколько единиц валюты магазина в 1 <?php echo get_option('currency_code', 'USD'); ?>. Клиент видит сумму в <?php echo get_option('currency_code', 'USD'); ?>, платит в валюте магазина.</li>
  </ul>
</div>
