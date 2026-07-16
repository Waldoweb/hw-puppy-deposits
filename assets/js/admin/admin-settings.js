jQuery(document).ready(function ($) {
  "use strict";

  $(".deposits-color-field").wpColorPicker();

  //checkout fields toggle payment plans field

  var checkout_mode_toggle = function () {
    var payment_plan_row = $("#wc_deposits_checkout_mode_payment_plans")
      .parent()
      .parent();
    var amount_row = $("#wc_deposits_checkout_mode_deposit_amount")
      .parent()
      .parent();
    if (
      $("#wc_deposits_checkout_mode_deposit_amount_type").val() ===
      "payment_plan"
    ) {
      amount_row.slideUp("fast");
      payment_plan_row.slideDown("fast");
      $("#wc_deposits_checkout_mode_payment_plans").attr(
        "required",
        "required"
      );
    } else {
      payment_plan_row.slideUp("fast");
      amount_row.slideDown("fast");
      $("#wc_deposits_checkout_mode_payment_plans").removeAttr("required");
    }
  };
  $("#wc_deposits_checkout_mode_deposit_amount_type").change(function () {
    checkout_mode_toggle();
  });

  var storewide_deposit_toggle = function () {
    var payment_plan_row = $("#wc_deposits_storewide_deposit_payment_plans")
      .parent()
      .parent();
    var amount_row = $("#wc_deposits_storewide_deposit_amount")
      .parent()
      .parent();

    if (
      $("#wc_deposits_storewide_deposit_amount_type").val() === "payment_plan"
    ) {
      amount_row.slideUp("fast");
      payment_plan_row.slideDown("fast");
      $("#wc_deposits_storewide_deposit_payment_plans").attr(
        "required",
        "required"
      );
    } else {
      payment_plan_row.slideUp("fast");
      $("#wc_deposits_storewide_deposit_payment_plans").removeAttr("required");

      amount_row.slideDown("fast");
    }
  };
  $("#wc_deposits_storewide_deposit_amount_type").change(function () {
    storewide_deposit_toggle();
  });

  storewide_deposit_toggle();
  checkout_mode_toggle();

  $("#wc_deposits_verify_purchase_code").on("click", function (e) {
    e.preventDefault();

    var purchase_code = $("#wc_deposits_purchase_code").val();

    if (purchase_code.length < 1) {
      window.alert("Purchase code cannot be empty");
      return false;
    }

    $(this).attr("disabled", "disabled");
    $("#wcdp_verify_purchase_container").prepend(
      '<img src="images/loading.gif" />'
    );

    //make ajax call

    var data = {
      action: "wc_deposits_verify_purchase_code",
      purchase_code: $("#wc_deposits_purchase_code").val(),
      nonce: $("#wcdp_verify_purchase_code_nonce").val(),
    };

    $.post(wc_deposits.ajax_url, data)
      .done(function (res) {
        if (res.success) {
          $("#wc_deposits_verify_purchase_code").removeAttr("disabled");
          $("#wcdp_verify_purchase_container")
            .empty()
            .append('<span style="color:green;">' + res.data + "</span>");
          // $('#wcdp_verify_purchase_container').find('img').remove();
        } else {
          $("#wc_deposits_verify_purchase_code").removeAttr("disabled");
          $("#wcdp_verify_purchase_container")
            .empty()
            .append('&nbsp;<span style="color:red;" >' + res.data + "</span>");
          // $('#wcdp_verify_purchase_container').find('img').remove();
        }
      })
      .fail(function () {
        $(this).removeAttr("disabled");
        window.alert("Error occurred");
      });
  });

  // if()
  // tabs

  if ($(".wcdp.nav-tab").length > 0) {
    var switchTab = function (target) {
      $(".wcdp-tab-content").hide();
      $(".wcdp.nav-tab").removeClass("nav-tab-active");
      $('.wcdp.nav-tab[data-target="' + target + '"]').addClass(
        "nav-tab-active"
      );
      var ele = $("#" + target);
      ele.show();
    };

    $(".wcdp.nav-tab").on("click", function (e) {
      e.preventDefault();
      var target = $(this).data("target");
      location.hash = target;
      switchTab(target);
      $("html,body").animate({
        scrollTop: "-=200px",
      });

      return false;
    });

    if (window.location.hash.length > 0) {
      $(
        '.wcdp.nav-tab[data-target="' +
          window.location.hash.split("#")[1] +
          '"]'
      ).trigger("click");
    }
  }
});
