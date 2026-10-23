<?php
/**
 * Offertes: een bestelling met de status "Offerte" die de klant via de betaallink accepteert door te betalen.
 *
 * Een offerte wordt gemaakt in hetzelfde venster als de snelle bestelling. Ze blijft op "Offerte" staan tot de klant
 * betaalt; daarna gaat ze verder als een gewone bestelling ("In behandeling" of "In de wacht"), met de gewone mails,
 * de factuur en de voorraad. Na de datum "geldig tot en met" kan er niet meer betaald worden en gaat ze 's nachts naar
 * "Offerte verlopen".
 *
 * De pdf is een eigen document in "PDF Invoices & Packing Slips" (zie GTXXL_Maken_Offerte_Document), met de opmaak
 * van de factuur.
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Offerte {

    const STATUS   = 'offerte';
    const VERLOPEN = 'offerte-verlopen';
    /** Op de offerte: de laatste dag waarop ze geldig is (Y-m-d). */
    const META_TOT = '_gtxxl_offerte_tot';
    const CRON     = 'gtxxl_maken_offertes_verlopen';
    /** Zo lang is een nieuwe offerte standaard geldig. */
    const DAGEN    = 14;

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'statussen' ] );
        add_filter( 'wc_order_statuses', [ __CLASS__, 'in_lijst' ] );
        add_filter( 'woocommerce_analytics_excluded_order_statuses', [ __CLASS__, 'niet_in_cijfers' ] );
        add_filter( 'wc_order_is_editable', [ __CLASS__, 'te_bewerken' ], 10, 2 );

        // Betalen via de betaallink, zolang de offerte geldig is.
        add_filter( 'woocommerce_valid_order_statuses_for_payment', [ __CLASS__, 'mag_betalen' ], 10, 2 );
        add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', [ __CLASS__, 'mag_afronden' ] );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'na_betaling' ], 5, 4 );
        add_action( 'template_redirect', [ __CLASS__, 'betaalpagina' ] );
        add_action( 'before_woocommerce_pay_form', [ __CLASS__, 'op_betaalpagina' ] );

        // Bestelnummer: een offerte krijgt pas een V-nummer als ze een bestelling wordt; tot dan het offertenummer.
        add_filter( 'gtxxl_ordernummer_uitstellen', [ __CLASS__, 'nog_geen_ordernummer' ], 10, 2 );
        add_filter( 'woocommerce_order_number', [ __CLASS__, 'offertenummer_als_bestelnummer' ], 20, 2 );
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'ordernummer_na_acceptatie' ], 1, 4 );

        // Verlopen.
        add_action( self::CRON, [ __CLASS__, 'verloop_alles' ] );
        add_action( 'init', [ __CLASS__, 'plan_cron' ] );

        // De pagina WooCommerce > Offertes: de bestellijst, beperkt tot offertes, met eigen tabbladen.
        add_action( 'admin_menu', [ __CLASS__, 'menu' ], 60 );
        add_filter( 'submenu_file', [ __CLASS__, 'menu_actief' ] );
        add_filter( 'admin_title', [ __CLASS__, 'paginatitel' ], 20 );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ __CLASS__, 'lijst_query' ] );
        add_filter( 'woocommerce_before_shop_order_list_table_view_links', [ __CLASS__, 'lijst_tabs' ] );
        add_filter( 'woocommerce_shop_order_list_table_should_render_blank_state', [ __CLASS__, 'lijst_leeg' ] );

        // In de backoffice.
        add_filter( 'woocommerce_order_actions', [ __CLASS__, 'acties' ], 10, 2 );
        add_action( 'woocommerce_order_action_gtxxl_offerte_mailen', [ __CLASS__, 'actie_mailen' ] );
        add_action( 'woocommerce_admin_order_data_after_order_details', [ __CLASS__, 'melding' ], 18 );
        add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'opslaan' ], 60 );

        // De pdf.
        add_filter( 'wpo_wcpdf_document_classes', [ __CLASS__, 'pdf_document' ], 99 );
        add_filter( 'default_option_wpo_wcpdf_documents_settings_offerte', [ __CLASS__, 'pdf_instellingen' ] );
        add_filter( 'option_wpo_wcpdf_editor_settings', [ __CLASS__, 'pdf_kolommen' ] );
        add_filter( 'wpo_wcpdf_template_file', [ __CLASS__, 'pdf_template' ], 10, 2 );
        add_filter( 'wpo_wcpdf_document_is_allowed', [ __CLASS__, 'pdf_geen_factuur' ], 20, 2 );
    }

    public static function is_offerte( $order ): bool {
        return $order instanceof WC_Order && '' !== (string) $order->get_meta( self::META_TOT );
    }

    /** De laatste dag waarop de offerte geldig is (Y-m-d), of ''. */
    public static function tot( WC_Order $order ): string {
        return (string) $order->get_meta( self::META_TOT );
    }

    public static function verlopen( WC_Order $order ): bool {
        $tot = self::tot( $order );

        return '' !== $tot && current_time( 'Y-m-d' ) > $tot;
    }

    public static function standaard_tot(): string {
        return wp_date( 'Y-m-d', time() + self::DAGEN * DAY_IN_SECONDS );
    }

    /** Een datum (Y-m-d) zoals de klant hem leest: 06-11-2026 of 06.11.2026. */
    public static function datum( string $ymd, bool $nl ): string {
        $tijd = strtotime( $ymd . ' 12:00:00' );

        return $tijd ? gmdate( $nl ? 'd-m-Y' : 'd.m.Y', $tijd ) : $ymd;
    }

    /** Het offertenummer van de pdf, of '' als de pdf er nog niet is. */
    public static function nummer( WC_Order $order, bool $maken = false ): string {
        if ( ! function_exists( 'wcpdf_get_document' ) ) {
            return '';
        }
        $document = wcpdf_get_document( 'offerte', $order, $maken );
        if ( ! $document || ! $document->exists() ) {
            return '';
        }
        $nummer = $document->get_number( 'offerte' );

        return $nummer && is_callable( [ $nummer, 'get_formatted' ] ) ? (string) $nummer->get_formatted() : '';
    }

    /* ------------------------------------------------------------------ */
    /* Statussen                                                            */
    /* ------------------------------------------------------------------ */

    public static function statussen(): void {
        register_post_status( 'wc-' . self::STATUS, [
            'label'                     => _x( 'Offerte', 'Order status', 'gtxxl-maken' ),
            'public'                    => false,
            'exclude_from_search'       => false,
            // Niet onder Bestellingen: offertes hebben een eigen pagina.
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
            /* translators: %s: aantal */
            'label_count'               => _n_noop( 'Offerte <span class="count">(%s)</span>', 'Offertes <span class="count">(%s)</span>', 'gtxxl-maken' ),
        ] );
        register_post_status( 'wc-' . self::VERLOPEN, [
            'label'                     => _x( 'Offerte verlopen', 'Order status', 'gtxxl-maken' ),
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => false,
            'show_in_admin_status_list' => false,
            /* translators: %s: aantal */
            'label_count'               => _n_noop( 'Offerte verlopen <span class="count">(%s)</span>', 'Offertes verlopen <span class="count">(%s)</span>', 'gtxxl-maken' ),
        ] );
    }

    /** Achter "Wachtend op betaling" in de keuzelijst. */
    public static function in_lijst( $statussen ) {
        $uit = [];
        foreach ( (array) $statussen as $sleutel => $naam ) {
            $uit[ $sleutel ] = $naam;
            if ( 'wc-pending' === $sleutel ) {
                $uit[ 'wc-' . self::STATUS ]   = _x( 'Offerte', 'Order status', 'gtxxl-maken' );
                $uit[ 'wc-' . self::VERLOPEN ] = _x( 'Offerte verlopen', 'Order status', 'gtxxl-maken' );
            }
        }

        return $uit + [
            'wc-' . self::STATUS   => _x( 'Offerte', 'Order status', 'gtxxl-maken' ),
            'wc-' . self::VERLOPEN => _x( 'Offerte verlopen', 'Order status', 'gtxxl-maken' ),
        ];
    }

    /** Een offerte is nog geen omzet. */
    public static function niet_in_cijfers( $statussen ) {
        return array_values( array_unique( array_merge( (array) $statussen, [ self::STATUS, self::VERLOPEN ] ) ) );
    }

    /** Een offerte kun je in het bestelscherm nog aanpassen, net als een bestelling die op betaling wacht. */
    public static function te_bewerken( $kan, $order ) {
        return $kan || ( $order instanceof WC_Order && $order->has_status( self::STATUS ) );
    }

    /* ------------------------------------------------------------------ */
    /* Betalen                                                              */
    /* ------------------------------------------------------------------ */

    public static function mag_betalen( $statussen, $order = null ) {
        if ( $order instanceof WC_Order && $order->has_status( self::STATUS ) && ! self::verlopen( $order ) ) {
            $statussen[] = self::STATUS;
        }

        return $statussen;
    }

    public static function mag_afronden( $statussen ) {
        $statussen[] = self::STATUS;

        return $statussen;
    }

    /**
     * De klant heeft betaald (of betaalt per overschrijving). WooCommerce en andere plugins reageren op "van Wachtend
     * op betaling naar ...": de orderbevestiging met de factuur, de melding aan de winkel. Voor een offerte gebeurt dat
     * dus niet vanzelf; daarom wordt die overgang hier alsnog gemeld.
     */
    public static function na_betaling( $order_id, $van, $naar, $order = null ): void {
        if ( self::STATUS !== $van || ! in_array( $naar, [ 'processing', 'on-hold', 'completed', 'failed' ], true ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( 'failed' !== $naar ) {
            $order->add_order_note( 'Offerte geaccepteerd door de klant.', 0, false );
        }
        do_action( "woocommerce_order_status_pending_to_{$naar}", $order_id, $order );
    }

    /**
     * De klant opent de betaallink van een offerte die over datum is: dan meteen op "Offerte verlopen", en een melding
     * dat de offerte verlopen is. Zonder dat stuurt "betaallink al betaald" (green-trading-xxl-custom-scripts) elke
     * link die niet meer te betalen is door naar "Bedankt voor je bestelling".
     */
    public static function betaalpagina(): void {
        if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
            return;
        }
        $order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
        if ( ! self::is_offerte( $order ) ) {
            return;
        }
        if ( $order->has_status( self::STATUS ) && self::verlopen( $order ) ) {
            self::verloop( $order );
        }
        if ( ! $order->has_status( self::VERLOPEN ) ) {
            return;
        }
        remove_action( 'template_redirect', 'greentradingxxl_redirect_already_paid_order_pay_link', 20 );
        $nl    = 'nl' === $order->get_meta( 'wpml_language' );
        $datum = self::datum( self::tot( $order ), $nl );
        $tekst = $nl
            ? sprintf( 'Deze offerte was geldig tot en met %s en is verlopen. Wil je haar toch? Beantwoord dan onze mail of neem contact met ons op, dan sturen we je graag een nieuwe offerte.', $datum )
            : sprintf( 'Dieses Angebot war gültig bis einschließlich %s und ist abgelaufen. Möchtest du es trotzdem? Dann antworte auf unsere E-Mail oder kontaktiere uns, wir schicken dir gern ein neues Angebot.', $datum );
        // De foutmelding van WooCommerce ("deze bestelling kan niet betaald worden") vervangen door die van de offerte.
        add_filter(
            'gettext_woocommerce',
            static function ( $vertaling, $origineel ) use ( $tekst ) {
                return 0 === strpos( (string) $origineel, 'This order&rsquo;s status is &ldquo;%s&rdquo;' ) ? $tekst : $vertaling;
            },
            10,
            2
        );
    }

    /** Boven het betaalformulier: dat het om een offerte gaat en tot wanneer ze geldig is. */
    public static function op_betaalpagina( $order = null ): void {
        if ( ! $order instanceof WC_Order || ! $order->has_status( self::STATUS ) ) {
            return;
        }
        $nl     = 'nl' === $order->get_meta( 'wpml_language' );
        $nummer = self::nummer( $order );
        $datum  = self::datum( self::tot( $order ), $nl );
        $tekst  = $nl
            ? sprintf( 'Offerte %s, geldig tot en met %s. Met je betaling accepteer je de offerte.', $nummer, $datum )
            : sprintf( 'Angebot %s, gültig bis einschließlich %s. Mit deiner Zahlung nimmst du das Angebot an.', $nummer, $datum );
        echo '<p class="gtxxl-offerte-betaal">' . esc_html( preg_replace( '/\s+,/', ',', $tekst ) ) . '</p>';
    }

    /* ------------------------------------------------------------------ */
    /* Bestelnummer                                                         */
    /* ------------------------------------------------------------------ */

    /** Zolang een offerte deze status heeft, krijgt ze geen V-nummer (ook niet na een mislukte betaalpoging). */
    const ZONDER_ORDERNUMMER = [ self::STATUS, self::VERLOPEN, 'failed', 'cancelled' ];

    /**
     * Gemaakt als offerte? Ook op het moment van aanmaken, als de datum "geldig tot" er nog niet op staat: dan geeft
     * "created_via" het al aan.
     */
    private static function als_offerte_gemaakt( $order ): bool {
        return $order instanceof WC_Order && ( self::is_offerte( $order ) || GTXXL_Maken::VIA_OFFERTE === $order->get_created_via() );
    }

    /** Voor het ordernummer-script (green-trading-xxl-custom-scripts): nog geen V-nummer voor een open offerte. */
    public static function nog_geen_ordernummer( $uitstellen, $order = null ) {
        return $uitstellen || ( self::als_offerte_gemaakt( $order ) && $order->has_status( self::ZONDER_ORDERNUMMER ) );
    }

    /** Zonder V-nummer heet de offerte overal naar haar offertenummer (lijst, betaalpagina, mails): #A23517. */
    public static function offertenummer_als_bestelnummer( $nummer, $order = null ) {
        if ( ! self::als_offerte_gemaakt( $order ) || ( class_exists( 'GTXXL_Ordernummer' ) && $order->get_meta( GTXXL_Ordernummer::META_FULL ) ) ) {
            return $nummer;
        }
        $offerte = (string) $order->get_meta( '_wcpdf_offerte_number' );

        return '' !== $offerte ? $offerte : $nummer;
    }

    /**
     * De offerte wordt een bestelling (betaald, of door de medewerker omgezet): nu het V-nummer, vóór de mails en de
     * factuur. Meestal heeft het ordernummer-script het bij het opslaan van de nieuwe status al gegeven.
     */
    public static function ordernummer_na_acceptatie( $order_id, $van, $naar, $order = null ): void {
        if ( ! in_array( $van, self::ZONDER_ORDERNUMMER, true ) || in_array( $naar, self::ZONDER_ORDERNUMMER, true ) || ! class_exists( 'GTXXL_Ordernummer' ) ) {
            return;
        }
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( self::als_offerte_gemaakt( $order ) && ! $order->get_meta( GTXXL_Ordernummer::META_FULL ) ) {
            GTXXL_Ordernummer::assign_order_number( $order );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Verlopen                                                             */
    /* ------------------------------------------------------------------ */

    public static function plan_cron(): void {
        if ( ! wp_next_scheduled( self::CRON ) ) {
            // Elke nacht kort na middernacht (tijd van de winkel).
            $morgen = new DateTime( 'tomorrow 00:15', wp_timezone() );
            wp_schedule_event( $morgen->getTimestamp(), 'daily', self::CRON );
        }
    }

    public static function verloop( WC_Order $order ): void {
        $order->update_status( self::VERLOPEN, sprintf( 'Offerte verlopen: ze was geldig tot en met %s.', self::datum( self::tot( $order ), true ) ) );
    }

    public static function verloop_alles(): void {
        $ids = wc_get_orders( [
            'status'     => [ self::STATUS ],
            'limit'      => -1,
            'return'     => 'ids',
            'meta_query' => [ [ 'key' => self::META_TOT, 'value' => current_time( 'Y-m-d' ), 'compare' => '<' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] );
        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );
            if ( $order instanceof WC_Order && self::verlopen( $order ) ) {
                self::verloop( $order );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* De pagina Offertes                                                   */
    /* ------------------------------------------------------------------ */

    /** Het adres van de pagina: de bestellijst met de tab "Open". */
    const PAGINA = 'admin.php?page=wc-orders&status=wc-offerte';

    /**
     * De tabbladen: [ status in het adres => naam ]. "Geaccepteerd" en "Alle" zijn geen echte statussen; welke
     * bestellingen erbij horen, staat in tab_query().
     */
    public static function tabs(): array {
        return [
            'wc-' . self::STATUS   => __( 'Open', 'gtxxl-maken' ),
            'wc-' . self::VERLOPEN => __( 'Verlopen', 'gtxxl-maken' ),
            'gtxxl-geaccepteerd'   => __( 'Geaccepteerd', 'gtxxl-maken' ),
            'gtxxl-offertes'       => __( 'Alle offertes', 'gtxxl-maken' ),
        ];
    }

    /** De statussen en de voorwaarde van een tab. */
    private static function tab_query( string $tab ): array {
        $offerte = [ [ 'key' => self::META_TOT, 'compare' => 'EXISTS' ] ];
        switch ( $tab ) {
            case 'gtxxl-geaccepteerd':
                return [ 'status' => [ 'wc-processing', 'wc-on-hold', 'wc-completed' ], 'meta_query' => $offerte ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            case 'gtxxl-offertes':
                return [ 'status' => array_keys( wc_get_order_statuses() ), 'meta_query' => $offerte ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            default:
                return [ 'status' => [ $tab ] ];
        }
    }

    /** Staat de pagina Offertes open? Dan de tab, anders ''. */
    public static function lijst_tab(): string {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( ! is_admin() || 'wc-orders' !== ( $_GET['page'] ?? '' ) || ! empty( $_GET['action'] ) ) {
            return '';
        }
        $tab = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
        // phpcs:enable

        return isset( self::tabs()[ $tab ] ) ? $tab : '';
    }

    /** "Offertes" in het menu van WooCommerce, direct onder "Bestellingen". */
    public static function menu(): void {
        global $submenu;
        add_submenu_page( 'woocommerce', __( 'Offertes', 'gtxxl-maken' ), __( 'Offertes', 'gtxxl-maken' ), 'edit_shop_orders', self::PAGINA );
        if ( empty( $submenu['woocommerce'] ) ) {
            return;
        }
        $items = array_values( $submenu['woocommerce'] );
        $hier  = null;
        $na    = null;
        foreach ( $items as $i => $item ) {
            if ( self::PAGINA === $item[2] ) {
                $hier = $i;
            } elseif ( in_array( $item[2], [ 'wc-orders', 'edit.php?post_type=shop_order' ], true ) ) {
                $na = $i;
            }
        }
        if ( null !== $hier && null !== $na ) {
            $item = $items[ $hier ];
            unset( $items[ $hier ] );
            $items = array_values( $items );
            array_splice( $items, $na < $hier ? $na + 1 : $na, 0, [ $item ] );
            $submenu['woocommerce'] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        }
    }

    /** Op de pagina Offertes en in een open of verlopen offerte staat "Offertes" aan in het menu. */
    public static function menu_actief( $submenu_file ) {
        if ( self::lijst_tab() ) {
            return self::PAGINA;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( is_admin() && 'wc-orders' === ( $_GET['page'] ?? '' ) && 'edit' === ( $_GET['action'] ?? '' ) ) {
            $order = wc_get_order( absint( $_GET['id'] ?? 0 ) );
            if ( self::is_offerte( $order ) && $order->has_status( [ self::STATUS, self::VERLOPEN ] ) ) {
                return self::PAGINA;
            }
        }
        // phpcs:enable

        return $submenu_file;
    }

    public static function paginatitel( $titel ) {
        /* translators: %s: naam van de website */
        return self::lijst_tab() ? sprintf( __( 'Offertes &lsaquo; %s &#8212; WordPress', 'gtxxl-maken' ), get_bloginfo( 'name' ) ) : $titel;
    }

    public static function lijst_query( $args ) {
        $tab = self::lijst_tab();

        return $tab ? array_merge( (array) $args, self::tab_query( $tab ) ) : $args;
    }

    /** De tabbladen boven de lijst, met het aantal per tab. */
    public static function lijst_tabs( $links ) {
        $huidig = self::lijst_tab();
        if ( ! $huidig ) {
            return $links;
        }
        $links = [];
        foreach ( self::tabs() as $tab => $naam ) {
            $aantal = wc_get_orders( self::tab_query( $tab ) + [ 'limit' => 1, 'paginate' => true, 'return' => 'ids' ] )->total;
            $links[ $tab ] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%s)</span></a>',
                esc_url( admin_url( 'admin.php?page=wc-orders&status=' . $tab ) ),
                $tab === $huidig ? ' class="current" aria-current="page"' : '',
                esc_html( $naam ),
                esc_html( number_format_i18n( $aantal ) )
            );
        }

        return $links;
    }

    /** Ook zonder offertes de lijst tonen, niet het welkomstscherm van WooCommerce. */
    public static function lijst_leeg( $leeg ) {
        return self::lijst_tab() ? false : $leeg;
    }

    /* ------------------------------------------------------------------ */
    /* Backoffice                                                           */
    /* ------------------------------------------------------------------ */

    public static function acties( $acties, $order = null ) {
        if ( self::is_offerte( $order ) && $order->has_status( self::STATUS ) && $order->get_billing_email() ) {
            $acties['gtxxl_offerte_mailen'] = __( 'Offerte met pdf naar de klant mailen', 'gtxxl-maken' );
        }

        return $acties;
    }

    public static function actie_mailen( $order ): void {
        if ( self::mail( $order ) ) {
            $order->add_order_note( 'Offerte met pdf opnieuw naar de klant gemaild.', 0, false );
        }
    }

    /** Mailt de offerte met de pdf; geeft terug of de mail is verstuurd. */
    public static function mail( WC_Order $order, string $bericht = '' ): bool {
        $mails = WC()->mailer()->get_emails();
        if ( ! isset( $mails['GTXXL_Maken_Offerte_Email'] ) || ! $order->get_billing_email() ) {
            return false;
        }

        return $mails['GTXXL_Maken_Offerte_Email']->trigger( $order, $bericht );
    }

    /**
     * Op de offerte: het nummer en tot wanneer ze geldig is (aan te passen). De betaallink om zelf door te sturen staat
     * er al (veld "Betaallink" bij de status).
     */
    public static function melding( $order ): void {
        if ( ! self::is_offerte( $order ) ) {
            return;
        }
        $nummer = self::nummer( $order );
        echo '<div class="gtxxl-wz-blok form-field form-field-wide gtxxl-offerte-blok">';
        printf(
            '<p class="gtxxl-wz-melding">%s</p>',
            esc_html( $nummer
                /* translators: %s: offertenummer */
                ? sprintf( __( 'Offerte %s', 'gtxxl-maken' ), $nummer )
                : __( 'Offerte (de pdf is nog niet gemaakt)', 'gtxxl-maken' ) )
        );
        // Alleen voor de medewerker: inkoop en marge op de producten (zonder btw en verzendkosten).
        $inkoop   = 0.0;
        $omzet    = 0.0;
        $onbekend = 0;
        foreach ( $order->get_items() as $item ) {
            $stuk = GTXXL_Maken::inkoop( $item->get_product() );
            if ( null === $stuk ) {
                $onbekend++;
                continue;
            }
            $inkoop += $stuk * (int) $item->get_quantity();
            $omzet  += (float) $item->get_total();
        }
        if ( $inkoop > 0 ) {
            printf(
                '<p class="gtxxl-offerte-marge">%s</p>',
                esc_html( sprintf(
                    /* translators: 1: inkoop, 2: winst, 3: opslag in procenten */
                    __( 'Inkoop %1$s · winst %2$s · opslag %3$s', 'gtxxl-maken' ),
                    GTXXL_Maken::bedrag( $inkoop, $order ),
                    GTXXL_Maken::bedrag( $omzet - $inkoop, $order ),
                    str_replace( '.', ',', (string) round( ( $omzet / $inkoop - 1 ) * 100, 1 ) ) . ' %'
                ) . ( $onbekend ? ' · ' . sprintf( __( '%d product(en) zonder inkoopprijs telt niet mee', 'gtxxl-maken' ), $onbekend ) : '' ) )
            );
        }
        if ( ! $order->is_paid() && $order->has_status( [ self::STATUS, self::VERLOPEN ] ) ) {
            printf(
                '<label for="gtxxl_offerte_tot">%s</label><input type="date" id="gtxxl_offerte_tot" name="gtxxl_offerte_tot" value="%s">',
                esc_html( __( 'Geldig tot en met', 'gtxxl-maken' ) ),
                esc_attr( self::tot( $order ) )
            );
        }
        echo '</div>';
    }

    /** Een nieuwe datum "geldig tot en met". Een verlopen offerte met een datum in de toekomst is weer geldig. */
    public static function opslaan( $order_id ): void {
        if ( ! isset( $_POST['gtxxl_offerte_tot'] ) || ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce controleert de nonce van het bestelscherm.
            return;
        }
        $order = wc_get_order( $order_id );
        $tot   = sanitize_text_field( wp_unslash( $_POST['gtxxl_offerte_tot'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! self::is_offerte( $order ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $tot ) || $tot === self::tot( $order ) ) {
            return;
        }
        $oud = self::tot( $order );
        $order->update_meta_data( self::META_TOT, $tot );
        $order->save();
        $order->add_order_note( sprintf( 'Offerte geldig tot en met %s (was %s).', self::datum( $tot, true ), self::datum( $oud, true ) ), 0, true );
        if ( $order->has_status( self::VERLOPEN ) && ! self::verlopen( $order ) ) {
            $order->update_status( self::STATUS, 'Offerte weer geldig.' );
        }
    }

    /* ------------------------------------------------------------------ */
    /* De pdf                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Het document erbij zetten. De basisklasse komt uit Pro, dat haar in dezelfde filter laadt (daarom hier na Pro):
     * bij "PDF Invoices" 6.0 en hoger AbstractProDocument, daarvoor Pro_Document.
     */
    public static function pdf_document( $documenten ) {
        if ( ! class_exists( 'GTXXL_Maken_Offerte_Basis', false ) ) {
            foreach ( [ '\WPO\IPS\Documents\AbstractProDocument', '\WPO\WC\PDF_Invoices\Documents\Pro_Document' ] as $basis ) {
                if ( class_exists( $basis, false ) ) {
                    class_alias( ltrim( $basis, '\\' ), 'GTXXL_Maken_Offerte_Basis' );
                    break;
                }
            }
        }
        if ( class_exists( 'GTXXL_Maken_Offerte_Basis', false ) ) {
            require_once GTXXL_MAKEN_PATH . 'includes/class-gtxxl-maken-offerte-document.php';
            $documenten['GTXXL_Maken_Offerte_Document'] = new GTXXL_Maken_Offerte_Document();
        }

        return $documenten;
    }

    /**
     * De instellingen van het document zolang ze niet in "PDF Invoices" zijn opgeslagen: eigen doorlopende nummering
     * A23517, A23518, … (A van Angebot, zoals V en R), met de mail "Offerte" mee.
     */
    public static function pdf_instellingen( $standaard ) {
        return [
            'enabled'                  => '1',
            'attach_to_email_ids'      => [ 'gtxxl_offerte' => '1' ],
            'display_shipping_address' => 'when_different',
            'display_number'           => 'offerte_number',
            'display_date'             => 'document_date',
            'number_format'            => [ 'prefix' => 'A', 'suffix' => '', 'padding' => '' ],
            'my_account_buttons'       => 'available',
            'use_latest_settings'      => '1',
        ];
    }

    /** De kolommen en totalen van de factuur, zolang de offerte in de template-editor geen eigen heeft. */
    public static function pdf_kolommen( $instellingen ) {
        if ( ! is_array( $instellingen ) ) {
            return $instellingen;
        }
        foreach ( [ 'columns', 'totals' ] as $soort ) {
            if ( empty( $instellingen[ "fields_offerte_{$soort}" ] ) && ! empty( $instellingen[ "fields_invoice_{$soort}" ] ) ) {
                $instellingen[ "fields_offerte_{$soort}" ] = $instellingen[ "fields_invoice_{$soort}" ];
                // De korting direct onder de goederenwaarde, zodat de bedragen onder elkaar optellen tot het netto.
                if ( 'totals' === $soort ) {
                    $instellingen['fields_offerte_totals'] = self::korting_onder_subtotaal( (array) $instellingen['fields_offerte_totals'] );
                }
            }
        }
        if ( isset( $instellingen['sort_items'] ) && is_array( $instellingen['sort_items'] ) && ! isset( $instellingen['sort_items']['offerte'] ) && isset( $instellingen['sort_items']['invoice'] ) ) {
            $instellingen['sort_items']['offerte'] = $instellingen['sort_items']['invoice'];
        }

        return $instellingen;
    }

    /**
     * Zolang het een (verlopen) offerte is geen factuur, creditnota of kwitantie: een factuur maken zou een factuurnummer
     * kosten voor iets wat (nog) niet verkocht is. Na betaling komt de factuur zoals altijd.
     */
    public static function pdf_geen_factuur( $mag, $document = null ) {
        if ( ! $mag || ! is_object( $document ) || ! is_callable( [ $document, 'get_type' ] ) || ! in_array( $document->get_type(), [ 'invoice', 'credit-note', 'receipt' ], true ) ) {
            return $mag;
        }
        $order = $document->order ?? null;

        return $order instanceof WC_Order && $order->has_status( [ self::STATUS, self::VERLOPEN ] ) ? false : $mag;
    }

    /** De totalen van de pdf met de kortingsregel direct na het subtotaal; de nummering (1, 2, …) blijft doorlopend. */
    private static function korting_onder_subtotaal( array $totalen ): array {
        $korting = [];
        $rest    = [];
        foreach ( $totalen as $regel ) {
            if ( is_array( $regel ) && 'discount' === ( $regel['type'] ?? '' ) ) {
                $korting[] = $regel;
            } else {
                $rest[] = $regel;
            }
        }
        $uit = [];
        foreach ( $rest as $regel ) {
            $uit[] = $regel;
            if ( is_array( $regel ) && 'subtotal' === ( $regel['type'] ?? '' ) ) {
                array_push( $uit, ...$korting );
                $korting = [];
            }
        }
        $uit = array_merge( $uit, $korting );

        return $uit ? array_combine( range( 1, count( $uit ) ), $uit ) : $totalen;
    }

    /** Het eigen template; opmaak (style.css) en omhulsel komen uit het gekozen template van "PDF Invoices". */
    public static function pdf_template( $pad, $soort = '' ) {
        return 'offerte' === $soort && 'offerte.php' === basename( (string) $pad ) ? GTXXL_MAKEN_PATH . 'templates/pdf/offerte.php' : $pad;
    }
}
