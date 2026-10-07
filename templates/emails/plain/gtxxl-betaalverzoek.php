<?php
/**
 * Mail "betaalverzoek" (platte tekst).
 *
 * @var WC_Order $order
 * @var string   $bericht
 * @var string   $betaallink
 * @var string   $email_heading
 */

defined( 'ABSPATH' ) || exit;

$gtxxl_nl   = 'nl' === $order->get_meta( 'wpml_language' );
$gtxxl_geld = static function ( $bedrag ) use ( $order ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $bedrag, array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
};

echo '= ' . esc_html( $email_heading ) . " =\n\n";
echo esc_html( $gtxxl_nl ? sprintf( 'We hebben bestelling #%s voor je klaargezet.', $order->get_order_number() ) : sprintf( 'Wir haben die Bestellung #%s für dich vorbereitet.', $order->get_order_number() ) ) . "\n\n";

if ( '' !== trim( $bericht ) ) {
	echo esc_html( $bericht ) . "\n\n";
}
foreach ( $order->get_items() as $gtxxl_item ) {
	echo esc_html( $gtxxl_item->get_quantity() . ' x ' . wp_strip_all_tags( $gtxxl_item->get_name() ) ) . "\n";
}
echo "\n" . esc_html( ( $gtxxl_nl ? 'Te betalen: ' : 'Zu zahlen: ' ) . $gtxxl_geld( $order->get_total() ) ) . "\n";
echo esc_url_raw( $betaallink ) . "\n";
