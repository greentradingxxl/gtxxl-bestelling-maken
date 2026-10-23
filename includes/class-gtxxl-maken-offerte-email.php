<?php
/**
 * De mail aan de klant met de offerte: wat erin zit, tot wanneer ze geldig is, de knop om te accepteren en te betalen
 * en de offerte als pdf in de bijlage (die hangt "PDF Invoices" eraan, zie GTXXL_Maken_Offerte::pdf_instellingen).
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Offerte_Email extends WC_Email {

    /** @var string Het bericht van de medewerker aan de klant. */
    public $bericht = '';

    /** @var string De link om te accepteren en te betalen. */
    public $betaallink = '';

    /** @var string Het offertenummer. */
    public $nummer = '';

    public function __construct() {
        $this->id             = 'gtxxl_offerte';
        $this->customer_email = true;
        $this->title          = __( 'Offerte', 'gtxxl-maken' );
        $this->description    = __( 'Gaat naar de klant als je een snelle offerte maakt (of opnieuw mailt): wat er in de offerte zit, tot wanneer ze geldig is, de knop om te accepteren en te betalen en de offerte als pdf.', 'gtxxl-maken' );
        $this->template_html  = 'emails/gtxxl-offerte.php';
        $this->template_plain = 'emails/plain/gtxxl-offerte.php';
        $this->template_base  = GTXXL_MAKEN_PATH . 'templates/';

        parent::__construct();
    }

    private function nl(): bool {
        return $this->object instanceof WC_Order && 'nl' === $this->object->get_meta( 'wpml_language' );
    }

    // Onderwerp en kop vast per taal van de bestelling: geen vertalingen in WPML nodig.
    public function get_subject() {
        $nummer = '' !== $this->nummer ? $this->nummer : ( $this->object instanceof WC_Order ? $this->object->get_order_number() : '' );

        return $this->nl() ? sprintf( 'Je offerte %s van Green Trading XXL', $nummer ) : sprintf( 'Dein Angebot %s von Green Trading XXL', $nummer );
    }

    public function get_heading() {
        return $this->nl() ? 'Je offerte staat klaar' : 'Dein Angebot ist bereit';
    }

    public function trigger( WC_Order $order, string $bericht = '' ): bool {
        $taal = (string) $order->get_meta( 'wpml_language' );
        if ( $taal ) {
            do_action( 'wpml_switch_language', $taal );
        }
        $this->setup_locale();

        $this->object     = $order;
        $this->bericht    = $bericht;
        $this->betaallink = $order->get_checkout_payment_url();
        $this->recipient  = $order->get_billing_email();
        // De pdf maken voordat de mail wordt opgebouwd: dan staat het nummer in het onderwerp.
        $this->nummer     = GTXXL_Maken_Offerte::nummer( $order, true );

        $verstuurd = false;
        if ( $this->is_enabled() && $this->get_recipient() ) {
            $verstuurd = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }

        $this->restore_locale();
        if ( $taal ) {
            do_action( 'wpml_switch_language', '' );
        }

        return $verstuurd;
    }

    private function gegevens( bool $plat ): array {
        return [
            'order'         => $this->object,
            'bericht'       => $this->bericht,
            'betaallink'    => $this->betaallink,
            'nummer'        => $this->nummer,
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
