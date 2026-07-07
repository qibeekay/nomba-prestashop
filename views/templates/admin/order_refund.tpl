{* Nomba Refund Panel - User selects refund method *}
<div class="panel" id="nomba-refund-panel">
  <div class="panel-heading">
    <i class="icon-undo"></i> {l s='Nomba Refund' mod='nomba'}
  </div>

  <style>
    .nomba-refund-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; padding: 10px 0; }
    .nomba-badge { display: inline-block; padding: 4px 10px; font-size: 11px; font-weight: 600; border-radius: 4px; margin-bottom: 10px; }
    .nomba-badge-success { background-color: #d4edda; color: #155724; }
    .nomba-badge-warning { background-color: #fff3cd; color: #856404; }
    .nomba-badge-danger { background-color: #f8d7da; color: #721c24; }
    .nomba-refund-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; }
    .nomba-grid-item { display: flex; flex-direction: column; }
    .nomba-grid-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; margin-bottom: 4px; }
    .nomba-grid-val { font-size: 15px; font-weight: 600; color: #212529; }
    .nomba-method-card { border: 2px solid #e9ecef; border-radius: 8px; padding: 16px; cursor: pointer; transition: all 0.2s; position: relative; }
    .nomba-method-card:hover { border-color: #adb5bd; }
    .nomba-method-card.selected { border-color: #2563eb; background: #f0f7ff; }
    .nomba-method-card.disabled { opacity: 0.5; cursor: not-allowed; }
    .nomba-method-radio { position: absolute; top: 12px; right: 12px; width: 20px; height: 20px; accent-color: #2563eb; }
    .nomba-method-title { font-weight: 700; font-size: 15px; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
    .nomba-method-desc { font-size: 13px; color: #6c757d; line-height: 1.5; }
    .nomba-method-meta { margin-top: 10px; font-size: 12px; }
    .nomba-method-meta .tag { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; margin-right: 6px; }
    .tag-green { background: #d4edda; color: #155724; }
    .tag-orange { background: #fff3cd; color: #856404; }
    .tag-blue { background: #cfe2ff; color: #084298; }
    .nomba-form-group { margin-bottom: 18px; }
    .nomba-form-group label { font-weight: 600; margin-bottom: 6px; display: block; color: #323232; }
    .nomba-input-wrapper { position: relative; max-width: 300px; }
    .nomba-input-prefix { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #495057; font-weight: bold; }
    .nomba-input-field { width: 100%; padding: 8px 10px 8px 28px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
    .nomba-input-field:focus { border-color: #2563eb; outline: 0; }
    .nomba-bank-fields { background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-top: 10px; }
    .nomba-bank-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .nomba-bank-row input { width: 100%; padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
    .nomba-btn-submit { background-color: #dc3545; color: white !important; border: none; padding: 10px 20px; font-size: 14px; font-weight: 600; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
    .nomba-btn-submit:hover { background-color: #bd2130; }
    .nomba-btn-submit:disabled { background-color: #e2e8f0; color: #94a3b8 !important; cursor: not-allowed; }
    .nomba-method-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
    @media (max-width: 768px) { .nomba-method-grid { grid-template-columns: 1fr; } }
    .nomba-help-text { font-size: 12px; color: #6c757d; margin-top: 4px; }
    .nomba-alert-box { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; }
    .nomba-alert-info { background: #e7f3ff; border: 1px solid #b6d4fe; color: #084298; }
    .nomba-alert-warn { background: #fff3cd; border: 1px solid #ffecb5; color: #856404; }
  </style>

  <div class="panel-body nomba-refund-container">
    {if $status === 'refunded' || $amountRemaining <= 0}
      <div class="alert alert-success" style="margin: 0;">
        <p><i class="icon-check-circle" style="font-size: 16px;"></i> <strong>{l s='Fully Refunded' mod='nomba'}</strong></p>
        <p style="margin-top: 5px;">{l s='This payment has been fully refunded. No further refund actions are available.' mod='nomba'}</p>
        <div style="margin-top: 10px; font-size: 13px;">
          <strong>{l s='Total Paid:' mod='nomba'}</strong> {displayPrice price=$orderTotal}<br/>
          <strong>{l s='Total Refunded:' mod='nomba'}</strong> {displayPrice price=$amountRefunded}
        </div>
      </div>
    {else}
      {if $amountRefunded > 0}
        <div class="nomba-badge nomba-badge-warning">
          <i class="icon-exclamation-triangle"></i> {l s='Partially Refunded' mod='nomba'} — {displayPrice price=$amountRefunded} {l s='refunded so far' mod='nomba'}
        </div>
      {else}
        <div class="nomba-badge nomba-badge-success">
          <i class="icon-check"></i> {l s='Payment Completed' mod='nomba'}
        </div>
      {/if}

      <div class="nomba-refund-grid">
        <div class="nomba-grid-item">
          <span class="nomba-grid-label">{l s='Transaction Reference' mod='nomba'}</span>
          <span class="nomba-grid-val" style="font-size:12px; word-break:break-all;">{$transactionRef|escape:'html':'UTF-8'}</span>
        </div>
        <div class="nomba-grid-item">
          <span class="nomba-grid-label">{l s='Total Paid' mod='nomba'}</span>
          <span class="nomba-grid-val">{displayPrice price=$orderTotal}</span>
        </div>
        <div class="nomba-grid-item">
          <span class="nomba-grid-label">{l s='Refunded So Far' mod='nomba'}</span>
          <span class="nomba-grid-val" style="color: #dc3545;">{displayPrice price=$amountRefunded}</span>
        </div>
        <div class="nomba-grid-item">
          <span class="nomba-grid-label">{l s='Remaining Refundable' mod='nomba'}</span>
          <span class="nomba-grid-val" style="color: #198754;">{displayPrice price=$amountRemaining}</span>
        </div>
      </div>

      <form action="{$refundUrl|escape:'html':'UTF-8'}" method="POST" id="nomba-refund-form">
        <input type="hidden" name="submitNombaRefund" value="1" />

        <!-- Refund Type: Full vs Partial -->
        <div class="nomba-form-group">
          <label>{l s='Refund Amount' mod='nomba'}</label>
          <div class="nomba-radio-group" style="display: flex; gap: 20px;">
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal;">
              <input type="radio" name="refund_type" value="full" checked="checked" id="nomba-refund-type-full" />
              <strong>{l s='Full Refund' mod='nomba'}</strong> — {displayPrice price=$amountRemaining}
            </label>
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal;">
              <input type="radio" name="refund_type" value="partial" id="nomba-refund-type-partial" />
              <strong>{l s='Partial Refund' mod='nomba'}</strong>
            </label>
          </div>
        </div>

        <!-- Partial Amount Input -->
        <div class="nomba-form-group" id="nomba-amount-group" style="display: none;">
          <label for="refund_amount">{l s='Refund Amount' mod='nomba'}</label>
          <div class="nomba-input-wrapper">
            <span class="nomba-input-prefix">₦</span>
            <input type="number" step="0.01" min="0.01" max="{$amountRemaining|escape:'html':'UTF-8'}"
                   name="refund_amount" id="nomba_refund_amount" class="nomba-input-field"
                   value="{$amountRemaining|escape:'html':'UTF-8'}" />
          </div>
          <p class="nomba-help-text">{l s='Enter amount ≤' mod='nomba'} {displayPrice price=$amountRemaining}</p>
        </div>

        <!-- Refund Method Selection -->
        <div class="nomba-form-group">
          <label>{l s='Refund Method' mod='nomba'}</label>

          {if $has_bank_details}
            <div class="nomba-alert-box nomba-alert-info">
              <i class="icon-info-circle"></i>
              {l s='Customer paid via bank transfer. Bank transfer refund is recommended for instant processing.' mod='nomba'}
            </div>
          {/if}

          <div class="nomba-method-grid">
            <!-- Native Refund Card -->
            <div class="nomba-method-card" id="card-native" onclick="selectMethod('native')">
              <input type="radio" name="refund_method" value="native" class="nomba-method-radio" id="method-native" />
              <div class="nomba-method-title">
                <i class="icon-credit-card" style="color: #2563eb;"></i>
                {l s='Card Reversal' mod='nomba'}
              </div>
              <div class="nomba-method-desc">
               {l s='Refund back to the customer\'s original card or payment method. Processed by Nomba automatically.' mod='nomba'}
              </div>
              <div class="nomba-method-meta">
                <span class="tag tag-orange">{l s='T+7 days' mod='nomba'}</span>
                <span class="tag tag-blue">{l s='No bank details needed' mod='nomba'}</span>
              </div>
            </div>

            <!-- Bank Transfer Refund Card -->
            <div class="nomba-method-card" id="card-transfer" onclick="selectMethod('transfer')">
              <input type="radio" name="refund_method" value="transfer" class="nomba-method-radio" id="method-transfer" />
              <div class="nomba-method-title">
                <i class="icon-bank" style="color: #198754;"></i>
                {l s='Bank Transfer' mod='nomba'}
              </div>
              <div class="nomba-method-desc">
                {l s='Send refund directly to customer\'s bank account. Faster and more reliable, especially for bank transfer payments.' mod='nomba'}
              </div>
              <div class="nomba-method-meta">
                <span class="tag tag-green">{l s='Instant' mod='nomba'}</span>
                <span class="tag tag-orange">{l s='Requires bank details' mod='nomba'}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Bank Details Fields (shown when transfer selected) -->
        <div class="nomba-bank-fields" id="nomba-bank-fields-wrapper" style="display: none;">
          <div style="font-weight: 600; margin-bottom: 12px; font-size: 14px;">
            <i class="icon-bank"></i> {l s='Recipient Bank Details' mod='nomba'}
          </div>
          <div class="nomba-bank-row">
            <div class="nomba-form-group" style="margin-bottom: 0;">
              <label for="account_number">{l s='Account Number' mod='nomba'} <span style="color: #dc3545;">*</span></label>
              <input type="text" name="account_number" id="nomba_account_number"
                     placeholder="e.g. 0123456789" value="{$accountNumber|escape:'html':'UTF-8'}" />
            </div>
            <div class="nomba-form-group" style="margin-bottom: 0;">
              <label for="bank_code">{l s='Bank Code' mod='nomba'} <span style="color: #dc3545;">*</span></label>
              <input type="text" name="bank_code" id="nomba_bank_code"
                     placeholder="e.g. 058" value="{$bankCode|escape:'html':'UTF-8'}" />
            </div>
          </div>
          <div class="nomba-bank-row" style="margin-top: 15px;">
            <div class="nomba-form-group" style="margin-bottom: 0;">
              <label for="account_name">{l s='Account Name' mod='nomba'} <span style="color: #dc3545;">*</span></label>
              <input type="text" name="account_name" id="nomba_account_name"
                     placeholder="e.g. John Doe" value="{$accountName|escape:'html':'UTF-8'}" />
            </div>
          </div>
          {if $has_bank_details}
            <p class="help-block" style="margin-top: 10px; color: #198754; font-weight: 600;">
              <i class="icon-info-circle"></i> {l s='Pre-filled from original payment.' mod='nomba'}
            </p>
          {/if}
        </div>

        <!-- Submit Button -->
        <div style="margin-top: 25px;">
          <button type="submit" class="nomba-btn-submit" id="nomba-submit-btn">
            <i class="icon-undo"></i> <span id="btn-text">{l s='Process Refund' mod='nomba'}</span>
          </button>
        </div>
      </form>

      <script>
        document.addEventListener('DOMContentLoaded', function() {
          var typeFull = document.getElementById('nomba-refund-type-full');
          var typePartial = document.getElementById('nomba-refund-type-partial');
          var amountGroup = document.getElementById('nomba-amount-group');
          var amountField = document.getElementById('nomba_refund_amount');
          var methodNative = document.getElementById('method-native');
          var methodTransfer = document.getElementById('method-transfer');
          var cardNative = document.getElementById('card-native');
          var cardTransfer = document.getElementById('card-transfer');
          var bankFields = document.getElementById('nomba-bank-fields-wrapper');
          var acctField = document.getElementById('nomba_account_number');
          var bankCodeField = document.getElementById('nomba_bank_code');
          var acctNameField = document.getElementById('nomba_account_name');
          var form = document.getElementById('nomba-refund-form');
          var submitBtn = document.getElementById('nomba-submit-btn');
          var btnText = document.getElementById('btn-text');
          var maxRefundable = parseFloat("{$amountRemaining}");

          // Toggle amount input
          function toggleAmountInput() {
            if (typePartial.checked) {
              amountGroup.style.display = 'block';
              amountField.focus();
            } else {
              amountGroup.style.display = 'none';
              amountField.value = maxRefundable.toFixed(2);
            }
          }

          // Select refund method
          window.selectMethod = function(method) {
            if (method === 'native') {
              methodNative.checked = true;
              cardNative.classList.add('selected');
              cardTransfer.classList.remove('selected');
              bankFields.style.display = 'none';
              acctField.removeAttribute('required');
              bankCodeField.removeAttribute('required');
              acctNameField.removeAttribute('required');
              btnText.textContent = "{l s='Process Card Reversal' mod='nomba'}";
            } else {
              methodTransfer.checked = true;
              cardTransfer.classList.add('selected');
              cardNative.classList.remove('selected');
              bankFields.style.display = 'block';
              acctField.setAttribute('required', 'required');
              bankCodeField.setAttribute('required', 'required');
              acctNameField.setAttribute('required', 'required');
              btnText.textContent = "{l s='Process Bank Transfer Refund' mod='nomba'}";
            }
          };

          typeFull.addEventListener('change', toggleAmountInput);
          typePartial.addEventListener('change', toggleAmountInput);

          // Default selection
          {if $has_bank_details}
            selectMethod('transfer');
          {else}
            selectMethod('native');
          {/if}

          // Form validation
          form.addEventListener('submit', function(e) {
            var selectedType = typePartial.checked ? 'partial' : 'full';
            var amt = parseFloat(amountField.value);
            var selectedMethod = methodTransfer.checked ? 'transfer' : 'native';

            if (selectedType === 'partial') {
              if (isNaN(amt) || amt <= 0) {
                alert("{l s='Please enter a valid refund amount.' mod='nomba'}");
                e.preventDefault(); return false;
              }
              if (amt > maxRefundable) {
                alert("{l s='Refund amount cannot exceed remaining total.' mod='nomba'}");
                e.preventDefault(); return false;
              }
            }

            if (selectedMethod === 'transfer') {
              if (acctField.value.trim() === '' || bankCodeField.value.trim() === '' || acctNameField.value.trim() === '') {
                alert("{l s='Please provide all bank details for transfer refund.' mod='nomba'}");
                e.preventDefault(); return false;
              }
            }

            var confirmMsg = selectedMethod === 'native'
              ? "{l s='Process card reversal of' mod='nomba'} " + formatCurrency(selectedType === 'full' ? maxRefundable : amt) + "?\n{l s='This may take T+7 days to reflect.' mod='nomba'}"
              : "{l s='Process bank transfer refund of' mod='nomba'} " + formatCurrency(selectedType === 'full' ? maxRefundable : amt) + "?\n{l s='Funds will be sent to the provided bank account.' mod='nomba'}";

            if (!confirm(confirmMsg + "\n\n{l s='This action cannot be undone.' mod='nomba'}")) {
              e.preventDefault(); return false;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="icon-spinner icon-spin"></i> {l s='Processing...' mod='nomba'}';
          });

          function formatCurrency(value) {
            return "₦" + value.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
          }
        });
      </script>
    {/if}
  </div>
</div>