<?php
/**
 * Victoriabank MIA – Thank You page QR code template.
 *
 * This template can be overridden by copying it to:
 * yourtheme/woocommerce/payment/victoriabank-mia-thankyou.php
 *
 * @package payment-gateway-wc-victoriabank-mia
 *
 * @var string    $gateway_title     Payment gateway display title.
 * @var string    $gateway_icon      URL of the gateway icon.
 * @var string    $fieldset_id       ID attribute for the outer fieldset.
 * @var string    $success_id        ID attribute for the success panel.
 * @var string    $expired_id        ID attribute for the expired panel.
 * @var string    $qr_section_id     ID attribute for the QR code section.
 * @var string    $qr_code_js_div_id ID attribute for the QRCode.js render target.
 * @var string    $countdown_id      ID attribute for the countdown element.
 * @var string    $deep_link_id      ID attribute for the deep-link anchor.
 * @var bool      $is_paid           Whether the order is already paid.
 * @var bool      $is_mobile         Whether the visitor is on a mobile device.
 * @var string    $qr_url            MIA QR deep-link URL.
 * @var string    $pay_url           WooCommerce order-pay URL (for QR retry).
 * @var string    $qr_code_title     Section heading text.
 * @var string    $qr_code_text      Section description text.
 * @var string    $qr_code_url_text  Deep-link button label.
 * @var string    $validity_text     Countdown label prefix.
 * @var string    $expired_text      Expired state message.
 * @var string    $retry_text        Retry button label.
 * @var string    $success_text      Success state message.
 * @var string    $paid_text         Already-paid state message.
 */

defined('ABSPATH') || exit;
?>

<fieldset id="<?php echo esc_attr($fieldset_id); ?>">
    <legend><?php echo esc_html($gateway_title); ?></legend>

    <?php if ($is_paid) : ?>

        <div style="display: flex; flex-direction: column; align-items: center; text-align: center;">
            <p><?php echo esc_html($paid_text); ?></p>
        </div>

    <?php else : ?>

        <?php /* Success state – hidden until payment confirmed via polling */ ?>
        <div id="<?php echo esc_attr($success_id); ?>" style="display: none; flex-direction: column; align-items: center; text-align: center;">
            <p class="woocommerce-message"><?php echo esc_html($success_text); ?></p>
        </div>

        <?php /* Expired state – hidden until countdown reaches zero */ ?>
        <div id="<?php echo esc_attr($expired_id); ?>" style="display: none; flex-direction: column; align-items: center; text-align: center;">
            <p><?php echo esc_html($expired_text); ?></p>
            <a href="<?php echo esc_url($pay_url); ?>" class="woocommerce-button button pay"><?php echo esc_html($retry_text); ?></a>
        </div>

        <?php /* QR code section – visible initially */ ?>
        <div id="<?php echo esc_attr($qr_section_id); ?>" style="display: flex; flex-direction: column; align-items: center; text-align: center;">
            <img src="<?php echo esc_url($gateway_icon); ?>" alt="<?php echo esc_attr($gateway_title); ?>" class="aligncenter" style="max-width: 200px; height: auto;">
            <?php if (!$is_mobile) : ?>
            <div id="<?php echo esc_attr($qr_code_js_div_id); ?>" class="aligncenter"></div>
            <?php endif; ?>
            <h2><?php echo esc_html($qr_code_title); ?></h2>
            <p><?php echo esc_html($qr_code_text); ?></p>
            <p>
                <?php echo esc_html($validity_text); ?>
                <strong id="<?php echo esc_attr($countdown_id); ?>">--:--</strong>
            </p>
            <a id="<?php echo esc_attr($deep_link_id); ?>" href="<?php echo esc_url($qr_url); ?>" target="_blank" class="woocommerce-button button pay order-actions-button"><?php echo esc_html($qr_code_url_text); ?></a>
        </div>

    <?php endif; ?>

</fieldset>
