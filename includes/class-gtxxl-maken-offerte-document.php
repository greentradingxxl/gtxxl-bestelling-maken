<?php
/**
 * Het document "Offerte" / "Angebot" in "PDF Invoices & Packing Slips": dezelfde opmaak als de factuur, een eigen
 * nummering (OF2026-0001) en alleen bij bestellingen die als offerte zijn gemaakt.
 *
 * Titels in de taal van de bestelling, zonder vertalingen in WPML. De basisklasse is die van Pro (zie
 * GTXXL_Maken_Offerte::pdf_document).
 */

defined( 'ABSPATH' ) || exit;

class GTXXL_Maken_Offerte_Document extends GTXXL_Maken_Offerte_Basis {

    public function __construct( $order = 0 ) {
        $this->type  = 'offerte';
        $this->title = 'Offerte';
        $this->icon  = '';

        parent::__construct( $order );
    }

    private function nl(): bool {
        return ! empty( $this->order ) && is_callable( [ $this->order, 'get_meta' ] ) && 'nl' === $this->order->get_meta( 'wpml_language' );
    }

    public function get_title(): string {
        return apply_filters( 'wpo_wcpdf_document_title', $this->nl() ? 'Offerte' : 'Angebot', $this );
    }

    public function get_number_title(): string {
        return apply_filters( 'wpo_wcpdf_document_number_title', $this->nl() ? 'Offertenummer:' : 'Angebotsnr.:', $this );
    }

    public function get_date_title(): string {
        return apply_filters( 'wpo_wcpdf_document_date_title', $this->nl() ? 'Offertedatum:' : 'Angebotsdatum:', $this );
    }

    public function get_shipping_address_title(): string {
        return apply_filters( 'wpo_wcpdf_document_shipping_address_title', $this->nl() ? 'Afleveradres:' : 'Lieferadresse:', $this );
    }

    public function get_valid_title(): string {
        return $this->nl() ? 'Geldig tot en met:' : 'Gültig bis:';
    }

    public function get_valid_date(): string {
        return empty( $this->order ) ? '' : GTXXL_Maken_Offerte::datum( GTXXL_Maken_Offerte::tot( $this->order ), $this->nl() );
    }

    /** De zin onder de regels: vrijblijvend en tot wanneer. */
    public function get_valid_text(): string {
        $datum = $this->get_valid_date();
        if ( '' === $datum ) {
            return '';
        }

        return $this->nl()
            ? sprintf( 'Deze offerte is vrijblijvend en geldig tot en met %s. Je accepteert haar door te betalen via de link in onze mail.', $datum )
            : sprintf( 'Dieses Angebot ist freibleibend und gültig bis einschließlich %s. Du nimmst es an, indem du über den Link in unserer E-Mail bezahlst.', $datum );
    }

    /** Alleen bij bestellingen die als offerte zijn gemaakt. */
    public function is_allowed() {
        return GTXXL_Maken_Offerte::is_offerte( $this->order ?? null ) && parent::is_allowed();
    }

    public function get_filename( $context = 'download', $args = [] ): string {
        $order_ids = $args['order_ids'] ?? [ $this->order_id ];
        $nummer    = $this->get_formatted_number( 'offerte' );
        if ( 1 !== count( $order_ids ) ) {
            $staart = gmdate( 'Y-m-d' );
        } elseif ( '' !== $nummer ) {
            $staart = $nummer;
        } else {
            $order  = empty( $this->order ) ? wc_get_order( $order_ids[0] ) : $this->order;
            $staart = $order ? $order->get_order_number() : '';
        }
        $uitvoer  = ! empty( $args['output'] ) ? esc_attr( $args['output'] ) : 'pdf';
        $naam     = ( $this->nl() ? 'Offerte' : 'Angebot' ) . '-' . $staart . wcpdf_get_document_output_format_extension( $uitvoer );
        $naam     = apply_filters( 'wpo_wcpdf_filename', $naam, $this->get_type(), $order_ids, $context, $args );

        return sanitize_file_name( $naam );
    }

    /** Het eerste offertenummer: zo oogt de eerste offerte niet als de allereerste (A23517, net als V… en R…). */
    const EERSTE = 23517;

    /** De teller van de offertenummers begint nooit lager dan EERSTE, ook niet bij een nieuwe teller (live). */
    public function get_sequential_number_store() {
        $teller = parent::get_sequential_number_store();
        if ( is_object( $teller ) && is_callable( [ $teller, 'get_next' ] ) && $teller->get_next() < self::EERSTE ) {
            $teller->set_next( self::EERSTE );
        }

        return $teller;
    }

    /** Geen eigen instellingenscherm: de instellingen staan vast (zie GTXXL_Maken_Offerte::pdf_instellingen). */
    public function init_settings(): void {
    }

    public function get_settings_categories( string $output_format ): array {
        return [];
    }
}
