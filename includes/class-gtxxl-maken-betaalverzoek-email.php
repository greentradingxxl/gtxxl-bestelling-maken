<?php
/**
 * De mail aan de klant met een betaallink voor een bestelling die in de backoffice is gemaakt.
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Betaalverzoek_Email extends WC_Email {

    /** @var string Het bericht van de medewerker aan de klant. */
    public $bericht = '';

    /** @var string De betaallink, in de taal van de bestelling. */
    public $betaallink = '';

    public function __construct() {
        $this->id             = 'gtxxl_betaalverzoek';
        $this->customer_email = true;
        $this->title          = __( 'Betaalverzoek', 'gtxxl-maken' );
        $this->description    = __( 'Gaat naar de klant als je bij een snelle bestelling of bij "Bestelling dupliceren" kiest voor een mail met betaallink: wat er in de bestelling zit, het bedrag en de knop om te betalen.', 'gtxxl-maken' );
        $this->template_html  = 'emails/gtxxl-betaalverzoek.php';
        $this->template_plain = 'emails/plain/gtxxl-betaalverzoek.php';
        $this->template_base  = GTXXL_MAKEN_PATH . 'templates/';

        parent::__construct();
    }

    private function nl(): bool {
        return $this->object instanceof WC_Order && 'nl' === $this->object->get_meta( 'wpml_language' );
    }

    // Onderwerp en kop vast per taal van de bestelling: geen vertalingen in WPML nodig.
    public function get_subject() {
        $nummer = $this->object instanceof WC_Order ? $this->object->get_order_number() : '';

        return $this->nl() ? sprintf( 'Betaalverzoek voor je bestelling #%s', $nummer ) : sprintf( 'Zahlungsaufforderung für deine Bestellung #%s', $nummer );
    }

    public function get_heading() {
        return $this->nl() ? 'Je betaallink staat klaar' : 'Dein Zahlungslink ist bereit';
    }

    public function trigger( WC_Order $order, string $bericht = '' ): void {
        $taal = (string) $order->get_meta( 'wpml_language' );
        if ( $taal ) {
            do_action( 'wpml_switch_language', $taal );
        }
        $this->setup_locale();

        $this->object     = $order;
        $this->bericht    = $bericht;
        $this->betaallink = $order->get_checkout_payment_url();
        $this->recipient  = $order->get_billing_email();

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }

        $this->restore_locale();
        if ( $taal ) {
            do_action( 'wpml_switch_language', '' );
        }
    }

    private function gegevens( bool $plat ): array {
        return [
            'order'         => $this->object,
            'bericht'       => $this->bericht,
            'betaallink'    => $this->betaallink,
            'email_heading' => $this->get_heading(),
            'sent_to_admin' => false,
            'plain_text'    => $plat,
            'email'         => $this,
        ];
    }

    public function get_content_html() {
        return wc_get_template_html( $this->template_html, $this->gegevens( false ), '', $this->template_base );
    }

    public function get_content_plain() {
        return wc_get_template_html( $this->template_plain, $this->gegevens( true ), '', $this->template_base );
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'    => [ 'title' => __( 'Ingeschakeld', 'gtxxl-maken' ), 'type' => 'checkbox', 'label' => __( 'Deze e-mailmelding inschakelen', 'gtxxl-maken' ), 'default' => 'yes' ],
            'email_type' => [ 'title' => __( 'E-mailtype', 'gtxxl-maken' ), 'type' => 'select', 'default' => 'html', 'class' => 'email_type wc-enhanced-select', 'options' => $this->get_email_type_options() ],
        ];
    }
}
