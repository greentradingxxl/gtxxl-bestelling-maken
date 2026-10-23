<?php
/**
 * Mail "offerte" (HTML): een offerte uit de backoffice, met de pdf in de bijlage.
 *
 * Begroeting, het bericht van de medewerker in een eigen blok, wat er in de offerte zit, het totaal, tot wanneer ze
 * geldig is en de knop om te accepteren en te betalen. Zelfde opzet als de mail "betaalverzoek".
 *
 * @var WC_Order $order
 * @var string   $bericht
 * @var string   $betaallink
 * @var string   $nummer
 * @var string   $email_heading
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

$gtxxl_nl     = 'nl' === $order->get_meta( 'wpml_language' );
$gtxxl_naam   = (string) $order->get_billing_first_name();
$gtxxl_nummer = '' !== $nummer ? $nummer : $order->get_order_number();
$gtxxl_tot    = GTXXL_Maken_Offerte::datum( GTXXL_Maken_Offerte::tot( $order ), $gtxxl_nl );
$gtxxl_geld   = static function ( $bedrag ) use ( $order ) {
	return wp_strip_all_tags( wc_price( (float) $bedrag, array( 'currency' => $order->get_currency() ) ) );
};
$gtxxl_afhalen = GTXXL_Maken::is_afhalen( $order );

// Teksten per taal hier, zodat er geen vertalingen in WPML nodig zijn.
$gtxxl_t = $gtxxl_nl
	? array(
		'hello'    => '' !== $gtxxl_naam ? sprintf( 'Hallo %s,', $gtxxl_naam ) : 'Hallo,',
		'intro'    => sprintf( 'Bedankt voor je aanvraag. Hierbij krijg je onze offerte %s; je vindt haar ook als pdf in de bijlage.', $gtxxl_nummer ),
		'contents' => 'Dit zit er in de offerte',
		'shipping' => 'Verzending',
		'total'    => 'Totaal',
		'valid'    => sprintf( 'De offerte is geldig tot en met %s. Akkoord? Met de knop hieronder accepteer je haar en betaal je meteen veilig online.', $gtxxl_tot ),
		'button'   => 'Offerte accepteren en betalen',
		'after'    => $gtxxl_afhalen ? 'Zodra je betaling binnen is, zetten we je bestelling klaar om af te halen. Je krijgt bericht zodra ze klaarstaat; de factuur krijg je per mail.' : 'Zodra je betaling binnen is, gaan we voor je aan de slag. De factuur krijg je dan per mail.',
		'question' => 'Heb je een vraag of wil je iets aanpassen? Beantwoord dan deze mail.',
	)
	: array(
		'hello'    => '' !== $gtxxl_naam ? sprintf( 'Hallo %s,', $gtxxl_naam ) : 'Hallo,',
		'intro'    => sprintf( 'Vielen Dank für deine Anfrage. Hier ist unser Angebot %s; du findest es auch als PDF im Anhang.', $gtxxl_nummer ),
		'contents' => 'Das ist im Angebot enthalten',
		'shipping' => 'Versand',
		'total'    => 'Gesamt',
		'valid'    => sprintf( 'Das Angebot ist gültig bis einschließlich %s. Einverstanden? Über den Button unten nimmst du es an und bezahlst direkt sicher online.', $gtxxl_tot ),
		'button'   => 'Angebot annehmen und bezahlen',
		'after'    => $gtxxl_afhalen ? 'Sobald deine Zahlung eingegangen ist, legen wir deine Bestellung zur Abholung bereit. Du erhältst eine Nachricht, sobald sie bereitliegt; die Rechnung bekommst du per E-Mail.' : 'Sobald deine Zahlung eingegangen ist, beginnen wir mit der Bearbeitung. Die Rechnung erhältst du dann per E-Mail.',
		'question' => 'Hast du eine Frage oder möchtest du etwas ändern? Dann antworte auf diese E-Mail.',
	);

$gtxxl_base = sanitize_hex_color( get_option( 'woocommerce_email_base_color' ) ) ?: '#23582c';
$gtxxl_rand = ' border-top:1px solid #e6ebe7;';

// De regels: producten met foto, daarna verzending en kosten.
$gtxxl_regels = array();
// Producten tegen de gewone prijs; een korting staat als eigen regel eronder.
foreach ( $order->get_items() as $gtxxl_item ) {
	$gtxxl_regels[] = array(
		'naam'   => wp_strip_all_tags( $gtxxl_item->get_name() ),
		'aantal' => '×' . $gtxxl_item->get_quantity(),
		'bedrag' => $gtxxl_geld( (float) $gtxxl_item->get_subtotal() + (float) $gtxxl_item->get_subtotal_tax() ),
	) + GTXXL_Wijzigen_Plan::foto( $gtxxl_item->get_product() );
}
$gtxxl_korting = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
if ( $gtxxl_korting > 0.004 ) {
	$gtxxl_regels[] = array(
		'naam'   => $gtxxl_nl ? 'Korting' : 'Rabatt',
		'aantal' => '',
		'bedrag' => '− ' . $gtxxl_geld( $gtxxl_korting ),
		'foto'   => '',
	);
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
	<table class="gtxxl-bank" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%; margin:0; border:1px solid #dde4df; border-radius:8px; border-collapse:separate; background-color:#f7f9f6;">
		<tr>
			<td style="padding:14px 16px; text-align:left; color:#1e1e1e; font-size:15px; line-height:150%; -ms-hyphens:none; hyphens:none; word-break:normal;"><?php echo wp_kses( nl2br( esc_html( $bericht ) ), array( 'br' => array() ) ); ?></td>
		</tr>
	</table>
	<?php // Afstand tot de kop eronder: Outlook negeert de marge onder een tabel, een lege cel met vaste hoogte niet. ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%;">
		<tr>
			<td height="22" style="height:22px; padding:0; font-size:1px; line-height:22px; mso-line-height-rule:exactly;">&nbsp;</td>
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

<p><?php echo esc_html( $gtxxl_t['valid'] ); ?></p>

<?php // De ruimte rond de tekst staat op de cel, niet op de link: Outlook negeert padding op een link, en ook de marge onder een tabel; daarom een lege rij als afstand. ?>
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0;">
	<tr>
		<td bgcolor="<?php echo esc_attr( $gtxxl_base ); ?>" style="padding:13px 26px; border-radius:6px; background-color:<?php echo esc_attr( $gtxxl_base ); ?>;">
			<a href="<?php echo esc_url( $betaallink ); ?>" style="display:inline-block; color:#ffffff; font-size:16px; font-weight:bold; line-height:120%; text-decoration:none;"><?php echo esc_html( $gtxxl_t['button'] . ' (' . $gtxxl_geld( $order->get_total() ) . ')' ); ?></a>
		</td>
	</tr>
	<tr>
		<td height="18" style="height:18px; padding:0; font-size:1px; line-height:18px;">&nbsp;</td>
	</tr>
</table>

<p><?php echo esc_html( $gtxxl_t['after'] ); ?></p>

<p style="color:#5d6a62; font-size:13px; line-height:150%;"><?php echo esc_html( $gtxxl_t['question'] ); ?></p>

<?php
do_action( 'woocommerce_email_footer', $email );
