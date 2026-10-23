<?php
/**
 * Plugin Name: GTXXL Bestelling maken
 * Description: Snel een bestelling maken vanuit de backoffice (klant kiezen of toevoegen, producten, verzendkosten of afhalen, mail met betaallink), een bestaande bestelling dupliceren en offertes maken (status "Offerte", pdf, accepteren door te betalen). Gebruikt de prijs-, verzendkosten- en zoekfuncties van "GTXXL Bestelling wijzigen".
 * Version: 1.3.0
 * Author: Green Trading XXL
 * Text Domain: gtxxl-maken
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'GTXXL_MAKEN_VERSIE', '1.3.0' );
define( 'GTXXL_MAKEN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GTXXL_MAKEN_URL', plugin_dir_url( __FILE__ ) );

add_action(
    'plugins_loaded',
    function () {
        load_plugin_textdomain( 'gtxxl-maken', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }
);

/** De rekenfuncties en de productzoeker komen uit "GTXXL Bestelling wijzigen"; zonder die plugin doet deze niets. */
function gtxxl_maken_kan(): bool {
    return class_exists( 'WooCommerce' ) && class_exists( 'GTXXL_Wijzigen_Plan' ) && class_exists( 'GTXXL_Wijzigen_Zoeker' );
}

add_action(
    'plugins_loaded',
    function () {
        if ( ! gtxxl_maken_kan() ) {
            return;
        }
        require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-klant.php';
        require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken.php';
        require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-admin.php';
        require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-offerte.php';

        GTXXL_Maken::init();
        GTXXL_Maken_Klant::init();
        GTXXL_Maken_Admin::init();
        GTXXL_Maken_Offerte::init();
    },
    20
);

// De mail met betaallink aan de klant.
add_filter(
    'woocommerce_email_classes',
    function ( $emails ) {
        if ( gtxxl_maken_kan() ) {
            require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-betaalverzoek-email.php';
            $emails['GTXXL_Maken_Betaalverzoek_Email'] = new GTXXL_Maken_Betaalverzoek_Email();
            require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-offerte-email.php';
            $emails['GTXXL_Maken_Offerte_Email'] = new GTXXL_Maken_Offerte_Email();
        }

        return $emails;
    }
);

// De nachtelijke controle op verlopen offertes.
register_deactivation_hook(
    __FILE__,
    function () {
        wp_clear_scheduled_hook( 'gtxxl_maken_offertes_verlopen' );
    }
);
