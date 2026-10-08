<?php
/**
 * De klant van een snelle bestelling: zoeken in eerdere bestellingen, of nieuw invullen.
 *
 * De meeste klanten bestellen zonder account; daarom wordt er gezocht in de factuurgegevens van eerdere bestellingen
 * (één resultaat per e-mailadres, de nieuwste bestelling telt). Uit de gekozen of ingevulde gegevens wordt een
 * bestelling in het geheugen gemaakt (niet opgeslagen) waarmee de prijzen, de btw en de verzendkosten worden berekend.
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Klant {

    /** De velden van het klantformulier: [ veld => verplicht ]. */
    const VELDEN = [
        'voornaam' => true, 'achternaam' => true, 'bedrijf' => false, 'email' => true, 'telefoon' => false,
        'adres' => true, 'adres2' => false, 'postcode' => true, 'plaats' => true, 'land' => true,
        'taal' => true, 'btw' => false,
        'ander' => false,
        'a_voornaam' => false, 'a_achternaam' => false, 'a_bedrijf' => false, 'a_adres' => false, 'a_adres2' => false,
        'a_postcode' => false, 'a_plaats' => false, 'a_land' => false,
    ];

    public static function init(): void {
        add_action( 'wp_ajax_gtxxl_maak_zoek_klant', [ __CLASS__, 'ajax_zoek' ] );
        add_action( 'wp_ajax_gtxxl_maak_klant', [ __CLASS__, 'ajax_klant' ] );
        add_action( 'wp_ajax_gtxxl_maak_btw', [ __CLASS__, 'ajax_btw' ] );
    }

    /** De landen waar de winkel aan verkoopt: [ code => naam ]. */
    public static function landen(): array {
        return (array) WC()->countries->get_allowed_countries();
    }

    public static function taal_van_land( string $land ): string {
        return in_array( $land, [ 'NL', 'BE' ], true ) ? 'nl' : 'de';
    }

    /* ------------------------------------------------------------------ */
    /* Zoeken                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Zoekt klanten in eerdere bestellingen op naam, bedrijf, e-mail, postcode, plaats of bestelnummer.
     *
     * @return array<int,array{id:int,text:string}> id = de nieuwste bestelling van die klant.
     */
    public static function zoek( string $term ): array {
        global $wpdb;
        $term = trim( $term );
        if ( strlen( $term ) < 2 ) {
            return [];
        }
        $ids = [];
        // Een bestelnummer: die bestelling eerst.
        if ( preg_match( '/^#?v?\d{4,}$/i', $term ) ) {
            foreach ( array_map( 'intval', (array) wc_order_search( ltrim( $term, '#' ) ) ) as $id ) {
                $ids[] = $id;
                if ( count( $ids ) >= 5 ) {
                    break;
                }
            }
        }
        $waar    = [];
        $waarden = [];
        foreach ( array_slice( array_filter( preg_split( '/\s+/', $term ) ), 0, 5 ) as $woord ) {
            $like      = '%' . $wpdb->esc_like( $woord ) . '%';
            $waar[]    = '( a.first_name LIKE %s OR a.last_name LIKE %s OR a.company LIKE %s OR a.email LIKE %s OR a.postcode LIKE %s OR a.city LIKE %s )';
            $waarden   = array_merge( $waarden, [ $like, $like, $like, $like, $like, $like ] );
        }
        if ( $waar ) {
            $sql = $wpdb->prepare(
                "SELECT MAX( o.id ) FROM {$wpdb->prefix}wc_orders o
                 JOIN {$wpdb->prefix}wc_order_addresses a ON a.order_id = o.id AND a.address_type = 'billing'
                 WHERE o.type = 'shop_order' AND o.status NOT IN ( 'trash', 'auto-draft', 'wc-checkout-draft' ) AND a.email <> '' AND " . implode( ' AND ', $waar ) . '
                 GROUP BY a.email ORDER BY MAX( o.id ) DESC LIMIT 25',
                $waarden
            );
            $ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $sql ) ) );
        }

        $uit  = [];
        $mail = [];
        foreach ( array_unique( $ids ) as $id ) {
            $order = wc_get_order( $id );
            if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() || isset( $mail[ strtolower( $order->get_billing_email() ) ] ) ) {
                continue;
            }
            $mail[ strtolower( $order->get_billing_email() ) ] = true;
            $delen = array_filter( [
                trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                $order->get_billing_company(),
                trim( $order->get_billing_postcode() . ' ' . $order->get_billing_city() . ' (' . $order->get_billing_country() . ')' ),
                $order->get_billing_email(),
                '#' . $order->get_order_number() . ( $order->get_date_created() ? ' · ' . $order->get_date_created()->date_i18n( 'd-m-Y' ) : '' ),
            ] );
            $uit[] = [ 'id' => $id, 'text' => html_entity_decode( wp_strip_all_tags( implode( ' · ', $delen ) ), ENT_QUOTES, 'UTF-8' ) ];
        }

        return $uit;
    }

    /** De klantvelden zoals ze op een bestelling staan. */
    public static function velden_van( WC_Order $order ): array {
        $ander = false;
        if ( $order->has_shipping_address() ) {
            foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'country' ] as $veld ) {
                if ( trim( (string) $order->{"get_shipping_{$veld}"}() ) !== trim( (string) $order->{"get_billing_{$veld}"}() ) ) {
                    $ander = true;
                }
            }
        }
        $land = (string) $order->get_billing_country();

        return [
            'voornaam' => $order->get_billing_first_name(), 'achternaam' => $order->get_billing_last_name(), 'bedrijf' => $order->get_billing_company(),
            'email' => $order->get_billing_email(), 'telefoon' => $order->get_billing_phone(),
            'adres' => $order->get_billing_address_1(), 'adres2' => $order->get_billing_address_2(), 'postcode' => $order->get_billing_postcode(), 'plaats' => $order->get_billing_city(), 'land' => $land,
            'taal' => in_array( $order->get_meta( 'wpml_language' ), [ 'nl', 'de' ], true ) ? $order->get_meta( 'wpml_language' ) : self::taal_van_land( $land ),
            'btw' => (string) $order->get_meta( '_billing_vat' ),
            'ander' => $ander ? '1' : '',
            'a_voornaam' => $ander ? $order->get_shipping_first_name() : '', 'a_achternaam' => $ander ? $order->get_shipping_last_name() : '', 'a_bedrijf' => $ander ? $order->get_shipping_company() : '',
            'a_adres' => $ander ? $order->get_shipping_address_1() : '', 'a_adres2' => $ander ? $order->get_shipping_address_2() : '', 'a_postcode' => $ander ? $order->get_shipping_postcode() : '',
            'a_plaats' => $ander ? $order->get_shipping_city() : '', 'a_land' => $ander ? $order->get_shipping_country() : '',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Van velden naar een klant waarmee gerekend wordt                     */
    /* ------------------------------------------------------------------ */

    /**
     * Leest en controleert de klantvelden uit een aanroep.
     *
     * @return array|WP_Error
     */
    public static function lees( array $ruw ) {
        $v = [];
        foreach ( array_keys( self::VELDEN ) as $veld ) {
            $v[ $veld ] = trim( sanitize_text_field( (string) ( $ruw[ $veld ] ?? '' ) ) );
        }
        $email      = $v['email'];
        $v['email'] = sanitize_email( $v['email'] );
        $v['land']  = strtoupper( $v['land'] );
        $v['taal']  = in_array( $v['taal'], [ 'nl', 'de' ], true ) ? $v['taal'] : self::taal_van_land( $v['land'] );
        $v['ander'] = '1' === $v['ander'] ? '1' : '';
        $v['a_land'] = strtoupper( $v['a_land'] );
        $landen     = self::landen();
        $namen      = [
            'voornaam' => __( 'Voornaam', 'gtxxl-maken' ), 'achternaam' => __( 'Achternaam', 'gtxxl-maken' ), 'email' => __( 'E-mailadres', 'gtxxl-maken' ),
            'adres' => __( 'Straat en huisnummer', 'gtxxl-maken' ), 'postcode' => __( 'Postcode', 'gtxxl-maken' ), 'plaats' => __( 'Plaats', 'gtxxl-maken' ), 'land' => __( 'Land', 'gtxxl-maken' ),
        ];
        if ( '' !== $email && ! is_email( $email ) ) {
            return new WP_Error( 'email', __( 'Dit e-mailadres klopt niet.', 'gtxxl-maken' ) );
        }
        foreach ( self::VELDEN as $veld => $verplicht ) {
            if ( $verplicht && '' === $v[ $veld ] && isset( $namen[ $veld ] ) ) {
                /* translators: %s: naam van het veld */
                return new WP_Error( 'veld', sprintf( __( 'Vul in: %s.', 'gtxxl-maken' ), $namen[ $veld ] ) );
            }
        }
        if ( ! is_email( $v['email'] ) ) {
            return new WP_Error( 'email', __( 'Dit e-mailadres klopt niet.', 'gtxxl-maken' ) );
        }
        if ( ! isset( $landen[ $v['land'] ] ) ) {
            return new WP_Error( 'land', __( 'Aan dit land verkopen we niet.', 'gtxxl-maken' ) );
        }
        if ( $v['ander'] ) {
            foreach ( [ 'a_adres', 'a_postcode', 'a_plaats', 'a_land' ] as $veld ) {
                if ( '' === $v[ $veld ] ) {
                    return new WP_Error( 'veld', __( 'Vul het afleveradres helemaal in, of zet "ander afleveradres" uit.', 'gtxxl-maken' ) );
                }
            }
            if ( ! isset( $landen[ $v['a_land'] ] ) ) {
                return new WP_Error( 'land', __( 'Naar dit land verzenden we niet.', 'gtxxl-maken' ) );
            }
        }

        return $v;
    }

    /**
     * Maakt van de klantvelden een bestelling in het geheugen (niet opgeslagen) met adres, taal en btw-gegevens.
     *
     * @param array  $v        Gecontroleerde velden (zie lees()).
     * @param string $levering verzenden, afhalen of geen: bij afhalen geldt er geen btw-vrijstelling.
     * @param bool   $vers     Het btw-nummer opnieuw navragen (bij het maken van de bestelling).
     */
    public static function bestelling( array $v, string $levering = 'verzenden', bool $vers = false ): WC_Order {
        $klant = new WC_Order();
        $fact  = [ 'first_name' => $v['voornaam'], 'last_name' => $v['achternaam'], 'company' => $v['bedrijf'], 'address_1' => $v['adres'], 'address_2' => $v['adres2'], 'city' => $v['plaats'], 'postcode' => $v['postcode'], 'country' => $v['land'], 'state' => '' ];
        $klant->set_address( $fact + [ 'email' => $v['email'], 'phone' => $v['telefoon'] ], 'billing' );
        $klant->set_address( $v['ander'] ? [ 'first_name' => $v['a_voornaam'] ?: $v['voornaam'], 'last_name' => $v['a_achternaam'] ?: $v['achternaam'], 'company' => $v['a_bedrijf'], 'address_1' => $v['a_adres'], 'address_2' => $v['a_adres2'], 'city' => $v['a_plaats'], 'postcode' => $v['a_postcode'], 'country' => $v['a_land'], 'state' => '' ] : $fact, 'shipping' );
        // Heeft dit e-mailadres een account, dan komt de bestelling daar ook onder te staan.
        $user = get_user_by( 'email', $v['email'] );
        $klant->set_customer_id( $user ? (int) $user->ID : 0 );
        $klant->update_meta_data( 'wpml_language', $v['taal'] );
        $klant->update_meta_data( 'is_vat_exempt', 'no' );

        if ( '' !== $v['btw'] ) {
            $klant->update_meta_data( '_billing_vat', $v['btw'] );
            // De controle van de btw-plugin, als die aanstaat: dezelfde regels als bij het afrekenen.
            if ( function_exists( 'gtxxl_btw_evaluate' ) ) {
                // Met het factuuradres erbij: de belastingdienst bevestigt het nummer alleen samen met naam en adres.
                $oordeel = gtxxl_btw_evaluate( $v['btw'], $v['bedrijf'], $klant->get_shipping_country(), 'afhalen' === $levering ? [ 'local_pickup' ] : [], $vers, $v['land'], [ 'city' => $v['plaats'], 'postcode' => $v['postcode'], 'street' => $v['adres'] ] );
                $check   = (array) ( $oordeel['check'] ?? [] );
                $klant->update_meta_data( 'is_vat_exempt', ! empty( $oordeel['exempt'] ) ? 'yes' : 'no' );
                if ( ! empty( $check['full'] ) ) {
                    $klant->update_meta_data( '_billing_vat', $check['full'] );
                    $klant->update_meta_data( '_gtxxl_btw_exempt', ! empty( $oordeel['exempt'] ) ? 'yes' : 'no' );
                    $klant->update_meta_data( '_gtxxl_btw_reason', (string) ( $oordeel['reason'] ?? '' ) );
                    foreach ( [ 'status', 'name', 'address', 'request_id', 'checked_at', 'source' ] as $deel ) {
                        $klant->update_meta_data( '_gtxxl_btw_' . $deel, (string) ( $check[ $deel ] ?? '' ) );
                    }
                    // Het antwoord van het BZSt met wat er is ingestuurd: het bewijs van de bevestiging, zoals de
                    // kassa het ook bij de bestelling bewaart (voor het bewijsblad).
                    $bzst = isset( $check['bzst'] ) && is_array( $check['bzst'] ) ? $check['bzst'] : [];
                    if ( ! empty( $bzst['code'] ) ) {
                        $klant->update_meta_data( '_gtxxl_btw_bzst', [ 'code' => (string) $bzst['code'], 'qualified' => ! empty( $bzst['qualified'] ), 'match' => (array) ( $bzst['match'] ?? [] ), 'sent' => (array) ( $bzst['sent'] ?? [] ), 'raw' => (array) ( $bzst['raw'] ?? [] ) ] );
                    }
                }
                $klant->update_meta_data( '_gtxxl_maken_btw_tekst', wp_strip_all_tags( (string) ( $oordeel['message'] ?? '' ) ) );
            }
        }

        return $klant;
    }

    /** Wat het venster over de btw van deze klant laat zien. */
    public static function btw_tekst( WC_Order $klant ): string {
        if ( '' === (string) $klant->get_meta( '_billing_vat' ) ) {
            return '';
        }
        $tekst = (string) $klant->get_meta( '_gtxxl_maken_btw_tekst' );
        if ( '' !== $tekst ) {
            return $tekst;
        }

        return 'yes' === $klant->get_meta( 'is_vat_exempt' ) ? __( 'Er wordt geen btw berekend.', 'gtxxl-maken' ) : __( 'Btw-nummer genoteerd; de btw wordt berekend.', 'gtxxl-maken' );
    }

    /* ------------------------------------------------------------------ */
    /* Aanroepen vanuit het venster                                         */
    /* ------------------------------------------------------------------ */

    public static function ajax_zoek(): void {
        check_ajax_referer( 'gtxxl_maken', 'nonce' );
        if ( ! GTXXL_Maken::mag() ) {
            wp_send_json_error( __( 'Je mag geen bestellingen maken.', 'gtxxl-maken' ) );
        }
        wp_send_json_success( self::zoek( sanitize_text_field( wp_unslash( $_REQUEST['term'] ?? '' ) ) ) );
    }

    /** De gegevens van de gekozen klant (uit zijn nieuwste bestelling), om het formulier mee te vullen. */
    /**
     * Controleert het btw-nummer terwijl de klant wordt ingevuld, met wat er op dat moment in het formulier staat.
     * Dezelfde controle als bij het afrekenen; het antwoord is wat er onder het veld komt te staan.
     */
    public static function ajax_btw(): void {
        check_ajax_referer( 'gtxxl_maken', 'nonce' );
        if ( ! GTXXL_Maken::mag() ) {
            wp_send_json_error();
        }
        $ruw = (array) wp_unslash( $_POST['klant'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $v   = [];
        foreach ( [ 'btw', 'bedrijf', 'adres', 'postcode', 'plaats', 'land', 'ander', 'a_land' ] as $veld ) {
            $v[ $veld ] = trim( sanitize_text_field( (string) ( $ruw[ $veld ] ?? '' ) ) );
        }
        if ( '' === $v['btw'] || ! function_exists( 'gtxxl_btw_evaluate' ) ) {
            wp_send_json_success( [ 'tekst' => '' ] );
        }
        $naar    = '' !== $v['ander'] && '' !== $v['a_land'] ? $v['a_land'] : $v['land'];
        $afhalen = 'afhalen' === sanitize_key( wp_unslash( $_POST['levering'] ?? '' ) );
        $oordeel = gtxxl_btw_evaluate( $v['btw'], $v['bedrijf'], $naar, $afhalen ? [ 'local_pickup' ] : [], false, $v['land'], [ 'city' => $v['plaats'], 'postcode' => $v['postcode'], 'street' => $v['adres'] ] );
        $vrij    = ! empty( $oordeel['exempt'] );
        $tekst   = wp_strip_all_tags( (string) ( $oordeel['message'] ?? '' ) );
        if ( '' === $tekst ) {
            $tekst = $vrij ? __( 'Er wordt geen btw berekend.', 'gtxxl-maken' ) : __( 'Btw-nummer genoteerd; de btw wordt berekend.', 'gtxxl-maken' );
        }

        wp_send_json_success( [
            'vrij'  => $vrij,
            'soort' => $vrij ? 'ok' : ( 'error' === ( $oordeel['type'] ?? '' ) ? 'error' : 'info' ),
            'tekst' => $tekst,
            // De naam waarop het nummer staat, als die afwijkt van wat is ingevuld.
            'naam'  => wp_strip_all_tags( (string) ( $oordeel['suggest'] ?? '' ) ),
        ] );
    }

    public static function ajax_klant(): void {
        check_ajax_referer( 'gtxxl_maken', 'nonce' );
        $order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
        if ( ! GTXXL_Maken::mag() || ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
            wp_send_json_error( __( 'Klant niet gevonden.', 'gtxxl-maken' ) );
        }
        wp_send_json_success( [ 'velden' => self::velden_van( $order ), 'laatste' => $order->get_order_number() ] );
    }
}
