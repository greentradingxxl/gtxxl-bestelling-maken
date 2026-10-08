<?php
/**
 * Een bestelling maken vanuit de backoffice: nieuw voor een klant, of als kopie van een bestaande bestelling.
 *
 * Beide gaan door dezelfde rekenronde (plan): de producten met hun prijs, de verzendkosten of afhalen, de
 * substratentoeslag en het totaal. Wat uit een bestaande bestelling komt, houdt de prijs die de klant destijds
 * betaalde; wat erbij komt, krijgt de prijs van nu. Per product kan een afwijkende prijs worden opgegeven.
 * De nieuwe bestelling krijgt de status "Wachtend op betaling".
 *
 * Voor de prijs met btw en de verzendkosten gelden de functies van "GTXXL Bestelling wijzigen".
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken {

    /** Op de nieuwe bestelling: de bestelling waar ze van gekopieerd is. */
    const META_VAN = '_gtxxl_dup_van';
    /** Op de nieuwe bestelling: ze hoeft niet verzonden te worden. */
    const META_NIET_VERZENDEN = '_gtxxl_niet_verzenden';
    /** Op de nieuwe bestelling: de voorraad wordt voor deze bestelling niet aangepast. */
    const META_GEEN_VOORRAAD = '_gtxxl_geen_voorraad';

    const VIA_KOPIE = 'gtxxl_dupliceren';
    const VIA_NIEUW = 'gtxxl_snelle_bestelling';

    public static function init(): void {
        // Hoeft niet verzonden te worden: nooit in de inpaklijst, en na betaling meteen afgerond.
        add_filter( 'gtxxl_packgo_hold', [ __CLASS__, 'packgo_niet' ], 10, 2 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'direct_afronden' ], 999, 3 );
        // Er is niets verzonden: de mail "bestelling afgerond" gaat voor zo'n bestelling niet uit.
        add_filter( 'woocommerce_email_enabled_customer_completed_order', [ __CLASS__, 'geen_afgerond_mail' ], 99, 2 );

        // Voorraad niet aanpassen: niet bij betaling, en ook niet als er later in het bestelscherm een regel verandert.
        add_filter( 'woocommerce_can_reduce_order_stock', [ __CLASS__, 'voorraad_mag' ], 20, 2 );
        add_filter( 'woocommerce_can_restore_order_stock', [ __CLASS__, 'voorraad_mag' ], 20, 2 );
        add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', [ __CLASS__, 'voorraad_regel_vast' ], 20, 2 );

        // De orderbevestiging van een bestelling zonder zending: geen "we gaan voor je aan de slag".
        add_filter( 'woocommerce_email_subject_customer_processing_order', [ __CLASS__, 'mail_onderwerp' ], 30, 2 );
        add_filter( 'woocommerce_email_heading_customer_processing_order', [ __CLASS__, 'mail_kop' ], 30, 2 );
        add_action( 'woocommerce_email_header', [ __CLASS__, 'mail_begin' ], 6, 2 );
        add_action( 'woocommerce_email_footer', [ __CLASS__, 'mail_einde' ], 998 );
    }

    public static function mag(): bool {
        return current_user_can( 'edit_shop_orders' );
    }

    public static function niet_verzenden( $order ): bool {
        return $order instanceof WC_Order && '1' === (string) $order->get_meta( self::META_NIET_VERZENDEN );
    }

    public static function geen_voorraad( $order ): bool {
        return $order instanceof WC_Order && '1' === (string) $order->get_meta( self::META_GEEN_VOORRAAD );
    }

    public static function is_afhalen( $order ): bool {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $order->get_shipping_methods() as $methode ) {
            if ( in_array( $methode->get_method_id(), [ 'local_pickup', 'pickup_location' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    public static function bedrag( float $bedrag, WC_Order $order ): string {
        return html_entity_decode( wp_strip_all_tags( wc_price( $bedrag, [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
    }

    private static function is_toeslag( WC_Order_Item_Fee $fee ): bool {
        return GTXXL_Wijzigen_Plan::is_toeslag_naam( (string) $fee->get_name() );
    }

    /* ------------------------------------------------------------------ */
    /* Wat er uit een bestaande bestelling te kopiëren valt                 */
    /* ------------------------------------------------------------------ */

    /**
     * De regels van een bestelling voor het venster: producten en kosten, met wat de klant ervoor betaalde en wat ervan
     * is terugbetaald. Verzendkosten en de substratentoeslag staan er niet bij: die worden opnieuw berekend.
     */
    public static function regels( WC_Order $order ): array {
        $regels = [];
        foreach ( $order->get_items() as $id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product  = $item->get_product();
            $aantal   = max( 1, (int) $item->get_quantity() );
            $regels[] = [
                'id'      => (int) $id,
                'soort'   => 'product',
                'naam'    => wp_strip_all_tags( $item->get_name() ),
                'sku'     => $product ? (string) $product->get_sku() : '',
                'aantal'  => $aantal,
                'terug'   => abs( (int) $order->get_qty_refunded_for_item( $id ) ),
                'stuk'    => round( ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $aantal, 2 ),
                'bestaat' => (bool) $product,
            ] + GTXXL_Wijzigen_Plan::foto( $product );
        }
        foreach ( $order->get_items( 'fee' ) as $id => $item ) {
            $bruto = round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
            // Hulpregels van "Bestelling wijzigen" (verwijzing van 0 euro, verrekening) horen niet in een kopie.
            if ( $bruto <= 0 || $item->get_meta( '_gtxxl_wijzig_verrekening' ) || self::is_toeslag( $item ) ) {
                continue;
            }
            $terug = (float) $order->get_total_refunded_for_item( $id, 'fee' );
            foreach ( array_keys( (array) ( $item->get_taxes()['total'] ?? [] ) ) as $rate_id ) {
                $terug += (float) $order->get_tax_refunded_for_item( $id, $rate_id, 'fee' );
            }
            $regels[] = [ 'id' => (int) $id, 'soort' => 'fee', 'naam' => wp_strip_all_tags( $item->get_name() ), 'bedrag' => $bruto, 'terug' => round( $terug, 2 ) ];
        }

        return $regels;
    }

    /** Hoe de bestelling geleverd werd, als voorzet voor de kopie: verzenden, afhalen of geen. */
    public static function levering_was( WC_Order $order ): string {
        if ( self::is_afhalen( $order ) ) {
            return 'afhalen';
        }

        return $order->get_items( 'shipping' ) ? 'verzenden' : 'geen';
    }

    /** Is er van de verzendkosten van deze bestelling iets terugbetaald? */
    public static function verzend_terugbetaald( WC_Order $order ): bool {
        $terug = 0.0;
        foreach ( $order->get_items( 'shipping' ) as $id => $item ) {
            $terug += (float) $order->get_total_refunded_for_item( $id, 'shipping' );
        }

        return $terug > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Rekenen                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Een product dat erbij komt, in de taal van de bestelling: zo staat de juiste naam op de factuur, ook als in het
     * beheerscherm de versie in de andere taal is gekozen.
     *
     * @return WC_Product|WP_Error
     */
    private static function product_in_taal( WC_Order $klant, int $gevraagd ) {
        $taal     = (string) $klant->get_meta( 'wpml_language' );
        $vertaald = $taal ? (int) apply_filters( 'wpml_object_id', $gevraagd, get_post_type( $gevraagd ) ?: 'product', true, $taal ) : $gevraagd;
        $product  = wc_get_product( $vertaald ?: $gevraagd );
        if ( ! $product || ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
            return new WP_Error( 'product', __( 'Een van de toegevoegde producten kan niet besteld worden.', 'gtxxl-maken' ) );
        }

        return $product;
    }

    /**
     * Een bedrag inclusief btw uitsplitsen voor deze klant: zonder btw en de btw per tarief.
     *
     * @return array{netto:float,tax:array<int,float>}
     */
    private static function uit_bruto( WC_Order $klant, string $tax_class, bool $belast, float $bruto ): array {
        $tax = [];
        if ( $belast && wc_tax_enabled() && ! GTXXL_Wijzigen_Plan::btw_vrij( $klant ) ) {
            $tarieven = WC_Tax::find_rates( GTXXL_Wijzigen_Plan::btw_plaats( $klant ) + [ 'tax_class' => $tax_class ] );
            foreach ( WC_Tax::calc_tax( $bruto, $tarieven, true ) as $id => $bedrag ) {
                $tax[ (int) $id ] = (float) wc_round_tax_total( $bedrag );
            }
        }

        return [ 'netto' => $bruto - array_sum( $tax ), 'tax' => $tax ];
    }

    private static function gewicht( $product, int $aantal ): float {
        return $product instanceof WC_Product && $product->needs_shipping() ? (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) * $aantal : 0.0;
    }

    /**
     * Rekent uit wat er in de nieuwe bestelling komt.
     *
     * @param WC_Order      $klant     De klant: bij een kopie de oorspronkelijke bestelling, bij een nieuwe bestelling een
     *                                 (niet opgeslagen) bestelling met adres, taal en btw-gegevens.
     * @param WC_Order|null $origineel De bestelling waar regels uit worden overgenomen, of null.
     * @param array         $in        aantallen [ regel => [ aantal, prijs|null ] ], extra [ product => [ aantal, prijs|null ] ],
     *                                 overig (kostenregels), levering (verzenden|afhalen|geen).
     * @return array|WP_Error
     */
    public static function plan( WC_Order $klant, ?WC_Order $origineel, array $in ) {
        // De levering staat nog niet in een bestelling; voor de btw telt of er wordt afgehaald (btw van de winkel).
        $vast = property_exists( 'GTXXL_Wijzigen_Plan', 'afhalen_vast' );
        if ( $vast ) {
            GTXXL_Wijzigen_Plan::$afhalen_vast = 'afhalen' === ( $in['levering'] ?? '' );
        }
        try {
            return self::plan_reken( $klant, $origineel, $in );
        } finally {
            if ( $vast ) {
                GTXXL_Wijzigen_Plan::$afhalen_vast = null;
            }
        }
    }

    private static function plan_reken( WC_Order $klant, ?WC_Order $origineel, array $in ) {
        $levering = in_array( $in['levering'] ?? '', [ 'verzenden', 'afhalen' ], true ) ? $in['levering'] : 'geen';
        $nl       = 'nl' === $klant->get_meta( 'wpml_language' );
        $regels   = [];
        $gewicht  = 0.0;
        $toeslag_stuks = 0;
        $let_op   = [];

        // Uit de bestaande bestelling: de prijs die de klant destijds betaalde, tenzij er een andere is opgegeven.
        foreach ( (array) ( $in['aantallen'] ?? [] ) as $item_id => $keuze ) {
            $aantal = (int) ( $keuze['aantal'] ?? 0 );
            $item   = $origineel ? $origineel->get_item( (int) $item_id ) : null;
            if ( $aantal < 1 || ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product  = $item->get_product();
            $besteld  = max( 1, (int) $item->get_quantity() );
            $per_tax  = array_map( static function ( $b ) use ( $besteld ) { return (float) $b / $besteld; }, (array) ( $item->get_taxes()['total'] ?? [] ) );
            $per      = (float) $item->get_total() / $besteld;
            $standaard = round( $per + array_sum( $per_tax ), 2 );
            $prijs    = isset( $keuze['prijs'] ) && '' !== $keuze['prijs'] && null !== $keuze['prijs'] ? max( 0.0, round( (float) $keuze['prijs'], 2 ) ) : null;
            if ( null !== $prijs && abs( $prijs - $standaard ) >= 0.005 ) {
                $deel = self::uit_bruto( $klant, (string) $item->get_tax_class(), 'taxable' === $item->get_tax_status(), $prijs * $aantal );
            } else {
                // Dezelfde prijs als toen, met de btw van nu: het tarief kan sinds de oorspronkelijke bestelling
                // veranderd zijn (btw van het land van de klant).
                $prijs = $standaard;
                $deel  = self::uit_bruto( $klant, (string) $item->get_tax_class(), 'taxable' === $item->get_tax_status(), ( $per + array_sum( $per_tax ) ) * $aantal );
            }
            $regels[] = [ 'sleutel' => 'r' . (int) $item_id, 'item' => $item, 'product' => $product, 'naam' => wp_strip_all_tags( $item->get_name() ), 'sku' => $product ? (string) $product->get_sku() : '', 'aantal' => $aantal, 'stuk' => $prijs, 'standaard' => $standaard, 'tax_class' => (string) $item->get_tax_class() ] + $deel + GTXXL_Wijzigen_Plan::foto( $product );
            $gewicht += self::gewicht( $product, $aantal );
            $toeslag_stuks += GTXXL_Wijzigen_Plan::toeslag_geldt( $product ) ? $aantal : 0;
        }

        // Wat erbij komt: de prijs van nu met de btw van deze klant, tenzij er een andere is opgegeven.
        foreach ( (array) ( $in['extra'] ?? [] ) as $product_id => $keuze ) {
            $aantal = (int) ( $keuze['aantal'] ?? 0 );
            if ( $aantal < 1 ) {
                continue;
            }
            $product = self::product_in_taal( $klant, (int) $product_id );
            if ( is_wp_error( $product ) ) {
                return $product;
            }
            // Te weinig voorraad houdt de medewerker niet tegen (er kan iets afgesproken zijn), maar het venster meldt het wel.
            if ( $product->managing_stock() && $product->get_stock_quantity() < $aantal ) {
                /* translators: 1: productnaam, 2: aantal op voorraad */
                $let_op[] = sprintf( __( 'Van "%1$s" zijn er maar %2$d op voorraad.', 'gtxxl-maken' ), wp_strip_all_tags( $product->get_name() ), max( 0, (int) $product->get_stock_quantity() ) );
            }
            $standaard = (float) GTXXL_Wijzigen_Plan::prijs( $klant, $product, 1 )['bruto'];
            $prijs     = isset( $keuze['prijs'] ) && '' !== $keuze['prijs'] && null !== $keuze['prijs'] ? max( 0.0, round( (float) $keuze['prijs'], 2 ) ) : null;
            if ( null !== $prijs && abs( $prijs - $standaard ) >= 0.005 ) {
                $deel = self::uit_bruto( $klant, (string) $product->get_tax_class(), $product->is_taxable(), $prijs * $aantal );
            } else {
                $prijs = $standaard;
                $nu    = GTXXL_Wijzigen_Plan::prijs( $klant, $product, $aantal );
                $deel  = [ 'netto' => (float) $nu['netto'], 'tax' => (array) $nu['tax'] ];
            }
            $regels[] = [ 'sleutel' => 'p' . (int) $product_id, 'item' => null, 'product' => $product, 'naam' => wp_strip_all_tags( $product->get_name() ), 'sku' => (string) $product->get_sku(), 'aantal' => $aantal, 'stuk' => $prijs, 'standaard' => $standaard, 'tax_class' => (string) $product->get_tax_class() ] + $deel + GTXXL_Wijzigen_Plan::foto( $product );
            $gewicht += self::gewicht( $product, $aantal );
            $toeslag_stuks += GTXXL_Wijzigen_Plan::toeslag_geldt( $product ) ? $aantal : 0;
        }

        // Kostenregels uit de bestaande bestelling, zoals de klant ze toen betaalde.
        $kosten = [];
        $overig = array_map( 'intval', (array) ( $in['overig'] ?? [] ) );
        foreach ( $origineel ? $origineel->get_items( 'fee' ) : [] as $id => $fee ) {
            if ( in_array( (int) $id, $overig, true ) && ! self::is_toeslag( $fee ) ) {
                $kosten[] = [ 'fee' => $fee, 'bruto' => (float) $fee->get_total() + (float) $fee->get_total_tax() ];
            }
        }

        $verzend = null;
        $toeslag = null;
        if ( $regels && 'verzenden' === $levering ) {
            $prijs = GTXXL_Wijzigen_Plan::verzendprijs( $klant, $gewicht );
            $verzend = $prijs
                ? [ 'kan' => true, 'naam' => wp_strip_all_tags( (string) $prijs['label'] ), 'gewicht' => $gewicht ] + $prijs
                : [ 'kan' => false, 'reden' => __( 'Voor dit land of gewicht staat er geen verzendtarief in de tabel.', 'gtxxl-maken' ), 'netto' => 0.0, 'tax' => [], 'bruto' => 0.0 ];
            // De substratentoeslag hoort bij verzenden; bij afhalen is ze er niet.
            $per = function_exists( 'gtxxl_bijkomende_kosten' ) ? (float) gtxxl_bijkomende_kosten()['per'] : 0.0;
            if ( $toeslag_stuks > 0 && $per > 0 ) {
                $netto = $per * $toeslag_stuks;
                $tax   = [];
                if ( wc_tax_enabled() && ! GTXXL_Wijzigen_Plan::btw_vrij( $klant ) ) {
                    foreach ( WC_Tax::calc_tax( $netto, WC_Tax::find_rates( GTXXL_Wijzigen_Plan::btw_plaats( $klant ) + [ 'tax_class' => '' ] ), false ) as $id => $bedrag ) {
                        $tax[ (int) $id ] = (float) wc_round_tax_total( $bedrag );
                    }
                }
                $toeslag = [ 'naam' => GTXXL_Wijzigen_Plan::toeslag_naam( $nl ), 'stuks' => $toeslag_stuks, 'netto' => $netto, 'tax' => $tax, 'bruto' => round( $netto + array_sum( $tax ), 2 ) ];
            }
        } elseif ( $regels && 'afhalen' === $levering ) {
            $verzend = [ 'kan' => true, 'afhalen' => true, 'naam' => $nl ? 'Afhalen bij Green Trading XXL' : 'Abholung bei Green Trading XXL', 'netto' => 0.0, 'tax' => [], 'bruto' => 0.0, 'gewicht' => $gewicht ];
        }

        // Het totaal zoals WooCommerce het voor de bestelling optelt: regels en btw onafgerond bij elkaar, alleen de
        // verzendkosten zonder btw vooraf afgerond, en het geheel aan het eind afgerond.
        $dp     = wc_get_price_decimals();
        $totaal = 0.0;
        foreach ( $regels as $regel ) {
            $totaal += $regel['netto'] + array_sum( $regel['tax'] );
        }
        foreach ( $kosten as $k ) {
            $totaal += (float) $k['fee']->get_total() + array_sum( array_map( 'floatval', (array) ( $k['fee']->get_taxes()['total'] ?? [] ) ) );
        }
        if ( $toeslag ) {
            $totaal += $toeslag['netto'] + array_sum( $toeslag['tax'] );
        }
        if ( $verzend ) {
            $totaal += round( (float) $verzend['netto'], $dp ) + array_sum( array_map( 'floatval', (array) $verzend['tax'] ) );
        }

        return [ 'regels' => $regels, 'kosten' => $kosten, 'verzend' => $verzend, 'toeslag' => $toeslag, 'levering' => $levering, 'totaal' => round( $totaal, $dp ), 'btw_vrij' => GTXXL_Wijzigen_Plan::btw_vrij( $klant ), 'let_op' => $let_op ];
    }

    /* ------------------------------------------------------------------ */
    /* Maken                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Maakt de nieuwe bestelling.
     *
     * @param WC_Order      $klant     Zie plan().
     * @param WC_Order|null $origineel De bestelling die gekopieerd wordt, of null bij een nieuwe bestelling.
     * @param array         $in        Zie plan(), plus: niet_verzenden, mail (bool), voorraad ('nu' = meteen afboeken,
     *                                 'niet' = niet aanpassen, anders bij betaling) en bericht (string).
     * @return WC_Order|WP_Error
     */
    public static function maak( WC_Order $klant, ?WC_Order $origineel, array $in ) {
        if ( ! self::mag() ) {
            return new WP_Error( 'geen_rechten', __( 'Je mag geen bestellingen maken.', 'gtxxl-maken' ) );
        }
        if ( ! empty( $in['niet_verzenden'] ) ) {
            $in['levering'] = 'geen';
        }
        $plan = self::plan( $klant, $origineel, $in );
        if ( is_wp_error( $plan ) ) {
            return $plan;
        }
        if ( ! $plan['regels'] && ! $plan['kosten'] ) {
            return new WP_Error( 'leeg', __( 'Kies minstens één product.', 'gtxxl-maken' ) );
        }
        if ( $plan['verzend'] && empty( $plan['verzend']['kan'] ) ) {
            return new WP_Error( 'verzend', __( 'Voor dit land of gewicht staat er geen verzendtarief in de tabel. Kies "Geen verzendkosten" en zet ze er daarna zelf bij.', 'gtxxl-maken' ) );
        }
        $voorraad = in_array( (string) ( $in['voorraad'] ?? '' ), [ 'nu', 'niet' ], true ) ? (string) $in['voorraad'] : '';

        try {
            $nieuw = wc_create_order( [ 'customer_id' => (int) $klant->get_customer_id(), 'status' => 'pending', 'created_via' => $origineel ? self::VIA_KOPIE : self::VIA_NIEUW ] );
            if ( is_wp_error( $nieuw ) ) {
                return $nieuw;
            }
            $nieuw->set_address( $klant->get_address( 'billing' ), 'billing' );
            $nieuw->set_address( $klant->get_address( 'shipping' ), 'shipping' );
            $nieuw->set_currency( $klant->get_currency() );
            $nieuw->set_prices_include_tax( $klant->get_prices_include_tax() );
            // De betaalwijze gaat niet mee: die kiest de klant pas op de betaalpagina.
            // Taal en btw-gegevens van de klant, zodat mails, betaalpagina en btw kloppen.
            foreach ( [ 'wpml_language', 'is_vat_exempt', '_billing_vat', '_gtxxl_btw_exempt', '_gtxxl_btw_reason', '_gtxxl_btw_status', '_gtxxl_btw_name', '_gtxxl_btw_address', '_gtxxl_btw_request_id', '_gtxxl_btw_checked_at', '_gtxxl_btw_source', '_gtxxl_btw_bzst' ] as $sleutel ) {
                $waarde = $klant->get_meta( $sleutel );
                if ( '' !== $waarde && null !== $waarde ) {
                    $nieuw->update_meta_data( $sleutel, $waarde );
                }
            }
            if ( $origineel ) {
                $nieuw->update_meta_data( self::META_VAN, $origineel->get_id() );
            }
            if ( ! empty( $in['niet_verzenden'] ) ) {
                $nieuw->update_meta_data( self::META_NIET_VERZENDEN, '1' );
            }
            if ( 'niet' === $voorraad ) {
                $nieuw->update_meta_data( self::META_GEEN_VOORRAAD, '1' );
            }

            foreach ( $plan['regels'] as $r ) {
                $regel = new WC_Order_Item_Product();
                if ( $r['product'] ) {
                    $regel->set_product( $r['product'] );
                }
                $regel->set_name( $r['naam'] );
                $regel->set_quantity( $r['aantal'] );
                $regel->set_tax_class( $r['tax_class'] );
                $regel->set_subtotal( $r['netto'] );
                $regel->set_total( $r['netto'] );
                $regel->set_taxes( [ 'subtotal' => $r['tax'], 'total' => $r['tax'] ] );
                if ( $r['item'] ) {
                    foreach ( $r['item']->get_meta_data() as $meta ) {
                        if ( ! in_array( $meta->key, [ '_reduced_stock', '_restock_refunded_items' ], true ) ) {
                            $regel->add_meta_data( $meta->key, $meta->value );
                        }
                    }
                }
                $nieuw->add_item( $regel );
            }
            foreach ( $plan['kosten'] as $k ) {
                $regel = new WC_Order_Item_Fee();
                $regel->set_name( $k['fee']->get_name() );
                $regel->set_tax_class( $k['fee']->get_tax_class() );
                $regel->set_tax_status( $k['fee']->get_tax_status() );
                $regel->set_amount( $k['fee']->get_total() );
                $regel->set_total( $k['fee']->get_total() );
                $regel->set_taxes( $k['fee']->get_taxes() );
                $nieuw->add_item( $regel );
            }
            if ( $plan['toeslag'] ) {
                $regel = new WC_Order_Item_Fee();
                $regel->set_name( $plan['toeslag']['naam'] );
                $regel->set_tax_status( 'taxable' );
                $regel->set_tax_class( '' );
                $regel->set_amount( $plan['toeslag']['netto'] );
                $regel->set_total( $plan['toeslag']['netto'] );
                $regel->set_taxes( [ 'total' => $plan['toeslag']['tax'] ] );
                $nieuw->add_item( $regel );
            }
            if ( $plan['verzend'] ) {
                $regel = new WC_Order_Item_Shipping();
                $regel->set_method_title( $plan['verzend']['naam'] );
                $regel->set_method_id( ! empty( $plan['verzend']['afhalen'] ) ? 'local_pickup' : 'gtxxl_verzending' );
                $regel->set_total( $plan['verzend']['netto'] );
                $regel->set_taxes( [ 'total' => $plan['verzend']['tax'] ] );
                $nieuw->add_item( $regel );
            }
            // De btw staat al op de regels: alleen optellen, niet opnieuw berekenen.
            $nieuw->update_taxes();
            $nieuw->calculate_totals( false );
            $nieuw->save();

            $delen = [];
            if ( 'nu' === $voorraad ) {
                wc_maybe_reduce_stock_levels( $nieuw->get_id() );
                $delen[] = 'voorraad meteen afgeboekt';
            } elseif ( 'niet' === $voorraad ) {
                $delen[] = 'voorraad wordt niet aangepast';
            }
            if ( ! empty( $in['niet_verzenden'] ) ) {
                $delen[] = 'hoeft niet verzonden te worden (na betaling direct afgerond)';
            }
            foreach ( $plan['regels'] as $r ) {
                if ( abs( $r['stuk'] - $r['standaard'] ) >= 0.005 ) {
                    $delen[] = sprintf( 'afwijkende prijs voor %s: %s in plaats van %s', $r['naam'], self::bedrag( $r['stuk'], $nieuw ), self::bedrag( $r['standaard'], $nieuw ) );
                }
            }
            $staart = $delen ? ': ' . implode( ', ', $delen ) : '';
            $nieuw  = wc_get_order( $nieuw->get_id() );
            if ( $origineel ) {
                $nieuw->add_order_note( sprintf( 'Gedupliceerd van bestelling #%s%s.', $origineel->get_order_number(), $staart ), 0, false );
                $origineel->add_order_note( sprintf( 'Gedupliceerd naar bestelling #%s (%s)%s.', $nieuw->get_order_number(), self::bedrag( (float) $nieuw->get_total(), $nieuw ), $staart ), 0, false );
            } else {
                $nieuw->add_order_note( sprintf( 'Snelle bestelling gemaakt in de backoffice%s.', $staart ), 0, true );
            }

            if ( ! empty( $in['mail'] ) && $nieuw->get_total() > 0 && $nieuw->get_billing_email() ) {
                $mails = WC()->mailer()->get_emails();
                if ( isset( $mails['GTXXL_Maken_Betaalverzoek_Email'] ) ) {
                    $mails['GTXXL_Maken_Betaalverzoek_Email']->trigger( $nieuw, (string) ( $in['bericht'] ?? '' ) );
                    $nieuw->add_order_note( 'Betaalverzoek met betaallink naar de klant gemaild.', 0, false );
                }
            }

            return $nieuw;
        } catch ( Throwable $e ) {
            return new WP_Error( 'mislukt', $e->getMessage() );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Niet verzenden en voorraad                                           */
    /* ------------------------------------------------------------------ */

    public static function packgo_niet( $wacht, $order ) {
        return $wacht || self::niet_verzenden( $order );
    }

    /**
     * De bestelling is betaald. De status "In behandeling" is alleen even nodig voor de factuur en de bevestiging aan
     * de klant; daarna gaat ze meteen naar "Afgerond".
     */
    public static function direct_afronden( $order_id, $van = '', $naar = '' ): void {
        if ( 'processing' !== $naar ) {
            return;
        }
        // Dit loopt als laatste van de statuswissel: de bevestiging met de factuur is dan al de deur uit.
        $order = wc_get_order( $order_id );
        if ( self::niet_verzenden( $order ) && 'processing' === $order->get_status() ) {
            $order->update_status( 'completed', 'Hoeft niet verzonden te worden: na betaling direct afgerond.' );
        }
    }

    public static function geen_afgerond_mail( $aan, $order = null ) {
        return self::niet_verzenden( $order ) ? false : $aan;
    }

    public static function voorraad_mag( $mag, $order = null ) {
        return self::geen_voorraad( $order ) ? false : $mag;
    }

    public static function voorraad_regel_vast( $vast, $item = null ) {
        return $vast || ( $item instanceof WC_Order_Item && self::geen_voorraad( $item->get_order() ) );
    }

    /** Teksten van de bevestiging na betaling van een bestelling zonder zending, in de taal van de bestelling. */
    private static function mail_teksten( WC_Order $order ): array {
        $nummer = $order->get_order_number();

        return 'nl' === $order->get_meta( 'wpml_language' )
            ? [
                'onderwerp' => sprintf( 'Betaling ontvangen – order #%s', $nummer ),
                'kop'       => 'We hebben je betaling ontvangen',
                'intro'     => 'Bedankt, je betaling is binnen. Voor deze bestelling volgt geen zending. De factuur vind je als bijlage bij deze mail.',
            ]
            : [
                'onderwerp' => sprintf( 'Zahlung erhalten – Bestellung #%s', $nummer ),
                'kop'       => 'Wir haben deine Zahlung erhalten',
                'intro'     => 'Vielen Dank, deine Zahlung ist eingegangen. Für diese Bestellung erfolgt kein Versand. Die Rechnung findest du im Anhang dieser E-Mail.',
            ];
    }

    public static function mail_onderwerp( $onderwerp, $order = null ) {
        return self::niet_verzenden( $order ) ? self::mail_teksten( $order )['onderwerp'] : $onderwerp;
    }

    public static function mail_kop( $kop, $order = null ) {
        return self::niet_verzenden( $order ) ? self::mail_teksten( $order )['kop'] : $kop;
    }

    /** Tijdens het opbouwen van de bevestiging de vaste inleiding vervangen (na de regel van het thema, die op 99 staat). */
    public static function mail_begin( $kop, $email = null ): void {
        if ( $email && 'customer_processing_order' === $email->id && self::niet_verzenden( $email->object ) ) {
            $GLOBALS['gtxxl_maken_mail_order'] = $email->object;
            add_filter( 'gettext_woocommerce', [ __CLASS__, 'mail_intro' ], 100, 2 );
        }
    }

    public static function mail_intro( $vertaling, $tekst ) {
        $order = $GLOBALS['gtxxl_maken_mail_order'] ?? null;

        return $order instanceof WC_Order && 0 === strpos( (string) $tekst, 'Just to let you know' ) ? self::mail_teksten( $order )['intro'] : $vertaling;
    }

    public static function mail_einde(): void {
        unset( $GLOBALS['gtxxl_maken_mail_order'] );
        remove_filter( 'gettext_woocommerce', [ __CLASS__, 'mail_intro' ], 100 );
    }
}
