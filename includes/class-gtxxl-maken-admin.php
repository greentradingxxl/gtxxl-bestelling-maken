<?php
/**
 * Het venster in de backoffice: "Snelle bestelling" (naast "Bestelling toevoegen") en "Bestelling dupliceren"
 * (onder de producten van een bestelling). Eén venster voor allebei; bij een snelle bestelling komt er een stap
 * "klant" bij en begint de lijst leeg.
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Admin {

    public static function init(): void {
        add_action( 'woocommerce_order_item_add_action_buttons', [ __CLASS__, 'knop_kopie' ], 5 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'scripts' ] );
        add_action( 'admin_footer', [ __CLASS__, 'venster' ] );
        add_action( 'wp_ajax_gtxxl_maak_reken', [ __CLASS__, 'ajax_reken' ] );
        add_action( 'wp_ajax_gtxxl_maak_bestelling', [ __CLASS__, 'ajax_maak' ] );
        add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'melding' ], 19 );
    }

    /** Staat het scherm met de bestellingen open (lijst of één bestelling)? */
    private static function op_scherm(): bool {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        return $screen && in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' ], true ) && GTXXL_Maken::mag();
    }

    /** De bestelling die nu openstaat, of null (lijst, of een nieuwe bestelling). */
    private static function order_van_scherm(): ?WC_Order {
        if ( ! self::op_scherm() ) {
            return null;
        }
        $order = wc_get_order( absint( $_GET['id'] ?? ( $_GET['post'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return $order instanceof WC_Order && 'shop_order' === $order->get_type() ? $order : null;
    }

    public static function knop_kopie( $order ): void {
        if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() && GTXXL_Maken::mag() && $order->get_items( [ 'line_item', 'fee' ] ) ) {
            echo '<button type="button" class="button" id="gtxxl-bm-kopie">' . esc_html( __( 'Bestelling dupliceren', 'gtxxl-maken' ) ) . '</button>';
        }
    }

    /** Op een gemaakte bestelling: waar ze vandaan komt en wat er bijzonder aan is. */
    public static function melding( $order ): void {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $van = wc_get_order( (int) $order->get_meta( GTXXL_Maken::META_VAN ) );
        if ( ! $van && ! GTXXL_Maken::niet_verzenden( $order ) && ! GTXXL_Maken::geen_voorraad( $order ) ) {
            return;
        }
        echo '<div class="gtxxl-wz-blok form-field form-field-wide">';
        if ( $van ) {
            /* translators: %s: koppeling naar de bestelling */
            printf( '<p class="gtxxl-wz-melding">%s</p>', wp_kses( sprintf( __( 'Gedupliceerd van bestelling %s.', 'gtxxl-maken' ), '<a href="' . esc_url( $van->get_edit_order_url() ) . '">#' . esc_html( $van->get_order_number() ) . '</a>' ), [ 'a' => [ 'href' => [] ] ] ) );
        }
        if ( GTXXL_Maken::niet_verzenden( $order ) ) {
            printf( '<p class="gtxxl-wz-melding gtxxl-wz-melding--wacht">%s</p>', esc_html( __( 'Hoeft niet verzonden te worden: na betaling gaat deze bestelling direct naar "Afgerond" en komt ze niet in de inpaklijst.', 'gtxxl-maken' ) ) );
        }
        if ( GTXXL_Maken::geen_voorraad( $order ) ) {
            printf( '<p class="gtxxl-wz-melding gtxxl-wz-melding--wacht">%s</p>', esc_html( __( 'De voorraad wordt voor deze bestelling niet aangepast.', 'gtxxl-maken' ) ) );
        }
        echo '</div>';
    }

    public static function scripts(): void {
        if ( ! self::op_scherm() ) {
            return;
        }
        $order = self::order_van_scherm();
        wp_enqueue_style( 'gtxxl-wijzigen', GTXXL_WIJZIGEN_URL . 'assets/admin.css', [], (string) filemtime( GTXXL_WIJZIGEN_PATH . 'assets/admin.css' ) );
        wp_enqueue_style( 'gtxxl-maken', GTXXL_MAKEN_URL . 'assets/maken.css', [ 'gtxxl-wijzigen' ], (string) filemtime( GTXXL_MAKEN_PATH . 'assets/maken.css' ) );
        GTXXL_Wijzigen_Zoeker::script( $order );
        wp_enqueue_script( 'gtxxl-maken', GTXXL_MAKEN_URL . 'assets/maken.js', [ 'jquery', 'wc-enhanced-select', 'gtxxl-wijzigen-zoeker' ], (string) filemtime( GTXXL_MAKEN_PATH . 'assets/maken.js' ), true );
        $landen = [];
        foreach ( GTXXL_Maken_Klant::landen() as $code => $naam ) {
            $landen[] = [ 'code' => $code, 'naam' => html_entity_decode( (string) $naam, ENT_QUOTES, 'UTF-8' ), 'taal' => GTXXL_Maken_Klant::taal_van_land( $code ) ];
        }
        wp_localize_script( 'gtxxl-maken', 'gtxxlMaken', [
            'ajax'     => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gtxxl_maken' ),
            'valuta'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
            'landen'   => $landen,
            // De bestelling die openstaat, om te dupliceren.
            'kopie'    => $order ? [
                'order_id'      => $order->get_id(),
                'nummer'        => $order->get_order_number(),
                'regels'        => GTXXL_Maken::regels( $order ),
                'levering'      => GTXXL_Maken::levering_was( $order ),
                'verzend_terug' => GTXXL_Maken::verzend_terugbetaald( $order ),
                'terugbetaald'  => (bool) $order->get_refunds(),
                'mail'          => (string) $order->get_billing_email(),
            ] : null,
            // Een gemaakte bestelling houdt de taal van de klant: WooCommerce Multilingual zet anders bij het opslaan de
            // taal van de medewerker.
            'taal'     => $order && in_array( $order->get_created_via(), [ GTXXL_Maken::VIA_KOPIE, GTXXL_Maken::VIA_NIEUW, 'gtxxl_wijzigen' ], true ) ? (string) $order->get_meta( 'wpml_language' ) : '',
            't'        => [
                'knopNieuw'    => __( 'Snelle bestelling', 'gtxxl-maken' ),
                'titelNieuw'   => __( 'Snelle bestelling', 'gtxxl-maken' ),
                /* translators: %s: bestelnummer */
                'titelKopie'   => __( 'Bestelling #%s dupliceren', 'gtxxl-maken' ),
                'doenNieuw'    => __( 'Bestelling maken', 'gtxxl-maken' ),
                'doenKopie'    => __( 'Dupliceren', 'gtxxl-maken' ),
                'minder'       => __( 'Minder', 'gtxxl-maken' ),
                'meer'         => __( 'Meer', 'gtxxl-maken' ),
                'aantal'       => __( 'Aantal', 'gtxxl-maken' ),
                'prijs'        => __( 'Prijs per stuk, inclusief btw', 'gtxxl-maken' ),
                /* translators: %s: bedrag */
                'normaal'      => __( 'Normaal %s', 'gtxxl-maken' ),
                'verwijder'    => __( 'Weghalen', 'gtxxl-maken' ),
                'kosten'       => __( 'Kosten', 'gtxxl-maken' ),
                'verzendkosten' => __( 'Verzendkosten', 'gtxxl-maken' ),
                'afhalen'      => __( 'Afhalen', 'gtxxl-maken' ),
                'gratis'       => __( 'gratis', 'gtxxl-maken' ),
                'rekenen'      => __( 'Even rekenen…', 'gtxxl-maken' ),
                'geenRegels'   => __( 'Er zit nog niets in de bestelling. Zoek hieronder een product.', 'gtxxl-maken' ),
                'eerstKlant'   => __( 'Kies eerst een klant.', 'gtxxl-maken' ),
                'totaal'       => __( 'Totaal', 'gtxxl-maken' ),
                'zonderBtw'    => __( 'zonder btw', 'gtxxl-maken' ),
                'leeg'         => __( 'Kies minstens één product.', 'gtxxl-maken' ),
                'bezig'        => __( 'Bezig…', 'gtxxl-maken' ),
                'mislukt'      => __( 'Het maken van de bestelling is niet gelukt.', 'gtxxl-maken' ),
                'geenVerb'     => __( 'Geen verbinding met de server. Kijk bij de bestellingen of de bestelling is gemaakt voordat je het opnieuw probeert.', 'gtxxl-maken' ),
                'rekenFout'    => __( 'Het berekenen is niet gelukt.', 'gtxxl-maken' ),
                /* translators: %s: e-mailadres */
                'mailNaar'     => __( 'Klant een mail met betaallink sturen (%s)', 'gtxxl-maken' ),
                'mailZonder'   => __( 'Klant een mail met betaallink sturen', 'gtxxl-maken' ),
                'klantZoek'    => __( 'Zoek op naam, bedrijf, e-mail, postcode of bestelnummer…', 'gtxxl-maken' ),
                'kort'         => __( 'Typ minstens 2 tekens…', 'gtxxl-maken' ),
                'zoeken'       => __( 'Zoeken…', 'gtxxl-maken' ),
                'geenKlant'    => __( 'Geen klant gevonden. Kies "Nieuwe klant".', 'gtxxl-maken' ),
                'aanpassen'    => __( 'Aanpassen', 'gtxxl-maken' ),
                'andereKlant'  => __( 'Andere klant', 'gtxxl-maken' ),
                /* translators: %s: bestelnummer */
                'laatste'      => __( 'laatste bestelling #%s', 'gtxxl-maken' ),
                'nieuweKlant'  => __( 'nieuwe klant', 'gtxxl-maken' ),
                'aflever'      => __( 'Afleveradres', 'gtxxl-maken' ),
                'vulIn'        => __( 'Vul de verplichte velden in (met een sterretje).', 'gtxxl-maken' ),
                'nl'           => __( 'Nederlands', 'gtxxl-maken' ),
                'de'           => __( 'Duits', 'gtxxl-maken' ),
            ],
        ] );
    }

    private static function veld( string $naam, string $label, bool $verplicht = false, string $soort = 'text', string $klasse = '' ): void {
        printf(
            '<label class="gtxxl-bm__veld %4$s"><span>%2$s%3$s</span><input type="%5$s" data-veld="%1$s" autocomplete="off"></label>',
            esc_attr( $naam ),
            esc_html( $label ),
            $verplicht ? ' *' : '',
            esc_attr( $klasse ),
            esc_attr( $soort )
        );
    }

    private static function landveld( string $naam, string $label, bool $verplicht ): void {
        echo '<label class="gtxxl-bm__veld"><span>' . esc_html( $label ) . ( $verplicht ? ' *' : '' ) . '</span><select data-veld="' . esc_attr( $naam ) . '">';
        foreach ( GTXXL_Maken_Klant::landen() as $code => $land ) {
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( html_entity_decode( (string) $land, ENT_QUOTES, 'UTF-8' ) ) . '</option>';
        }
        echo '</select></label>';
    }

    public static function venster(): void {
        if ( ! self::op_scherm() ) {
            return;
        }
        ?>
        <div id="gtxxl-bm-overlay" class="gtxxl-wz-overlay" style="display:none;">
            <div class="gtxxl-wz gtxxl-bm" role="dialog" aria-modal="true" aria-labelledby="gtxxl-bm-titel">
                <div class="gtxxl-wz__kop">
                    <h2 id="gtxxl-bm-titel"></h2>
                    <button type="button" class="gtxxl-wz__sluit" aria-label="<?php echo esc_attr( __( 'Sluiten', 'gtxxl-maken' ) ); ?>">&times;</button>
                </div>
                <div class="gtxxl-wz__inhoud">
                    <div class="gtxxl-wz__links">

                        <div class="gtxxl-bm__klant" data-alleen="nieuw">
                            <h3><?php echo esc_html( __( 'Klant', 'gtxxl-maken' ) ); ?></h3>
                            <div class="gtxxl-bm__klant-zoek">
                                <select id="gtxxl-bm-klant-zoek" style="width:100%;"></select>
                                <button type="button" class="button" id="gtxxl-bm-klant-nieuw"><?php echo esc_html( __( 'Nieuwe klant', 'gtxxl-maken' ) ); ?></button>
                            </div>
                            <div class="gtxxl-bm__klant-kaart" style="display:none;"></div>
                            <div class="gtxxl-bm__klant-form" style="display:none;">
                                <div class="gtxxl-bm__rij">
                                    <?php
                                    self::veld( 'voornaam', __( 'Voornaam', 'gtxxl-maken' ), true );
                                    self::veld( 'achternaam', __( 'Achternaam', 'gtxxl-maken' ), true );
                                    self::veld( 'bedrijf', __( 'Bedrijf', 'gtxxl-maken' ) );
                                    ?>
                                </div>
                                <div class="gtxxl-bm__rij">
                                    <?php
                                    self::veld( 'email', __( 'E-mailadres', 'gtxxl-maken' ), true, 'email', 'is-breed' );
                                    self::veld( 'telefoon', __( 'Telefoon', 'gtxxl-maken' ), false, 'tel' );
                                    ?>
                                </div>
                                <div class="gtxxl-bm__rij">
                                    <?php
                                    self::veld( 'adres', __( 'Straat en huisnummer', 'gtxxl-maken' ), true, 'text', 'is-breed' );
                                    self::veld( 'adres2', __( 'Toevoeging', 'gtxxl-maken' ) );
                                    ?>
                                </div>
                                <div class="gtxxl-bm__rij">
                                    <?php
                                    self::veld( 'postcode', __( 'Postcode', 'gtxxl-maken' ), true );
                                    self::veld( 'plaats', __( 'Plaats', 'gtxxl-maken' ), true );
                                    self::landveld( 'land', __( 'Land', 'gtxxl-maken' ), true );
                                    ?>
                                </div>
                                <div class="gtxxl-bm__rij">
                                    <label class="gtxxl-bm__veld"><span><?php echo esc_html( __( 'Taal van mails en factuur', 'gtxxl-maken' ) ); ?> *</span>
                                        <select data-veld="taal"><option value="nl"><?php echo esc_html( __( 'Nederlands', 'gtxxl-maken' ) ); ?></option><option value="de"><?php echo esc_html( __( 'Duits', 'gtxxl-maken' ) ); ?></option></select>
                                    </label>
                                    <?php self::veld( 'btw', __( 'Btw-nummer (zakelijk)', 'gtxxl-maken' ), false, 'text', 'is-breed' ); ?>
                                </div>
                                <label class="gtxxl-wz__vink"><input type="checkbox" data-veld="ander"> <?php echo esc_html( __( 'Ander afleveradres', 'gtxxl-maken' ) ); ?></label>
                                <div class="gtxxl-bm__aflever" style="display:none;">
                                    <div class="gtxxl-bm__rij">
                                        <?php
                                        self::veld( 'a_voornaam', __( 'Voornaam', 'gtxxl-maken' ) );
                                        self::veld( 'a_achternaam', __( 'Achternaam', 'gtxxl-maken' ) );
                                        self::veld( 'a_bedrijf', __( 'Bedrijf', 'gtxxl-maken' ) );
                                        ?>
                                    </div>
                                    <div class="gtxxl-bm__rij">
                                        <?php
                                        self::veld( 'a_adres', __( 'Straat en huisnummer', 'gtxxl-maken' ), true, 'text', 'is-breed' );
                                        self::veld( 'a_adres2', __( 'Toevoeging', 'gtxxl-maken' ) );
                                        ?>
                                    </div>
                                    <div class="gtxxl-bm__rij">
                                        <?php
                                        self::veld( 'a_postcode', __( 'Postcode', 'gtxxl-maken' ), true );
                                        self::veld( 'a_plaats', __( 'Plaats', 'gtxxl-maken' ), true );
                                        self::landveld( 'a_land', __( 'Land', 'gtxxl-maken' ), true );
                                        ?>
                                    </div>
                                </div>
                                <p class="gtxxl-bm__klant-knoppen">
                                    <button type="button" class="button button-primary" id="gtxxl-bm-klant-klaar"><?php echo esc_html( __( 'Deze klant gebruiken', 'gtxxl-maken' ) ); ?></button>
                                    <button type="button" class="button-link" id="gtxxl-bm-klant-terug"><?php echo esc_html( __( 'Annuleren', 'gtxxl-maken' ) ); ?></button>
                                </p>
                            </div>
                        </div>

                        <div class="gtxxl-bm__producten">
                            <h3><?php echo esc_html( __( 'Producten', 'gtxxl-maken' ) ); ?></h3>
                            <p class="gtxxl-bm__snel" data-alleen="kopie">
                                <button type="button" class="button-link" id="gtxxl-bm-terugbetaald"><?php echo esc_html( __( 'Alleen wat is terugbetaald', 'gtxxl-maken' ) ); ?></button><span class="gtxxl-bm__punt"> · </span>
                                <button type="button" class="button-link" id="gtxxl-bm-alles"><?php echo esc_html( __( 'Alles terugzetten', 'gtxxl-maken' ) ); ?></button>
                            </p>
                            <div class="gtxxl-bm__lijst"><table class="gtxxl-wz__regels"><tbody></tbody></table></div>
                            <div class="gtxxl-wz__zoek">
                                <label for="gtxxl-bm-zoek"><?php echo esc_html( __( 'Product toevoegen', 'gtxxl-maken' ) ); ?></label>
                                <select id="gtxxl-bm-zoek" class="gtxxl-wz-zoeker" style="width:100%;" data-placeholder="<?php echo esc_attr( __( 'Zoek op naam of artikelnummer…', 'gtxxl-maken' ) ); ?>"></select>
                            </div>
                            <p class="gtxxl-wz__uitleg" data-alleen="kopie"><?php echo esc_html( __( 'Wat uit de oude bestelling komt, houdt de prijs die de klant destijds betaalde; wat je toevoegt, krijgt de prijs van nu. De prijs per stuk kun je aanpassen.', 'gtxxl-maken' ) ); ?></p>
                            <p class="gtxxl-wz__uitleg" data-alleen="nieuw"><?php echo esc_html( __( 'Producten krijgen de prijs van nu; de prijs per stuk kun je aanpassen. De bestelling krijgt de status "Wachtend op betaling".', 'gtxxl-maken' ) ); ?></p>
                        </div>
                    </div>

                    <div class="gtxxl-wz__rechts">
                        <h3><?php echo esc_html( __( 'Levering', 'gtxxl-maken' ) ); ?></h3>
                        <div class="gtxxl-bm__keuze" id="gtxxl-bm-levering">
                            <label><input type="radio" name="gtxxl-bm-levering" value="verzenden"> <?php echo esc_html( __( 'Verzenden (kosten automatisch berekend)', 'gtxxl-maken' ) ); ?></label>
                            <label><input type="radio" name="gtxxl-bm-levering" value="afhalen"> <?php echo esc_html( __( 'Afhalen in de winkel', 'gtxxl-maken' ) ); ?></label>
                            <label><input type="radio" name="gtxxl-bm-levering" value="geen"> <?php echo esc_html( __( 'Geen verzendkosten', 'gtxxl-maken' ) ); ?></label>
                        </div>
                        <div data-alleen="kopie">
                            <label class="gtxxl-wz__vink"><input type="checkbox" id="gtxxl-bm-niet-verzenden"> <?php echo esc_html( __( 'Bestelling hoeft niet meer verzonden te worden', 'gtxxl-maken' ) ); ?></label>
                            <p class="gtxxl-wz__klein"><?php echo esc_html( __( 'Na betaling gaat ze direct naar "Afgerond" en komt ze niet bij de inpakkers.', 'gtxxl-maken' ) ); ?></p>
                        </div>
                        <h3><?php echo esc_html( __( 'Voorraad', 'gtxxl-maken' ) ); ?></h3>
                        <div class="gtxxl-bm__keuze">
                            <label><input type="radio" name="gtxxl-bm-voorraad" value="" checked> <?php echo esc_html( __( 'Afboeken als de bestelling betaald is', 'gtxxl-maken' ) ); ?></label>
                            <label><input type="radio" name="gtxxl-bm-voorraad" value="nu"> <?php echo esc_html( __( 'Nu al afboeken', 'gtxxl-maken' ) ); ?></label>
                            <label><input type="radio" name="gtxxl-bm-voorraad" value="niet"> <?php echo esc_html( __( 'Niet aanpassen', 'gtxxl-maken' ) ); ?></label>
                        </div>
                        <h3><?php echo esc_html( __( 'Betaling', 'gtxxl-maken' ) ); ?></h3>
                        <label class="gtxxl-wz__vink"><input type="checkbox" id="gtxxl-bm-mail"> <span id="gtxxl-bm-mail-tekst"></span></label>
                        <textarea id="gtxxl-bm-bericht" rows="3" placeholder="<?php echo esc_attr( __( 'Bericht aan de klant (optioneel)…', 'gtxxl-maken' ) ); ?>"></textarea>
                        <div class="gtxxl-wz__totaal gtxxl-bm__totaal"></div>
                        <div class="gtxxl-bm__melding"></div>
                        <button type="button" class="button button-primary button-large" id="gtxxl-bm-doen"></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Aanroepen vanuit het venster                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Leest de aanroep: de klant (bestelling om mee te rekenen), de bestelling die gekopieerd wordt (of null) en de keuzes.
     *
     * @param bool $vers Het btw-nummer van een nieuwe klant opnieuw navragen.
     * @return array{0:WC_Order,1:?WC_Order,2:array}
     */
    private static function invoer( bool $vers ): array {
        check_ajax_referer( 'gtxxl_maken', 'nonce' );
        if ( ! GTXXL_Maken::mag() ) {
            wp_send_json_error( __( 'Je mag geen bestellingen maken.', 'gtxxl-maken' ) );
        }
        $lijst = static function ( $sleutel ) {
            $uit = [];
            foreach ( (array) wp_unslash( $_POST[ $sleutel ] ?? [] ) as $id => $keuze ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $prijs = is_array( $keuze ) ? (string) ( $keuze['prijs'] ?? '' ) : '';
                $uit[ absint( $id ) ] = [
                    'aantal' => max( 0, min( 9999, (int) ( is_array( $keuze ) ? ( $keuze['aantal'] ?? 0 ) : $keuze ) ) ),
                    'prijs'  => '' === $prijs ? null : max( 0.0, min( 999999.0, (float) str_replace( ',', '.', $prijs ) ) ),
                ];
            }

            return $uit;
        };
        $in = [
            'aantallen'      => $lijst( 'aantallen' ),
            'extra'          => $lijst( 'extra' ),
            'overig'         => array_map( 'absint', (array) wp_unslash( $_POST['overig'] ?? [] ) ),
            'levering'       => sanitize_key( wp_unslash( $_POST['levering'] ?? 'geen' ) ),
            'niet_verzenden' => '1' === (string) ( $_POST['niet_verzenden'] ?? '0' ),
            'voorraad'       => sanitize_key( wp_unslash( $_POST['voorraad'] ?? '' ) ),
            'mail'           => '1' === (string) ( $_POST['mail'] ?? '0' ),
            'bericht'        => sanitize_textarea_field( wp_unslash( $_POST['bericht'] ?? '' ) ),
        ];
        if ( 'kopie' === ( $_POST['soort'] ?? '' ) ) {
            $origineel = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
            if ( ! $origineel instanceof WC_Order || 'shop_order' !== $origineel->get_type() ) {
                wp_send_json_error( __( 'Bestelling niet gevonden.', 'gtxxl-maken' ) );
            }

            return [ $origineel, $origineel, $in ];
        }
        $in['aantallen']      = [];
        $in['overig']         = [];
        $in['niet_verzenden'] = false;
        $velden = GTXXL_Maken_Klant::lees( (array) wp_unslash( $_POST['klant'] ?? [] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( is_wp_error( $velden ) ) {
            wp_send_json_error( [ 'code' => 'klant', 'tekst' => $velden->get_error_message() ] );
        }

        return [ GTXXL_Maken_Klant::bestelling( $velden, $in['levering'], $vers ), null, $in ];
    }

    /** Rekent uit wat er nu in het venster gekozen is: regels, verzendkosten, toeslag en totaal. */
    public static function ajax_reken(): void {
        [ $klant, $origineel, $in ] = self::invoer( false );
        if ( $in['niet_verzenden'] ) {
            $in['levering'] = 'geen';
        }
        $plan = GTXXL_Maken::plan( $klant, $origineel, $in );
        if ( is_wp_error( $plan ) ) {
            wp_send_json_error( [ 'code' => $plan->get_error_code(), 'tekst' => $plan->get_error_message() ] );
        }
        $regels = [];
        foreach ( $plan['regels'] as $r ) {
            $regels[] = [ 'sleutel' => $r['sleutel'], 'naam' => $r['naam'], 'sku' => $r['sku'], 'foto' => $r['foto'], 'stuk' => $r['stuk'], 'standaard' => $r['standaard'] ];
        }
        $v = $plan['verzend'];
        wp_send_json_success( [
            'regels'    => $regels,
            'verzend'   => $v ? [ 'kan' => ! empty( $v['kan'] ), 'afhalen' => ! empty( $v['afhalen'] ), 'naam' => (string) ( $v['naam'] ?? '' ), 'reden' => (string) ( $v['reden'] ?? '' ), 'bedrag' => (float) $v['bruto'], 'gewicht' => str_replace( '.', ',', (string) round( (float) ( $v['gewicht'] ?? 0 ), 1 ) ) ] : null,
            'toeslag'   => $plan['toeslag'] ? [ 'naam' => $plan['toeslag']['naam'], 'bedrag' => (float) $plan['toeslag']['bruto'], 'stuks' => (int) $plan['toeslag']['stuks'] ] : null,
            'totaal'    => $plan['totaal'],
            'btw_vrij'  => (bool) $plan['btw_vrij'],
            'let_op'    => array_values( (array) $plan['let_op'] ),
            'btw_tekst' => $origineel ? '' : GTXXL_Maken_Klant::btw_tekst( $klant ),
        ] );
    }

    public static function ajax_maak(): void {
        [ $klant, $origineel, $in ] = self::invoer( true );
        $nieuw = GTXXL_Maken::maak( $klant, $origineel, $in );
        if ( is_wp_error( $nieuw ) ) {
            wp_send_json_error( [ 'code' => $nieuw->get_error_code(), 'tekst' => $nieuw->get_error_message() ] );
        }
        wp_send_json_success( [ 'url' => html_entity_decode( $nieuw->get_edit_order_url() ), 'nummer' => $nieuw->get_order_number() ] );
    }
}
