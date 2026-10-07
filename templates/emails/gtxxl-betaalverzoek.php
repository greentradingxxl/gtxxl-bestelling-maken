<?php
/**
 * Mail "betaalverzoek" (HTML): een in de backoffice gemaakte bestelling die de klant nog moet betalen.
 *
 * Begroeting, het bericht van de medewerker in een eigen blok, wat er in de bestelling zit, het bedrag en de knop om
 * te betalen. Zelfde opzet als de andere mails van de winkel.
 *
 * @var WC_Order $order
 * @var string   $bericht
 * @var string   $betaallink
 * @var string   $email_heading
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

$gtxxl_nl     = 'nl' === $order->get_meta( 'wpml_language' );
$gtxxl_naam   = (string) $order->get_billing_first_name();
$gtxxl_nummer = $order->get_order_number();
$gtxxl_geld   = static function ( $bedrag ) use ( $order ) {
	return wp_strip_all_tags( wc_price( (float) $bedrag, array( 'currency' => $order->get_currency() ) ) );
};
$gtxxl_stuur   = ! GTXXL_Maken::niet_verzenden( $order );
$gtxxl_afhalen = GTXXL_Maken::is_afhalen( $order );

// Teksten per taal hier, zodat er geen vertalingen in WPML nodig zijn.
$gtxxl_t = $gtxxl_nl
	? array(
		'hello'    => '' !== $gtxxl_naam ? sprintf( 'Hallo %s,', $gtxxl_naam ) : 'Hallo,',
		'intro'    => sprintf( 'We hebben bestelling #%s voor je klaargezet. Met de knop hieronder kun je haar veilig online betalen.', $gtxxl_nummer ),
		'contents' => 'Dit zit er in de bestelling',
		'shipping' => 'Verzending',
		'total'    => 'Te betalen',
		'button'   => 'Nu betalen',
		'after'    => $gtxxl_afhalen ? 'Zodra je betaling binnen is, zetten we je bestelling klaar om af te halen. Je krijgt bericht zodra ze klaarstaat; de factuur krijg je per mail.' : ( $gtxxl_stuur ? 'Zodra je betaling binnen is, gaan we voor je aan de slag. De factuur krijg je dan per mail.' : 'Zodra je betaling binnen is, krijg je de factuur per mail. Voor deze bestelling volgt geen zending.' ),
		'question' => 'Heb je een vraag over dit betaalverzoek? Beantwoord dan deze mail.',
	)
	: array(
		'hello'    => '' !== $gtxxl_naam ? sprintf( 'Hallo %s,', $gtxxl_naam ) : 'Hallo,',
		'intro'    => sprintf( 'Wir haben die Bestellung #%s für dich vorbereitet. Über den Button unten kannst du sie sicher online bezahlen.', $gtxxl_nummer ),
		'contents' => 'Das ist in der Bestellung enthalten',
		'shipping' => 'Versand',
		'total'    => 'Zu zahlen',
		'button'   => 'Jetzt bezahlen',
		'after'    => $gtxxl_afhalen ? 'Sobald deine Zahlung eingegangen ist, legen wir deine Bestellung zur Abholung bereit. Du erhältst eine Nachricht, sobald sie bereitliegt; die Rechnung bekommst du per E-Mail.' : ( $gtxxl_stuur ? 'Sobald deine Zahlung eingegangen ist, beginnen wir mit der Bearbeitung. Die Rechnung erhältst du dann per E-Mail.' : 'Sobald deine Zahlung eingegangen ist, erhältst du die Rechnung per E-Mail. Für diese Bestellung erfolgt kein Versand.' ),
		'question' => 'Hast du eine Frage zu dieser Zahlungsaufforderung? Dann antworte auf diese E-Mail.',
	);

$gtxxl_base = sanitize_hex_color( get_option( 'woocommerce_email_base_color' ) ) ?: '#23582c';
$gtxxl_rand = ' border-top:1px solid #e6ebe7;';

// De regels: producten met foto, daarna verzending en kosten.
$gtxxl_regels = array();
foreach ( $order->get_items() as $gtxxl_item ) {
	$gtxxl_regels[] = array(
		'naam'   => wp_strip_all_tags( $gtxxl_item->get_name() ),
		'aantal' => '×' . $gtxxl_item->get_quantity(),
		'bedrag' => $gtxxl_geld( (float) $gtxxl_item->get_total() + (float) $gtxxl_item->get_total_tax() ),
	) + GTXXL_Wijzigen_Plan::foto( $gtxxl_item->get_product() );
}
foreach ( $order->get_items( array( 'shipping', 'fee' ) ) as $gtxxl_item ) {
	$gtxxl_regels[] = array(
		// Bij afhalen zegt de naam van de regel het al ("Afhalen bij Green Trading XXL").
		'naam'   => $gtxxl_item instanceof WC_Order_Item_Shipping ? ( $gtxxl_afhalen ? '' : $gtxxl_t['shipping'] . ': ' ) . wp_strip_all_tags( $gtxxl_item->get_method_title() ) : wp_strip_all_tags( $gtxxl_item->get_name() ),
		'aantal' => '',
		'bedrag' => $gtxxl_geld( (float) $gtxxl_item->get_total() + (float) $gtxxl_item->get_total_tax() ),
		'foto'   => '',
	);
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php echo esc_html( $gtxxl_t['hello'] ); ?></p>

<p><?php echo esc_html( $gtxxl_t['intro'] ); ?></p>

<?php if ( '' !== trim( $bericht ) ) : ?>
	<table class="gtxxl-bank" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%; margin:0 0 18px; border:1px solid #dde4df; border-radius:8px; border-collapse:separate; background-color:#f7f9f6;">
		<tr>
			<td style="padding:14px 16px; text-align:left; color:#1e1e1e; font-size:15px; line-height:150%;"><?php echo wp_kses( nl2br( esc_html( $bericht ) ), array( 'br' => array() ) ); ?></td>
		</tr>
	</table>
<?php endif; ?>

<h2><?php echo esc_html( $gtxxl_t['contents'] ); ?></h2>
<table cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%; margin:0 0 18px;">
	<?php foreach ( $gtxxl_regels as $gtxxl_i => $gtxxl_regel ) : ?>
		<tr>
			<td style="padding:9px 12px 9px 0; width:48px; vertical-align:middle;<?php echo $gtxxl_i ? esc_attr( $gtxxl_rand ) : ''; ?>"><?php if ( ! empty( $gtxxl_regel['foto'] ) && ! empty( $gtxxl_regel['foto_b'] ) ) : ?><img src="<?php echo esc_url( $gtxxl_regel['foto'] ); ?>" width="<?php echo (int) $gtxxl_regel['foto_b']; ?>" height="<?php echo (int) $gtxxl_regel['foto_h']; ?>" alt="" style="display:block; width:<?php echo (int) $gtxxl_regel['foto_b']; ?>px; height:<?php echo (int) $gtxxl_regel['foto_h']; ?>px; margin:0 auto;"><?php endif; ?></td>
			<td style="padding:9px 0; text-align:left; vertical-align:middle; color:#1e1e1e; font-size:15px; line-height:150%;<?php echo $gtxxl_i ? esc_attr( $gtxxl_rand ) : ''; ?>"><?php echo esc_html( $gtxxl_regel['naam'] ); ?></td>
			<td style="padding:9px 8px; text-align:right; vertical-align:middle; white-space:nowrap; color:#3c4741; font-size:15px; line-height:150%; width:44px;<?php echo $gtxxl_i ? esc_attr( $gtxxl_rand ) : ''; ?>"><?php echo esc_html( $gtxxl_regel['aantal'] ); ?></td>
			<td style="padding:9px 0; text-align:right; vertical-align:middle; white-space:nowrap; color:#1e1e1e; font-size:15px; line-height:150%; width:84px;<?php echo $gtxxl_i ? esc_attr( $gtxxl_rand ) : ''; ?>"><?php echo esc_html( $gtxxl_regel['bedrag'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	<tr>
		<td colspan="3" style="padding:12px 0 0; text-align:left; color:#1e1e1e; font-size:16px; font-weight:bold; line-height:150%; border-top:2px solid #dde4df;"><?php echo esc_html( $gtxxl_t['total'] ); ?></td>
		<td style="padding:12px 0 0; text-align:right; white-space:nowrap; color:#1e1e1e; font-size:16px; font-weight:bold; line-height:150%; border-top:2px solid #dde4df;"><?php echo esc_html( $gtxxl_geld( $order->get_total() ) ); ?></td>
	</tr>
</table>

<table cellspacing="0" cellpadding="0" border="0" style="margin:0 0 14px;">
	<tr>
		<td style="padding:0; border-radius:6px; background-color:<?php echo esc_attr( $gtxxl_base ); ?>;">
			<a href="<?php echo esc_url( $betaallink ); ?>" style="display:inline-block; padding:13px 26px; color:#ffffff; font-size:16px; font-weight:bold; line-height:120%; text-decoration:none; border-radius:6px;"><?php echo esc_html( $gtxxl_t['button'] . ' (' . $gtxxl_geld( $order->get_total() ) . ')' ); ?></a>
		</td>
	</tr>
</table>

<p><?php echo esc_html( $gtxxl_t['after'] ); ?></p>

<p style="color:#5d6a62; font-size:13px; line-height:150%;"><?php echo esc_html( $gtxxl_t['question'] ); ?></p>

<?php
do_action( 'woocommerce_email_footer', $email );
