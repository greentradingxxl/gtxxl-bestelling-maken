<?php
/**
 * Mail "offerte" (platte tekst).
 *
 * @var WC_Order $order
 * @var string   $bericht
 * @var string   $betaallink
 * @var string   $nummer
 * @var string   $email_heading
 */

defined( 'ABSPATH' ) || exit;

$gtxxl_nl   = 'nl' === $order->get_meta( 'wpml_language' );
$gtxxl_tot  = GTXXL_Maken_Offerte::datum( GTXXL_Maken_Offerte::tot( $order ), $gtxxl_nl );
$gtxxl_geld = static function ( $bedrag ) use ( $order ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $bedrag, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
};

echo '= ' . esc_html( $email_heading ) . " =\n\n";
echo esc_html( $gtxxl_nl ? sprintf( 'Hierbij krijg je onze offerte %s (ook als pdf in de bijlage).', $nummer ) : sprintf( 'Hier ist unser Angebot %s (auch als PDF im Anhang).', $nummer ) ) . "\n\n";

if ( '' !== trim( $bericht ) ) {
	echo esc_html( $bericht ) . "\n\n";
}
foreach ( $order->get_items() as $gtxxl_item ) {
	echo esc_html( $gtxxl_item->get_quantity() . ' x ' . wp_strip_all_tags( $gtxxl_item->get_name() ) ) . "\n";
}
echo "\n" . esc_html( ( $gtxxl_nl ? 'Totaal: ' : 'Gesamt: ' ) . $gtxxl_geld( $order->get_total() ) ) . "\n";
echo esc_html( $gtxxl_nl ? sprintf( 'Geldig tot en met %s. Accepteren en betalen:', $gtxxl_tot ) : sprintf( 'Gültig bis einschließlich %s. Annehmen und bezahlen:', $gtxxl_tot ) ) . "\n";
echo esc_url_raw( $betaallink ) . "\n";
