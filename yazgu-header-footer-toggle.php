<?php
/**
 * Plugin Name: YAZGU Header ve Footer Kaydırmalı
 * Description: Header ve footer'ı kaydırma yönüne göre gizler/gösterir. Footer başlangıçta ekranın altına sabitlenir. Sadece anasayfada ve Elementor editöründe aktif.
 * Version: 2.3
 * Author: YAZGU
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// === STİL ===
add_action('wp_head', function() {
    // Anasayfada veya Elementor editöründe stil uygula
    if ( is_front_page() || (isset($_GET['elementor-preview']) || isset($_GET['elementor']) || (function_exists('Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode()) ) ) { ?>
        <style>
            /* === HEADER === */
            #header-main {
                position: fixed !important;
                top: 0;
                left: 0;
                right: 0;
                z-index: 9999;
                transition: transform 0.4s ease;
            }
            #header-main.hide-header {
                transform: translateY(-100%);
            }

            /* === FOOTER === */
            #footer-main {
                position: fixed !important;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 9998;
                transition: transform 0.6s ease;
                will-change: transform;
            }
            #footer-main.hide-footer {
                transform: translateY(100%);
            }

            /* Sadece anasayfada ve Elementor editöründe padding uygula */
            body {
                padding-top: 100px !important;
                padding-bottom: 80px !important;
            }
        </style>
    <?php } else { ?>
        <style>
            /* Diğer sayfalarda header ve footer'ı gizle */
            #header-main, #footer-main {
                display: none !important;
            }
            /* Diğer sayfalarda body padding'i sıfırla */
            body {
                padding-top: 0 !important;
                padding-bottom: 0 !important;
            }
        </style>
    <?php }
});

// === JAVASCRIPT ===
add_action('wp_footer', function() {
    // Anasayfada veya Elementor editöründe JavaScript çalışsın
    if ( is_front_page() || (isset($_GET['elementor-preview']) || isset($_GET['elementor']) || (function_exists('Elementor\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode()) ) ) { ?>
        <script>
            (function($){
                let lastScrollTop = 0;
                const header = $('#header-main');
                const footer = $('#footer-main');

                $(window).on('scroll', function() {
                    let st = $(this).scrollTop();

                    // Aşağı kaydırma
                    if (st > lastScrollTop + 10) {
                        header.addClass('hide-header');
                        footer.addClass('hide-footer');
                    }
                    // Yukarı kaydırma
                    else if (st < lastScrollTop - 10) {
                        header.removeClass('hide-header');
                        footer.removeClass('hide-footer');
                    }

                    lastScrollTop = st;
                });
            })(jQuery);
        </script>
    <?php }
});