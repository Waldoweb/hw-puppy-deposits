// @ts-ignore
jQuery(document).ready(function ($) {
  "use strict";

  $(document.body).on("updated_checkout", function () {
    // @ts-ignore
    var options = wc_deposits_checkout_options;
    var form = $("#wc-deposits-options-form");
    var deposit = form.find("#pay-deposit");
    var deposit_label = form.find("#pay-deposit-label");
    var full = form.find("#pay-full-amount");
    var full_label = form.find("#pay-full-amount-label");
    var msg = form.find("#wc-deposits-notice");
    var amount = form.find("#deposit-amount");
    var update_message = function () {
      if (deposit.is(":checked")) {
        msg.html(options.message.deposit);
      } else if (full.is(":checked")) {
        msg.html(options.message.full);
      }
    };

    $('[name="wcdp-selected-plan"],[name="deposit-radio"]').on(
      "change",
      function () {
        $(document.body).trigger("update_checkout");
      }
    );
    $(".checkout").on("change", "input, select", update_message);
    update_message();

    if ($("#wcdp-payment-plans").length > 0) {
      $("#wcdp-payment-plans a.wcdp-view-plan-details").click(function () {
        var plan_id = $(this).data("id");
        var selector = "#plan-details-" + plan_id;
        if ($(this).data("expanded") === "no") {
          var text = $(this).data("hide-text");
          $(this).text(text);
          $(this).data("expanded", "yes");
          $(selector).slideDown();
        } else if ($(this).data("expanded") === "yes") {
          var text = $(this).data("view-text");
          $(this).text(text);
          $(this).data("expanded", "no");
          $(selector).slideUp();
        }
      });
    }
  });
});

// @ts-ignore
jQuery(document).ready(function ($) {
  "use strict";

  if ($("#deposit-help-tip").length === 0) {
    return;
  }

  var activate_tooltip = function () {
    $("#deposit-help-tip").tipTip({
      attribute: "data-tip",
      fadeIn: 50,
      fadeOut: 50,
      delay: 200,
    });
  };

  $(document.body).on("updated_cart_totals updated_checkout", activate_tooltip);

  activate_tooltip();
});
