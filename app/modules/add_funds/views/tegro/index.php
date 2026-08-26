<?php
   $option           = get_value($payment_params, 'option');
   $min_amount       = get_value($payment_params, 'min');
   $max_amount       = get_value($payment_params, 'max');
   $type             = get_value($payment_params, 'type');
   $tnx_fee          = get_value($option, 'tnx_fee');
   ?>
<form class="form actionAddFundsForm" action="#" method="POST">
   <div class="mb-4 text-center">
      <p class="p-3 bg-grey-100 rounded"><small><?=sprintf(lang("you_can_deposit_funds_with_paypal_they_will_be_automaticly_added_into_your_account"), 'Карты / СБП')?></small></p>
   </div>
   <div class="form-group mb-3">
      <label><?=sprintf(lang("amount_usd"), $currency_code)?></label>
      <input class="form-control square" type="number" name="amount" placeholder="<?php echo $min_amount; ?>">
   </div>
   <div class="bg-grey-100 p-3 rounded mb-3">
      <div class="fs-14 fw-medium mb-2"><?php echo lang("note"); ?></div>
      <ul class="fs-12 list-unstyled">
         <?php
            if ($tnx_fee > 0) {
            ?>
         <li class="mb-1"><?=lang("transaction_fee")?>: <strong><?php echo $tnx_fee; ?>%</strong></li>
         <?php } ?>
         <li class="mb-1"><?=lang("Minimal_payment")?>: <strong><?php echo $currency_symbol.$min_amount; ?></strong></li>
         <?php
            if ($max_amount > 0) {
            ?>
         <li class="mb-1"><?=lang("Maximal_payment")?>: <strong><?php echo $currency_symbol.$max_amount; ?></strong></li>
         <?php } ?>
         <li><?php echo lang("clicking_return_to_shop_merchant_after_payment_successfully_completed"); ?></li>
      </ul>
   </div>
   <div class="form-check mb-4">
      <label class="d-flex flex-row">
      <input type="checkbox" class="form-check-input" name="agree" value="1">
      <span class="ms-2 w-100"><?=lang("yes_i_understand_after_the_funds_added_i_will_not_ask_fraudulent_dispute_or_chargeback")?></span>
      </label>
   </div>
   <div class="form-group">
      <input type="hidden" name="payment_id" value="<?php echo $payment_id; ?>">
      <input type="hidden" name="payment_method" value="<?php echo $type; ?>">
      <button type="submit" class="btn round btn-primary btn-min-width mr-1 mb-1">
      <?=lang("Pay")?>
      </button>
   </div>
</form>
