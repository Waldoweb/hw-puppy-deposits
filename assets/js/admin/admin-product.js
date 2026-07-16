// @ts-ignore
jQuery(document).ready(function ($) {
  "use strict";

  var deposit_variation = function () {
    var update_plan = function (loop, amount_type) {
      if ($("#_wc_deposits_override_product_settings" + loop).is(":checked")) {
        if (amount_type === "payment_plan") {
          $("._wc_deposits_payment_plans" + loop + "_field").removeClass(
            "hidden"
          );
          $("._wc_deposits_deposit_amount" + loop + "_field ").addClass(
            "hidden"
          );
        } else {
          $("._wc_deposits_payment_plans" + loop + "_field").addClass("hidden");
          $("._wc_deposits_deposit_amount" + loop + "_field ").removeClass(
            "hidden"
          );
        }
      }
    };

    $(".wc_deposits_override_product_settings").change(function () {
      var loop = $(this).data("loop");
      if ($(this).is(":checked")) {
        $(".wc_deposits_field" + loop).removeClass("hidden");
        $(this)
          .parent()
          .parent()
          .find(".wc_deposits_varitaion_amount_type")
          .trigger("change");
      } else {
        $(".wc_deposits_field" + loop).addClass("hidden");
      }
    });

    $(".wc_deposits_override_product_settings").trigger("change");

    $(".wc_deposits_varitaion_amount_type").change(function () {
      var loop = $(this).data("loop");
      update_plan(loop, $(this).val());
    });

    $(".wc_deposits_varitaion_amount_type").trigger("change");
  };

  // $( '#variable_product_options' ).trigger( 'woocommerce_variations_added', 1 );
  $("#woocommerce-product-data").on(
    "woocommerce_variations_loaded",
    deposit_variation
  );
  $("#variable_product_options").on(
    "woocommerce_variations_added",
    deposit_variation
  );

  //payment plans toggle
  $("#_wc_deposits_amount_type").change(function () {
    if ($(".wc_deposits_override_product_settings").is(":checked")) {
      if ($(this).val() === "payment_plan") {
        $("._wc_deposits_payment_plans_field").removeClass("hidden");
        $("._wc_deposits_deposit_amount_field ").addClass("hidden");
      } else {
        $("._wc_deposits_payment_plans_field").addClass("hidden");
        $("._wc_deposits_deposit_amount_field ").removeClass("hidden");
      }
    }
  });

  $("#_wc_deposits_amount_type").change(function () {
    if ($(this).val() === "payment_plan") {
      $("._wc_deposits_payment_plans_field").removeClass("hidden");
      $("._wc_deposits_deposit_amount_field ").addClass("hidden");
    } else {
      $("._wc_deposits_payment_plans_field").addClass("hidden");
      $("._wc_deposits_deposit_amount_field ").removeClass("hidden");
    }
  });

  $("#_wc_deposits_inherit_storewide_settings").change(function () {
    $(".wcdp_deposit_values.options_group").toggleClass("hidden");
  });

  //reminder datepicker
  $("#reminder_datepicker").datepicker({
    dateFormat: "dd-mm-yy",
    minDate: new Date(),
  });
});
