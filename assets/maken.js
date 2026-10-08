/* Het venster "Snelle bestelling" en "Bestelling dupliceren" in de backoffice. */
( function ( $ ) {
    'use strict';

    var G = window.gtxxlMaken;
    if ( ! G ) {
        return;
    }

    var $overlay, $regels, $totaal, $melding, $kaart, $form;
    // 'nieuw' = snelle bestelling voor een klant; 'kopie' = de bestelling die openstaat dupliceren.
    var modus = 'nieuw';
    // De producten in de lijst, de kostenregels van de oude bestelling (aan of uit), de levering en de klant.
    var lijnen = [], overig = {}, levering = 'verzenden', leveringVoor = 'verzenden', klant = null, klantLaatste = '';
    // Wat de server voor deze keuze heeft berekend (null = nog aan het rekenen).
    var uitkomst = null, rekenTimer = null, rekenNr = 0, bezig = false;
    // De productzoeker deelt zijn instelling met het venster "Bestelling wijzigen": bij het sluiten gaat die terug.
    var zoekerVoor = null;

    var veilig = function ( tekst ) { return $( '<div>' ).text( tekst == null ? '' : String( tekst ) ).html(); };
    var t = function ( sleutel ) { return veilig( ( G.t && G.t[ sleutel ] ) || sleutel ); };
    var tp = function ( sleutel, waarde ) { return String( ( G.t && G.t[ sleutel ] ) || sleutel ).replace( '%s', waarde == null ? '' : waarde ); };
    var getal = function ( bedrag ) { return ( Math.round( bedrag * 100 ) / 100 ).toFixed( 2 ).replace( '.', ',' ); };
    var geld = function ( bedrag ) { return G.valuta + ' ' + getal( bedrag ); };
    var fout = function ( antwoord, anders ) { var d = antwoord && antwoord.data; return d ? ( d.tekst || ( typeof d === 'string' ? d : anders ) ) : anders; };
    var kruisje = function ( soort, sleutel ) { return '<button type="button" class="gtxxl-wz__weg" data-soort="' + soort + '" data-sleutel="' + veilig( sleutel ) + '" aria-label="' + t( 'verwijder' ) + '" title="' + t( 'verwijder' ) + '">&times;</button>'; };

    function zichtbaar() { return $.grep( lijnen, function ( l ) { return l.aantal > 0; } ); }
    function vind( sleutel ) { return $.grep( lijnen, function ( l ) { return l.sleutel === sleutel; } )[ 0 ]; }
    function klaarVoorProducten() { return 'kopie' === modus || !! klant; }

    /* ---------------------------------------------------------------- */
    /* De lijst                                                           */
    /* ---------------------------------------------------------------- */

    function teken() {
        var html = '', rekent = false;
        $.each( zichtbaar(), function ( i, l ) {
            var anders = l.prijs != null;
            var toon = anders ? l.prijs : l.stuk;
            html += '<tr class="gtxxl-wz__regel">' +
                '<td class="gtxxl-wz__foto">' + ( l.foto ? '<img src="' + veilig( l.foto ) + '" alt="">' : '' ) + '</td>' +
                '<td class="gtxxl-wz__naam"><strong>' + veilig( l.naam ) + '</strong>' + ( l.sku ? '<span>' + veilig( l.sku ) + '</span>' : '' ) +
                    ( anders && l.standaard != null ? '<em>' + veilig( tp( 'normaal', geld( l.standaard ) ) ) + '</em>' : '' ) + '</td>' +
                '<td class="gtxxl-wz__prijs"><span class="gtxxl-bm__prijs' + ( anders ? ' is-anders' : '' ) + '">' + veilig( G.valuta ) +
                    '<input type="text" inputmode="decimal" data-sleutel="' + veilig( l.sleutel ) + '" value="' + ( toon == null ? '' : getal( toon ) ) + '"' + ( toon == null ? ' disabled' : '' ) + ' aria-label="' + t( 'prijs' ) + '" title="' + t( 'prijs' ) + '"></span></td>' +
                '<td><span class="gtxxl-wz__aantal" data-sleutel="' + veilig( l.sleutel ) + '">' +
                    '<button type="button" class="button" data-stap="-1" aria-label="' + t( 'minder' ) + '">−</button>' +
                    '<input type="number" min="0" step="1" value="' + l.aantal + '" aria-label="' + t( 'aantal' ) + '">' +
                    '<button type="button" class="button" data-stap="1" aria-label="' + t( 'meer' ) + '">+</button></span>' + kruisje( 'lijn', l.sleutel ) + '</td></tr>';
        } );
        if ( 'kopie' === modus && G.kopie ) {
            $.each( G.kopie.regels, function ( i, r ) {
                if ( 'fee' === r.soort && overig[ r.id ] ) {
                    html += '<tr class="gtxxl-wz__regel gtxxl-bm__vast"><td class="gtxxl-wz__foto"></td>' +
                        '<td class="gtxxl-wz__naam"><strong>' + veilig( r.naam ) + '</strong><span>' + t( 'kosten' ) + '</span></td>' +
                        '<td class="gtxxl-wz__prijs">' + veilig( geld( r.bedrag ) ) + '</td><td>' + kruisje( 'overig', r.id ) + '</td></tr>';
                }
            } );
        }
        var heeft = zichtbaar().length > 0;
        if ( heeft && 'geen' !== levering ) {
            rekent = ! uitkomst;
            var v = uitkomst && uitkomst.verzend, ts = uitkomst && uitkomst.toeslag;
            if ( ts ) {
                html += '<tr class="gtxxl-wz__regel gtxxl-bm__vast"><td class="gtxxl-wz__foto"></td>' +
                    '<td class="gtxxl-wz__naam"><strong>' + veilig( ts.naam ) + '</strong><span>' + ts.stuks + ' ×</span></td>' +
                    '<td class="gtxxl-wz__prijs">' + veilig( geld( ts.bedrag ) ) + '</td><td></td></tr>';
            }
            var afhalen = 'afhalen' === levering;
            html += '<tr class="gtxxl-wz__regel gtxxl-bm__vast"><td class="gtxxl-wz__foto"></td>' +
                '<td class="gtxxl-wz__naam"><strong>' + t( afhalen ? 'afhalen' : 'verzendkosten' ) + '</strong><span>' +
                    ( ! uitkomst ? t( 'rekenen' ) : ( v && v.kan ? veilig( afhalen ? v.naam : v.naam + ' · ' + v.gewicht + ' kg' ) : veilig( v ? v.reden : '' ) ) ) + '</span></td>' +
                '<td class="gtxxl-wz__prijs">' + ( v && v.kan ? ( afhalen ? t( 'gratis' ) : veilig( geld( v.bedrag ) ) ) : '' ) + '</td>' +
                '<td>' + kruisje( 'levering', '' ) + '</td></tr>';
        } else if ( heeft ) {
            rekent = ! uitkomst;
        }
        if ( ! html ) {
            html = '<tr><td class="gtxxl-wz__leeg">' + t( klaarVoorProducten() ? 'geenRegels' : 'eerstKlant' ) + '</td></tr>';
        }
        $regels.html( html );

        var som = uitkomst ? uitkomst.totaal : 0;
        $totaal.html( '<div class="gtxxl-wz__rij is-slot"><span>' + t( 'totaal' ) + ( uitkomst && uitkomst.btw_vrij ? ' <small>(' + t( 'zonderBtw' ) + ')</small>' : '' ) + '</span><strong>' + ( rekent ? '…' : veilig( geld( heeft || uitkomst ? som : 0 ) ) ) + '</strong></div>' );
        var kanNiet = uitkomst && uitkomst.verzend && ! uitkomst.verzend.kan;
        $( '#gtxxl-bm-doen' ).prop( 'disabled', bezig || rekent || ! heeft || ! klaarVoorProducten() || !! kanNiet );
        $( '#gtxxl-bm-zoek' ).prop( 'disabled', ! klaarVoorProducten() );
        $( 'input[name="gtxxl-bm-levering"]' ).prop( 'disabled', $( '#gtxxl-bm-niet-verzenden' ).is( ':checked' ) ).filter( '[value="' + levering + '"]' ).prop( 'checked', true );
        tekenKlant();
    }

    function gekozen() {
        var aantallen = {}, extra = {}, lijst = [];
        $.each( zichtbaar(), function ( i, l ) {
            ( 'regel' === l.soort ? aantallen : extra )[ l.id ] = { aantal: l.aantal, prijs: l.prijs == null ? '' : l.prijs };
        } );
        $.each( overig, function ( id, aan ) { if ( aan ) { lijst.push( id ); } } );
        var data = { nonce: G.nonce, soort: modus, aantallen: aantallen, extra: extra, overig: lijst, levering: levering };
        if ( 'kopie' === modus ) {
            data.order_id = G.kopie.order_id;
            data.niet_verzenden = $( '#gtxxl-bm-niet-verzenden' ).is( ':checked' ) ? '1' : '0';
        } else {
            data.klant = klant || {};
        }
        return data;
    }

    // Er is iets veranderd: de server rekent prijzen, verzendkosten, toeslag en totaal opnieuw uit.
    function herreken() {
        clearTimeout( rekenTimer );
        var nr = ++rekenNr;
        uitkomst = null;
        if ( ! klaarVoorProducten() || ( ! zichtbaar().length && 'kopie' === modus ) ) {
            uitkomst = { totaal: 0, verzend: null, toeslag: null, regels: [] };
            teken();
            return;
        }
        teken();
        rekenTimer = setTimeout( function () {
            $.post( G.ajax, $.extend( { action: 'gtxxl_maak_reken' }, gekozen() ) ).done( function ( antwoord ) {
                if ( nr !== rekenNr ) { return; }
                if ( ! antwoord || ! antwoord.success ) {
                    uitkomst = { totaal: 0, verzend: { kan: false, reden: '' }, toeslag: null, regels: [] };
                    $melding.html( '<p class="gtxxl-wz__fout">' + veilig( fout( antwoord, G.t.rekenFout ) ) + '</p>' );
                    // Klopt er iets niet aan de klantgegevens, dan gaat het formulier weer open.
                    if ( antwoord && antwoord.data && 'klant' === antwoord.data.code ) { openForm( klant ); }
                    teken();
                    return;
                }
                uitkomst = antwoord.data;
                $melding.html( $.map( uitkomst.let_op || [], function ( tekst ) { return '<p class="gtxxl-wz__waarschuwing">' + veilig( tekst ) + '</p>'; } ).join( '' ) );
                $.each( uitkomst.regels, function ( i, r ) {
                    var l = vind( r.sleutel );
                    if ( l ) { l.naam = r.naam; l.sku = r.sku; l.foto = r.foto; l.standaard = r.standaard; l.stuk = r.standaard; }
                } );
                teken();
            } ).fail( function () {
                if ( nr !== rekenNr ) { return; }
                uitkomst = { totaal: 0, verzend: { kan: false, reden: '' }, toeslag: null, regels: [] };
                $melding.html( '<p class="gtxxl-wz__fout">' + veilig( G.t.rekenFout ) + '</p>' );
                teken();
            } );
        }, 250 );
    }

    function zetLevering( nieuw ) {
        levering = nieuw;
        herreken();
    }

    function voegToe( id, naam ) {
        var l = vind( 'p' + id );
        if ( l ) {
            l.aantal++;
        } else {
            lijnen.push( { sleutel: 'p' + id, soort: 'extra', id: id, naam: naam, sku: '', foto: '', aantal: 1, prijs: null, stuk: null, standaard: null } );
        }
        herreken();
    }

    // Kopie: alles zoals in de bestelling, of alleen wat er is terugbetaald. Wat is toegevoegd blijft staan.
    function kies( alleenTerug ) {
        lijnen = $.grep( lijnen, function ( l ) { return 'extra' === l.soort; } );
        overig = {};
        var basis = [];
        $.each( G.kopie.regels, function ( i, r ) {
            if ( 'product' === r.soort ) {
                basis.push( { sleutel: 'r' + r.id, soort: 'regel', id: r.id, naam: r.naam, sku: r.sku, foto: r.foto, aantal: r.bestaat ? ( alleenTerug ? r.terug : r.aantal ) : 0, prijs: null, stuk: r.stuk, standaard: r.stuk } );
            } else {
                overig[ r.id ] = alleenTerug ? r.terug > 0 : true;
            }
        } );
        lijnen = basis.concat( lijnen );
        if ( ! $( '#gtxxl-bm-niet-verzenden' ).is( ':checked' ) ) {
            levering = alleenTerug ? ( G.kopie.verzend_terug ? 'verzenden' : 'geen' ) : G.kopie.levering;
        }
        herreken();
    }

    /* ---------------------------------------------------------------- */
    /* De klant                                                           */
    /* ---------------------------------------------------------------- */

    function landNaam( code ) {
        var naam = code;
        $.each( G.landen, function ( i, l ) { if ( l.code === code ) { naam = l.naam; } } );
        return naam;
    }

    function tekenKlant() {
        if ( 'nieuw' !== modus ) { return; }
        var formOpen = $form.is( ':visible' );
        // Tijdens het invullen van de klant maakt de productlijst plaats voor het formulier.
        $overlay.find( '.gtxxl-bm__producten' ).toggle( ! formOpen );
        $overlay.find( '.gtxxl-bm__klant' ).toggleClass( 'is-open', formOpen );
        $overlay.find( '.gtxxl-bm__klant-zoek' ).toggle( ! klant && ! formOpen );
        $kaart.toggle( !! klant && ! formOpen );
        if ( ! klant ) { return; }
        var naam = $.trim( klant.voornaam + ' ' + klant.achternaam );
        var html = '<div class="gtxxl-bm__klant-tekst"><strong>' + veilig( naam ) + ( klant.bedrijf ? ' · ' + veilig( klant.bedrijf ) : '' ) + '</strong>' +
            '<span>' + veilig( klant.adres + ( klant.adres2 ? ' ' + klant.adres2 : '' ) + ', ' + klant.postcode + ' ' + klant.plaats + ', ' + landNaam( klant.land ) ) + '</span>' +
            ( klant.ander ? '<span>' + t( 'aflever' ) + ': ' + veilig( klant.a_adres + ', ' + klant.a_postcode + ' ' + klant.a_plaats + ', ' + landNaam( klant.a_land ) ) + '</span>' : '' ) +
            '<span>' + veilig( klant.email ) + ( klant.telefoon ? ' · ' + veilig( klant.telefoon ) : '' ) + ' · ' + t( klant.taal ) + ' · ' + veilig( klantLaatste ? tp( 'laatste', klantLaatste ) : G.t.nieuweKlant ) + '</span>' +
            ( klant.btw ? '<span class="gtxxl-bm__btw">' + veilig( klant.btw ) + ( uitkomst && uitkomst.btw_tekst ? ' – ' + veilig( uitkomst.btw_tekst ) : '' ) + '</span>' : '' ) +
            '</div><div class="gtxxl-bm__klant-links"><button type="button" class="button-link" id="gtxxl-bm-klant-aanpassen">' + t( 'aanpassen' ) + '</button>' +
            '<button type="button" class="button-link" id="gtxxl-bm-klant-andere">' + t( 'andereKlant' ) + '</button></div>';
        $kaart.html( html );
        $( '#gtxxl-bm-mail-tekst' ).text( tp( 'mailNaar', klant.email ) );
    }

    function openForm( velden ) {
        $form.find( '[data-veld]' ).each( function () {
            var veld = $( this ).data( 'veld' ), waarde = velden ? velden[ veld ] : '';
            if ( 'checkbox' === this.type ) {
                this.checked = !! waarde;
            } else if ( 'SELECT' === this.tagName ) {
                if ( waarde ) { $( this ).val( waarde ); } else { this.selectedIndex = 0; }
            } else {
                $( this ).val( waarde || '' );
            }
        } );
        if ( ! velden ) {
            // Nieuwe klant: de taal volgt het land.
            $form.find( '[data-veld="land"]' ).trigger( 'change' );
        }
        $form.find( '.gtxxl-bm__aflever' ).toggle( $form.find( '[data-veld="ander"]' ).is( ':checked' ) );
        $form.show();
        tekenKlant();
        controleerBtw();
        $form.find( 'input' ).first().trigger( 'focus' );
    }

    // Het btw-nummer controleren terwijl de klant wordt ingevuld: de uitkomst komt direct onder het veld, zoals bij
    // het afrekenen. Naam, adres en land tellen mee, dus ook een wijziging daarin vraagt opnieuw na.
    var btwTimer = null, btwNr = 0, $btwUitkomst = null;
    function controleerBtw() {
        clearTimeout( btwTimer );
        var nr = ++btwNr, velden = {};
        $form.find( '[data-veld]' ).each( function () {
            velden[ $( this ).data( 'veld' ) ] = 'checkbox' === this.type ? ( this.checked ? '1' : '' ) : $.trim( $( this ).val() || '' );
        } );
        if ( ! $btwUitkomst ) {
            $btwUitkomst = $( '<div class="gtxxl-bm__btwcheck"></div>' ).insertAfter( $form.find( '[data-veld="btw"]' ).closest( '.gtxxl-bm__rij' ) );
        }
        if ( ! velden.btw ) {
            $btwUitkomst.hide().empty();
            return;
        }
        $btwUitkomst.attr( 'class', 'gtxxl-bm__btwcheck is-bezig' ).text( t( 'btwBezig' ) ).show();
        btwTimer = setTimeout( function () {
            $.post( G.ajax, { action: 'gtxxl_maak_btw', nonce: G.nonce, klant: velden, levering: levering } ).done( function ( antwoord ) {
                if ( nr !== btwNr ) { return; }
                if ( ! antwoord || ! antwoord.success || ! antwoord.data || ! antwoord.data.tekst ) {
                    $btwUitkomst.hide().empty();
                    return;
                }
                $btwUitkomst.attr( 'class', 'gtxxl-bm__btwcheck is-' + antwoord.data.soort ).empty().append( $( '<span></span>' ).text( antwoord.data.tekst ) );
                if ( antwoord.data.naam ) {
                    $btwUitkomst.append( ' ' ).append( $( '<button type="button" class="button-link gtxxl-bm__btwnaam"></button>' ).text( tp( 'btwNaam', antwoord.data.naam ) ).data( 'naam', antwoord.data.naam ) );
                }
            } ).fail( function () {
                if ( nr === btwNr ) { $btwUitkomst.hide().empty(); }
            } );
        }, 700 );
    }

    function leesForm() {
        var velden = {}, mist = false;
        $form.find( '[data-veld]' ).each( function () {
            var veld = $( this ).data( 'veld' );
            velden[ veld ] = 'checkbox' === this.type ? ( this.checked ? '1' : '' ) : $.trim( $( this ).val() || '' );
        } );
        $.each( [ 'voornaam', 'achternaam', 'email', 'adres', 'postcode', 'plaats', 'land' ].concat( velden.ander ? [ 'a_adres', 'a_postcode', 'a_plaats', 'a_land' ] : [] ), function ( i, veld ) {
            var leeg = ! velden[ veld ];
            $form.find( '[data-veld="' + veld + '"]' ).toggleClass( 'is-fout', leeg );
            mist = mist || leeg;
        } );
        return mist ? null : velden;
    }

    function zetKlant( velden, laatste ) {
        klant = velden;
        klantLaatste = laatste || '';
        $form.hide();
        if ( window.gtxxlZoeker ) { window.gtxxlZoeker.order_id = 0; window.gtxxlZoeker.taal = klant ? klant.taal : ''; }
        if ( ! klant ) {
            $( '#gtxxl-bm-klant-zoek' ).val( null ).trigger( 'change.select2' );
            $( '#gtxxl-bm-mail-tekst' ).text( G.t.mailZonder );
        }
        herreken();
    }

    /* ---------------------------------------------------------------- */
    /* Openen, sluiten, maken                                             */
    /* ---------------------------------------------------------------- */

    function open( nieuweModus ) {
        modus = nieuweModus;
        bezig = false;
        lijnen = []; overig = {}; uitkomst = null; klant = null; klantLaatste = '';
        $melding.empty();
        $form.hide();
        $overlay.find( '.gtxxl-bm__producten' ).show();
        $overlay.find( '.gtxxl-bm__klant' ).removeClass( 'is-open' );
        $overlay.find( '.gtxxl-bm' ).toggleClass( 'is-nieuw', 'nieuw' === modus ).toggleClass( 'is-kopie', 'kopie' === modus );
        $overlay.find( '[data-alleen]' ).each( function () { $( this ).toggle( $( this ).data( 'alleen' ) === modus ); } );
        $( '#gtxxl-bm-titel' ).text( 'kopie' === modus ? tp( 'titelKopie', G.kopie.nummer ) : G.t.titelNieuw );
        $( '#gtxxl-bm-doen' ).text( 'kopie' === modus ? G.t.doenKopie : G.t.doenNieuw ).data( 'tekst', 'kopie' === modus ? G.t.doenKopie : G.t.doenNieuw );
        $( '#gtxxl-bm-niet-verzenden' ).prop( 'checked', false );
        $( 'input[name="gtxxl-bm-voorraad"][value=""]' ).prop( 'checked', true );
        // De mail met betaallink staat standaard aan.
        $( '#gtxxl-bm-mail' ).prop( 'checked', true );
        $( '#gtxxl-bm-bericht' ).val( '' ).show();
        if ( window.gtxxlZoeker ) {
            zoekerVoor = zoekerVoor || { order_id: window.gtxxlZoeker.order_id, taal: window.gtxxlZoeker.taal || '' };
        }
        $overlay.css( 'display', 'flex' );
        if ( 'kopie' === modus ) {
            if ( window.gtxxlZoeker ) { window.gtxxlZoeker.order_id = G.kopie.order_id; window.gtxxlZoeker.taal = ''; }
            $( '#gtxxl-bm-mail-tekst' ).text( G.kopie.mail ? tp( 'mailNaar', G.kopie.mail ) : G.t.mailZonder );
            $( '#gtxxl-bm-terugbetaald, .gtxxl-bm__punt' ).toggle( !! G.kopie.terugbetaald );
            kies( false );
        } else {
            levering = 'verzenden';
            zetKlant( null );
        }
    }

    function sluit() {
        if ( bezig ) { return; }
        $overlay.hide();
        if ( window.gtxxlZoeker && zoekerVoor ) { window.gtxxlZoeker.order_id = zoekerVoor.order_id; window.gtxxlZoeker.taal = zoekerVoor.taal; }
    }

    function meld( tekst ) {
        bezig = false;
        $melding.html( '<p class="gtxxl-wz__fout">' + veilig( tekst ) + '</p>' );
        $( '#gtxxl-bm-doen' ).text( $( '#gtxxl-bm-doen' ).data( 'tekst' ) );
        teken();
    }

    function doen() {
        if ( bezig ) { return; }
        if ( ! zichtbaar().length ) { meld( G.t.leeg ); return; }
        bezig = true;
        $melding.empty();
        $( '#gtxxl-bm-doen' ).prop( 'disabled', true ).text( G.t.bezig );
        $.post( G.ajax, $.extend( {
            action: 'gtxxl_maak_bestelling',
            voorraad: $( 'input[name="gtxxl-bm-voorraad"]:checked' ).val() || '',
            mail: $( '#gtxxl-bm-mail' ).is( ':checked' ) ? '1' : '0',
            bericht: $( '#gtxxl-bm-bericht' ).val()
        }, gekozen() ) ).done( function ( antwoord ) {
            if ( ! antwoord || ! antwoord.success ) {
                meld( fout( antwoord, G.t.mislukt ) );
                return;
            }
            // Door naar de nieuwe bestelling.
            window.location.href = antwoord.data.url;
        } ).fail( function () {
            meld( G.t.geenVerb );
        } );
    }

    $( function () {
        // Een gemaakte bestelling houdt de taal van de klant, ook als de medewerker haar opslaat.
        if ( G.taal ) {
            $( '#dropdown_shop_order_language' ).val( G.taal );
        }

        $overlay = $( '#gtxxl-bm-overlay' );
        if ( ! $overlay.length ) { return; }
        $regels  = $overlay.find( '.gtxxl-wz__regels tbody' );
        $totaal  = $overlay.find( '.gtxxl-bm__totaal' );
        $melding = $overlay.find( '.gtxxl-bm__melding' );
        $kaart   = $overlay.find( '.gtxxl-bm__klant-kaart' );
        $form    = $overlay.find( '.gtxxl-bm__klant-form' );

        // "Snelle bestelling" naast "Bestelling toevoegen".
        var $toevoegen = $( '.wrap .page-title-action' ).first();
        if ( $toevoegen.length ) {
            $( '<a href="#" class="page-title-action" id="gtxxl-bm-nieuw"></a>' ).text( G.t.knopNieuw ).insertAfter( $toevoegen );
        }
        $( document ).on( 'click', '#gtxxl-bm-nieuw', function ( e ) { e.preventDefault(); open( 'nieuw' ); } );
        $( document ).on( 'click', '#gtxxl-bm-kopie', function () { open( 'kopie' ); } );

        // "Bestelling dupliceren" hoort bij "Bestelling wijzigen" en "Terugbetalen", links onder de producten. Dat blok
        // wordt na een wijziging opnieuw geladen; daarom na elke aanroep naar de server kijken of de knop nog goed staat.
        var plaatsKnop = function () {
            var $knop = $( '#gtxxl-bm-kopie' ), $terug = $( '#woocommerce-order-items .refund-items' ).first(), $wijzig = $( '#gtxxl-wz-open' );
            var $doel = $wijzig.length ? $wijzig : $terug;
            if ( $knop.length && $doel.length && ! $knop.next().is( $doel ) ) { $knop.insertBefore( $doel ).after( ' ' ); }
        };
        plaatsKnop();
        $( document ).ajaxComplete( plaatsKnop );

        $overlay.on( 'click', '.gtxxl-wz__sluit', sluit );
        // Sluiten door ernaast te klikken: alleen als de klik buiten het venster begint én eindigt.
        var buitenBegonnen = false;
        $overlay.on( 'mousedown', function ( e ) { buitenBegonnen = e.target === this; } );
        $overlay.on( 'mouseup', function ( e ) { if ( buitenBegonnen && e.target === this ) { sluit(); } buitenBegonnen = false; } );
        $( document ).on( 'keydown', function ( e ) { if ( 'Escape' === e.key && $overlay.is( ':visible' ) && ! $( '.select2-container--open' ).length ) { sluit(); } } );

        // Aantal, prijs en weghalen.
        var zetAantal = function ( sleutel, aantal ) {
            var l = vind( sleutel );
            if ( ! l ) { return; }
            l.aantal = Math.max( 0, Math.min( 9999, parseInt( aantal, 10 ) || 0 ) );
            if ( 0 === l.aantal && 'extra' === l.soort ) { lijnen = $.grep( lijnen, function ( x ) { return x !== l; } ); }
            herreken();
        };
        $overlay.on( 'click', '.gtxxl-wz__aantal button', function () {
            var $a = $( this ).closest( '.gtxxl-wz__aantal' );
            zetAantal( $a.data( 'sleutel' ), ( parseInt( $a.find( 'input' ).val(), 10 ) || 0 ) + parseInt( $( this ).data( 'stap' ), 10 ) );
        } );
        $overlay.on( 'change', '.gtxxl-wz__aantal input', function () { zetAantal( $( this ).closest( '.gtxxl-wz__aantal' ).data( 'sleutel' ), $( this ).val() ); } );
        $overlay.on( 'change', '.gtxxl-bm__prijs input', function () {
            var l = vind( $( this ).data( 'sleutel' ) );
            if ( ! l ) { return; }
            var invoer = $.trim( $( this ).val() ).replace( /\s|€/g, '' ).replace( ',', '.' );
            var prijs = '' === invoer ? NaN : parseFloat( invoer );
            // Leeg, onleesbaar of gelijk aan de gewone prijs: terug naar de gewone prijs.
            l.prijs = isNaN( prijs ) || prijs < 0 || ( l.standaard != null && Math.abs( prijs - l.standaard ) < 0.005 ) ? null : Math.round( prijs * 100 ) / 100;
            herreken();
        } );
        // Aanklikken selecteert het hele bedrag, zodat je er meteen overheen typt.
        $overlay.on( 'focus', '.gtxxl-bm__prijs input, .gtxxl-wz__aantal input', function () { var veld = this; setTimeout( function () { veld.select(); }, 0 ); } );
        $overlay.on( 'keydown', '.gtxxl-bm__prijs input, .gtxxl-wz__aantal input', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); $( this ).trigger( 'blur' ); } } );
        $overlay.on( 'click', '.gtxxl-wz__weg', function () {
            var soort = $( this ).data( 'soort' );
            if ( 'levering' === soort ) {
                zetLevering( 'geen' );
            } else if ( 'overig' === soort ) {
                overig[ $( this ).data( 'sleutel' ) ] = false;
                herreken();
            } else {
                zetAantal( $( this ).data( 'sleutel' ), 0 );
            }
        } );

        $overlay.on( 'change', 'input[name="gtxxl-bm-levering"]', function () { zetLevering( $( this ).val() ); } );
        // Hoeft de bestelling niet verzonden te worden, dan ook geen verzendkosten.
        $overlay.on( 'change', '#gtxxl-bm-niet-verzenden', function () {
            if ( this.checked ) { leveringVoor = levering; zetLevering( 'geen' ); } else { zetLevering( leveringVoor ); }
        } );

        $overlay.on( 'change', '#gtxxl-bm-zoek', function () {
            var id = $( this ).val();
            if ( ! id ) { return; }
            var naam = $( this ).find( 'option:selected' ).text();
            $( this ).val( null ).trigger( 'change.select2' );
            $melding.empty();
            voegToe( id, naam );
        } );

        $overlay.on( 'click', '#gtxxl-bm-terugbetaald', function () { kies( true ); } );
        $overlay.on( 'click', '#gtxxl-bm-alles', function () { kies( false ); } );
        $overlay.on( 'change', '#gtxxl-bm-mail', function () { $( '#gtxxl-bm-bericht' ).toggle( this.checked ); } );
        $overlay.on( 'click', '#gtxxl-bm-doen', doen );

        // De klant: zoeken in eerdere bestellingen, of nieuw invullen.
        if ( $.fn.selectWoo ) {
            $( '#gtxxl-bm-klant-zoek' ).selectWoo( {
                placeholder: G.t.klantZoek,
                allowClear: true,
                minimumInputLength: 2,
                ajax: {
                    url: G.ajax,
                    dataType: 'json',
                    delay: 300,
                    data: function ( vraag ) { return { action: 'gtxxl_maak_zoek_klant', nonce: G.nonce, term: vraag.term }; },
                    processResults: function ( antwoord ) { return { results: antwoord && antwoord.success ? antwoord.data : [] }; }
                },
                language: {
                    inputTooShort: function () { return G.t.kort; },
                    searching: function () { return G.t.zoeken; },
                    noResults: function () { return G.t.geenKlant; },
                    errorLoading: function () { return G.t.rekenFout; }
                }
            } );
        }
        $overlay.on( 'change', '#gtxxl-bm-klant-zoek', function () {
            var id = $( this ).val();
            if ( ! id ) { return; }
            $.post( G.ajax, { action: 'gtxxl_maak_klant', nonce: G.nonce, order_id: id } ).done( function ( antwoord ) {
                if ( ! antwoord || ! antwoord.success ) {
                    $melding.html( '<p class="gtxxl-wz__fout">' + veilig( fout( antwoord, G.t.rekenFout ) ) + '</p>' );
                    return;
                }
                $melding.empty();
                zetKlant( antwoord.data.velden, antwoord.data.laatste );
            } );
        } );
        $overlay.on( 'click', '#gtxxl-bm-klant-nieuw', function () { klantLaatste = ''; openForm( null ); } );
        $overlay.on( 'click', '#gtxxl-bm-klant-aanpassen', function () { openForm( klant ); } );
        $overlay.on( 'click', '#gtxxl-bm-klant-andere', function () { zetKlant( null ); } );
        $overlay.on( 'click', '#gtxxl-bm-klant-terug', function () { $form.hide(); tekenKlant(); } );
        $overlay.on( 'click', '#gtxxl-bm-klant-klaar', function () {
            var velden = leesForm();
            if ( ! velden ) {
                $melding.html( '<p class="gtxxl-wz__fout">' + t( 'vulIn' ) + '</p>' );
                return;
            }
            $melding.empty();
            zetKlant( velden, klantLaatste );
        } );
        $form.on( 'change', '[data-veld="land"]', function () {
            var code = $( this ).val();
            $.each( G.landen, function ( i, l ) { if ( l.code === code ) { $form.find( '[data-veld="taal"]' ).val( l.taal ); } } );
        } );
        $form.on( 'input change', '[data-veld]', function () { $( this ).removeClass( 'is-fout' ); } );
        $form.on( 'change', '[data-veld="ander"]', function () { $form.find( '.gtxxl-bm__aflever' ).toggle( this.checked ); } );
        $form.on( 'input change', '[data-veld="btw"], [data-veld="bedrijf"], [data-veld="adres"], [data-veld="postcode"], [data-veld="plaats"], [data-veld="land"], [data-veld="ander"], [data-veld="a_land"]', controleerBtw );
        $form.on( 'click', '.gtxxl-bm__btwnaam', function () {
            $form.find( '[data-veld="bedrijf"]' ).val( $( this ).data( 'naam' ) ).removeClass( 'is-fout' );
            controleerBtw();
        } );
    } );
} )( jQuery );
