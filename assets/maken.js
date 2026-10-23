/* Het venster "Snelle bestelling", "Snelle offerte" en "Bestelling dupliceren" in de backoffice. */
( function ( $ ) {
    'use strict';

    var G = window.gtxxlMaken;
    if ( ! G ) {
        return;
    }

    var $overlay, $regels, $totaal, $melding, $kaart, $form;
    // 'nieuw' = snelle bestelling voor een klant; 'offerte' = offerte voor een klant; 'kopie' = de bestelling die
    // openstaat dupliceren.
    var modus = 'nieuw';
    // De producten in de lijst, de kostenregels van de oude bestelling (aan of uit), de levering en de klant.
    var lijnen = [], overig = {}, levering = 'verzenden', leveringVoor = 'verzenden', klant = null, klantLaatste = '';
    // Zelf ingevulde verzendkosten (inclusief btw), of null: dan gelden de kosten uit de verzendtabel.
    var verzendPrijs = null;
    // Korting in procenten op de producten (snelle bestelling en offerte), als tekst zoals ingevuld; '' = geen.
    var korting = '';
    // Een bestaande offerte wijzigen (modus is dan 'offerte'); verzendCheck: na de eerste berekening kijken of de
    // verzendkosten van de offerte gewoon die uit de tabel zijn.
    var wijzigen = false, verzendCheck = false;
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
            // Rekenhulp (inkoop, marge, prijs) achter het artikelnummer, zodra de server de regel heeft berekend.
            var reken = l.inkoopBekend && l.stuk != null && 'kopie' !== modus
                ? ' <button type="button" class="gtxxl-bm__reken" data-sleutel="' + veilig( l.sleutel ) + '" aria-label="' + t( 'rekenhulp' ) + '" title="' + t( 'rekenhulp' ) + '"><span class="dashicons dashicons-tag" aria-hidden="true"></span></button>'
                : '';
            html += '<tr class="gtxxl-wz__regel">' +
                '<td class="gtxxl-wz__foto">' + ( l.foto ? '<img src="' + veilig( l.foto ) + '" alt="">' : '' ) + '</td>' +
                '<td class="gtxxl-wz__naam"><strong>' + veilig( l.naam ) + '</strong>' + ( l.sku || reken ? '<span>' + veilig( l.sku ) + reken + '</span>' : '' ) +
                    ( anders && l.standaard != null ? '<em>' + veilig( tp( 'normaal', geld( l.standaard ) ) ) + '</em>' : '' ) +
                    ( ! anders && l.metKorting != null ? '<em class="gtxxl-bm__metkorting">' + veilig( tp( 'metKorting', geld( l.metKorting ) ) ) + '</em>' : '' ) +
                    // Alleen voor de medewerker: inkoop per stuk en opslag, zoals in het productscherm.
                    ( l.inkoopBekend ? '<small class="gtxxl-bm__inkoop">' + ( l.inkoop != null
                        ? veilig( String( G.t.inkoopRegel || '' ).replace( '%1$s', geld( l.inkoop ) ).replace( '%2$s', l.opslag == null ? '–' : String( l.opslag ).replace( '.', ',' ) + ' %' ) )
                        : t( 'geenInkoop' ) ) + '</small>' : '' ) + '</td>' +
                '<td class="gtxxl-wz__prijs"><span class="gtxxl-bm__prijs' + ( anders ? ' is-anders' : '' ) + '">' + veilig( G.valuta ) +
                    '<input type="text" inputmode="decimal" data-sleutel="' + veilig( l.sleutel ) + '" value="' + ( toon == null ? '' : getal( toon ) ) + '"' + ( toon == null ? ' disabled' : '' ) + ' aria-label="' + t( 'prijs' ) + '" title="' + t( 'prijs' ) + '"></span>' +
                    // Korting op dit product in procenten (niet bij dupliceren). Leeg = de korting op alle producten,
                    // die als grijze tekst in het vakje staat.
                    ( 'extra' === l.soort && 'kopie' !== modus
                        ? '<span class="gtxxl-bm__regelkorting' + ( l.korting ? ' is-anders' : '' ) + '">−<input type="text" inputmode="decimal" data-sleutel="' + veilig( l.sleutel ) + '" value="' + ( l.korting ? String( l.korting ).replace( '.', ',' ) : '' ) + '" placeholder="' + ( korting && ! anders ? String( korting ).replace( '.', ',' ) : '0' ) + '" aria-label="' + t( 'regelKorting' ) + '" title="' + t( 'regelKorting' ) + '">%</span>'
                        : '' ) + '</td>' +
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
                ( afhalen || ! uitkomst || ! v
                    ? '<td class="gtxxl-wz__prijs">' + ( afhalen && v && v.kan ? t( 'gratis' ) : '' ) + '</td>'
                    // Verzenden: het bedrag is aan te passen, net als de prijs van een product. Leeg = weer uit de tabel.
                    : '<td class="gtxxl-wz__prijs"><span class="gtxxl-bm__prijs' + ( v.eigen ? ' is-anders' : '' ) + '">' + veilig( G.valuta ) +
                        '<input type="text" inputmode="decimal" data-soort="verzend" value="' + ( v.kan ? getal( v.bedrag ) : '' ) + '" aria-label="' + t( 'verzendPrijs' ) + '" title="' + t( 'verzendPrijs' ) + '"></span>' +
                        ( v.eigen && v.standaard != null ? '<em class="gtxxl-bm__normaal">' + veilig( tp( 'normaal', geld( v.standaard ) ) ) + '</em>' : '' ) + '</td>' ) +
                '<td>' + kruisje( 'levering', '' ) + '</td></tr>';
        } else if ( heeft ) {
            rekent = ! uitkomst;
        }
        if ( ! html ) {
            html = '<tr><td class="gtxxl-wz__leeg">' + t( klaarVoorProducten() ? 'geenRegels' : 'eerstKlant' ) + '</td></tr>';
        }
        $regels.html( html );

        var som = uitkomst ? uitkomst.totaal : 0;
        var kortingRij = ! rekent && uitkomst && uitkomst.korting > 0 ? '<div class="gtxxl-wz__rij"><span>' + t( 'korting' ) + '</span><span>− ' + veilig( geld( uitkomst.korting ) ) + '</span></div>' : '';
        var m = ! rekent && heeft && uitkomst && uitkomst.marge;
        var margeBlok = m && ( m.inkoop > 0 || m.onbekend > 0 )
            ? '<div class="gtxxl-bm__marge">' + ( m.inkoop > 0
                ? '<div><span>' + t( 'inkoop' ) + ' ' + veilig( geld( m.inkoop ) ) + '</span><span>' + t( 'winst' ) + ' <strong>' + veilig( geld( m.winst ) ) + '</strong>' + ( m.opslag != null ? ' · ' + t( 'opslag' ) + ' ' + veilig( String( m.opslag ).replace( '.', ',' ) ) + ' %' : '' ) + '</span></div>'
                : '' ) +
                '<small>' + t( 'zonderBtwVerzend' ) + ( m.onbekend > 0 ? ' · ' + veilig( String( G.t.zonderInkoop || '' ).replace( '%d', m.onbekend ) ) : '' ) + '</small></div>'
            : '';
        $totaal.html( kortingRij + '<div class="gtxxl-wz__rij is-slot"><span>' + t( 'totaal' ) + ( uitkomst && uitkomst.btw_vrij ? ' <small>(' + t( 'zonderBtw' ) + ')</small>' : '' ) + '</span><strong>' + ( rekent ? '…' : veilig( geld( heeft || uitkomst ? som : 0 ) ) ) + '</strong></div>' + margeBlok );
        // Totaal € 0: er valt niets te betalen, dus geen betaallink; de bestelling gaat meteen naar "In behandeling".
        // Een offerte van € 0 maakt de server niet.
        var gratis = 'offerte' !== modus && heeft && !! uitkomst && ! rekent && som <= 0;
        $( '#gtxxl-bm-betaling' ).toggle( ! gratis );
        $( '#gtxxl-bm-gratis' ).toggle( gratis );
        var kanNiet = uitkomst && uitkomst.verzend && ! uitkomst.verzend.kan;
        $( '#gtxxl-bm-doen' ).prop( 'disabled', bezig || rekent || ! heeft || ! klaarVoorProducten() || !! kanNiet );
        $( '#gtxxl-bm-zoek' ).prop( 'disabled', ! klaarVoorProducten() );
        $( 'input[name="gtxxl-bm-levering"]' ).prop( 'disabled', $( '#gtxxl-bm-niet-verzenden' ).is( ':checked' ) ).filter( '[value="' + levering + '"]' ).prop( 'checked', true );
        tekenKlant();
    }

    function gekozen() {
        var aantallen = {}, extra = {}, lijst = [];
        $.each( zichtbaar(), function ( i, l ) {
            ( 'regel' === l.soort ? aantallen : extra )[ l.id ] = { aantal: l.aantal, prijs: l.prijs == null ? '' : l.prijs, korting: l.korting ? l.korting : '' };
        } );
        $.each( overig, function ( id, aan ) { if ( aan ) { lijst.push( id ); } } );
        var data = { nonce: G.nonce, soort: modus, aantallen: aantallen, extra: extra, overig: lijst, levering: levering, verzend_prijs: verzendPrijs == null ? '' : verzendPrijs, korting: 'kopie' === modus ? '' : korting };
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
                // Offerte wijzigen: zijn de verzendkosten gewoon die uit de tabel, dan geen eigen bedrag vasthouden.
                if ( verzendCheck ) {
                    verzendCheck = false;
                    var vz = uitkomst.verzend;
                    if ( vz && vz.standaard != null && verzendPrijs != null && Math.abs( verzendPrijs - vz.standaard ) < 0.005 ) { verzendPrijs = null; }
                }
                // Bij het wijzigen van een offerte blijft de waarschuwing over verdwenen producten staan.
                var weg = wijzigen && G.wijzig ? $.map( G.wijzig.weg || [], function ( naam ) { return tp( 'productWeg', naam ); } ) : [];
                $melding.html( $.map( weg.concat( uitkomst.let_op || [] ), function ( tekst ) { return '<p class="gtxxl-wz__waarschuwing">' + veilig( tekst ) + '</p>'; } ).join( '' ) );
                $.each( uitkomst.regels, function ( i, r ) {
                    var l = vind( r.sleutel );
                    if ( l ) { l.naam = r.naam; l.sku = r.sku; l.foto = r.foto; l.standaard = r.standaard; l.stuk = r.standaard; l.metKorting = r.met_korting; l.inkoop = r.inkoop; l.opslag = r.opslag; l.btw = r.btw || 1; l.inkoopBekend = true; }
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
            lijnen.push( { sleutel: 'p' + id, soort: 'extra', id: id, naam: naam, sku: '', foto: '', aantal: 1, prijs: null, korting: null, stuk: null, standaard: null } );
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
        if ( 'kopie' === modus ) { return; }
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
            '<span>' + veilig( klant.email ) + ( klant.telefoon ? ' · ' + veilig( klant.telefoon ) : '' ) + ' · ' + t( klant.taal ) + ( wijzigen ? '' : ' · ' + veilig( klantLaatste ? tp( 'laatste', klantLaatste ) : G.t.nieuweKlant ) ) + '</span>' +
            ( klant.btw ? '<span class="gtxxl-bm__btw">' + veilig( klant.btw ) + ( uitkomst && uitkomst.btw_tekst ? ' – ' + veilig( uitkomst.btw_tekst ) : '' ) + '</span>' : '' ) +
            '</div><div class="gtxxl-bm__klant-links"><button type="button" class="button-link" id="gtxxl-bm-klant-aanpassen">' + t( 'aanpassen' ) + '</button>' +
            '<button type="button" class="button-link" id="gtxxl-bm-klant-andere">' + t( 'andereKlant' ) + '</button></div>';
        $kaart.html( html );
        $( '#gtxxl-bm-mail-tekst' ).text( tp( wijzigen ? 'wijzigMail' : ( 'offerte' === modus ? 'offerteNaar' : 'mailNaar' ), klant.email ) );
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
            $( '#gtxxl-bm-mail-tekst' ).text( 'offerte' === modus ? G.t.offerteZonder : G.t.mailZonder );
        }
        herreken();
    }

    /* ---------------------------------------------------------------- */
    /* Openen, sluiten, maken                                             */
    /* ---------------------------------------------------------------- */

    function open( nieuweModus ) {
        // 'wijzig' = de offerte die openstaat wijzigen: hetzelfde venster als een nieuwe offerte, al ingevuld.
        wijzigen = 'wijzig' === nieuweModus && !! G.wijzig;
        modus = wijzigen ? 'offerte' : nieuweModus;
        bezig = false;
        lijnen = []; overig = {}; uitkomst = null; klant = null; klantLaatste = ''; verzendPrijs = null; korting = '';
        $( '#gtxxl-bm-korting' ).val( '' );
        $melding.empty();
        $form.hide();
        $overlay.find( '.gtxxl-bm__producten' ).show();
        $overlay.find( '.gtxxl-bm__klant' ).removeClass( 'is-open' );
        $overlay.find( '.gtxxl-bm' ).toggleClass( 'is-nieuw', 'kopie' !== modus ).toggleClass( 'is-kopie', 'kopie' === modus ).toggleClass( 'is-offerte', 'offerte' === modus );
        // data-alleen="nieuw offerte": alleen te zien in die modi.
        $overlay.find( '[data-alleen]' ).each( function () { $( this ).toggle( -1 !== String( $( this ).data( 'alleen' ) ).split( ' ' ).indexOf( modus ) ); } );
        var doenTekst = wijzigen ? G.t.doenWijzig : { nieuw: G.t.doenNieuw, offerte: G.t.doenOfferte, kopie: G.t.doenKopie }[ modus ];
        $( '#gtxxl-bm-titel' ).text( wijzigen ? tp( 'titelWijzig', G.wijzig.nummer ) : ( 'kopie' === modus ? tp( 'titelKopie', G.kopie.nummer ) : ( 'offerte' === modus ? G.t.titelOfferte : G.t.titelNieuw ) ) );
        $( '#gtxxl-bm-doen' ).text( doenTekst ).data( 'tekst', doenTekst );
        $( '#gtxxl-bm-geldig' ).val( wijzigen ? G.wijzig.geldig : G.geldig ).attr( 'min', G.vandaag );
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
        } else if ( wijzigen ) {
            // De producten met hun eigen prijs of korting, de levering en de verzendkosten van de offerte.
            lijnen = $.map( G.wijzig.regels, function ( r ) {
                return { sleutel: 'p' + r.id, soort: 'extra', id: r.id, naam: r.naam, sku: r.sku, foto: '', aantal: r.aantal, prijs: r.prijs, korting: r.korting, stuk: null, standaard: null };
            } );
            levering = G.wijzig.levering;
            verzendPrijs = 'verzenden' === levering ? G.wijzig.verzend : null;
            verzendCheck = true;
            zetKlant( G.wijzig.klant, '' );
            // Producten die niet meer bestaan, vallen bij opslaan weg: daar vooraf voor waarschuwen.
            $melding.html( $.map( G.wijzig.weg || [], function ( naam ) { return '<p class="gtxxl-wz__waarschuwing gtxxl-bm__weg">' + veilig( tp( 'productWeg', naam ) ) + '</p>'; } ).join( '' ) );
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
        var geldig = $( '#gtxxl-bm-geldig' ).val() || '';
        if ( 'offerte' === modus && ( ! /^\d{4}-\d{2}-\d{2}$/.test( geldig ) || geldig < G.vandaag ) ) { meld( G.t.geldigFout ); return; }
        bezig = true;
        $melding.empty();
        $( '#gtxxl-bm-doen' ).prop( 'disabled', true ).text( G.t.bezig );
        $.post( G.ajax, $.extend( {
            action: 'gtxxl_maak_bestelling',
            voorraad: $( 'input[name="gtxxl-bm-voorraad"]:checked' ).val() || '',
            mail: $( '#gtxxl-bm-mail' ).is( ':checked' ) ? '1' : '0',
            bericht: $( '#gtxxl-bm-bericht' ).val(),
            geldig: geldig,
            wijzig: wijzigen ? G.wijzig.order_id : ''
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

    /* ---------------------------------------------------------------- */
    /* Rekenhulp: inkoop, marge en prijs van één product                 */
    /* ---------------------------------------------------------------- */

    // Zoals in productbeheer: inkoop × (1 + marge%) × btw = prijs, maar met de btw van deze klant in plaats van
    // altijd 1,19. Inkoop wijzigen → marge opnieuw (prijs blijft), marge wijzigen → prijs, prijs wijzigen → marge.
    // OK zet de prijs in het prijsvak van het product.
    var $reken = null, rekenLijn = null;
    var leesGetal = function ( waarde ) { return parseFloat( String( waarde == null ? '' : waarde ).replace( /\s|€|%/g, '' ).replace( ',', '.' ) ); };
    var r2 = function ( x ) { return Math.round( x * 100 ) / 100; };
    var komma = function ( x, dec ) { return x.toFixed( dec ).replace( '.', ',' ); };

    // Scrollen met de muis boven een venster scrolt alleen het venster zelf, nooit de pagina erachter: ook niet als
    // het venster al onderaan of bovenaan is, of niets te scrollen heeft. Naast het venster scrolt de pagina gewoon.
    function houdScrollVast( e ) {
        var doos = this, dy = e.deltaY;
        if ( ! dy ) { return; }
        for ( var el = e.target; el && el !== doos.parentNode; el = el.parentNode ) {
            if ( 1 !== el.nodeType ) { continue; }
            var stijl = window.getComputedStyle( el ).overflowY;
            if ( ( 'auto' === stijl || 'scroll' === stijl ) && el.scrollHeight > el.clientHeight + 1 ) {
                var kanOp = el.scrollTop > 0, kanNeer = el.scrollTop + el.clientHeight < el.scrollHeight - 1;
                if ( ( dy < 0 && kanOp ) || ( dy > 0 && kanNeer ) ) { return; } // dit deel scrolt zelf
            }
            if ( el === doos ) { break; }
        }
        e.preventDefault();
    }

    function rekenhulp() {
        $reken = $( '<div class="gtxxl-bm-reken" id="gtxxl-bm-reken" hidden>' +
            '<div class="gtxxl-bm-reken__doos" role="dialog" aria-modal="true" aria-labelledby="gtxxl-bm-reken-titel">' +
                '<h3 id="gtxxl-bm-reken-titel"></h3>' +
                '<p class="gtxxl-bm-reken__normaal"></p>' +
                '<label class="gtxxl-bm-reken__rij" for="gtxxl-bm-reken-inkoop"><span>' + t( 'inkoopExcl' ) + '</span><span class="gtxxl-bm-reken__veld">' + veilig( G.valuta ) + '<input type="text" inputmode="decimal" id="gtxxl-bm-reken-inkoop"></span></label>' +
                '<label class="gtxxl-bm-reken__rij" for="gtxxl-bm-reken-marge"><span>' + t( 'marge' ) + '</span><span class="gtxxl-bm-reken__veld"><input type="text" inputmode="decimal" id="gtxxl-bm-reken-marge">%</span></label>' +
                '<label class="gtxxl-bm-reken__rij" for="gtxxl-bm-reken-prijs"><span id="gtxxl-bm-reken-prijslabel"></span><span class="gtxxl-bm-reken__veld">' + veilig( G.valuta ) + '<input type="text" inputmode="decimal" id="gtxxl-bm-reken-prijs"></span></label>' +
                '<p class="gtxxl-bm-reken__winst"></p>' +
                '<p class="gtxxl-wz__klein">' + t( 'rekenUitleg' ) + '</p>' +
                '<p class="gtxxl-bm-reken__knoppen"><button type="button" class="button button-primary" id="gtxxl-bm-reken-ok">' + t( 'ok' ) + '</button><button type="button" class="button-link" id="gtxxl-bm-reken-terug">' + t( 'annuleren' ) + '</button></p>' +
            '</div></div>' ).appendTo( $overlay );
        $reken.find( '.gtxxl-bm-reken__doos' )[ 0 ].addEventListener( 'wheel', houdScrollVast, { passive: false } );

        var $inkoop = $reken.find( '#gtxxl-bm-reken-inkoop' ), $marge = $reken.find( '#gtxxl-bm-reken-marge' ), $prijs = $reken.find( '#gtxxl-bm-reken-prijs' );
        var btw = function () { return rekenLijn && rekenLijn.btw ? rekenLijn.btw : 1; };
        var winst = function () {
            var c = leesGetal( $inkoop.val() ), p = leesGetal( $prijs.val() );
            $reken.find( '.gtxxl-bm-reken__winst' ).text( ! isNaN( c ) && c > 0 && ! isNaN( p ) ? tp( 'winstStuk', geld( p / btw() - c ) ) : '' );
        };
        var margeUitPrijs = function () {
            var c = leesGetal( $inkoop.val() ), p = leesGetal( $prijs.val() );
            if ( ! isNaN( c ) && c > 0 && ! isNaN( p ) ) { $marge.val( komma( r2( ( p / c / btw() - 1 ) * 100 ), 1 ) ); }
            winst();
        };
        $inkoop.on( 'input', margeUitPrijs );
        $prijs.on( 'input', margeUitPrijs );
        $marge.on( 'input', function () {
            var c = leesGetal( $inkoop.val() ), m = leesGetal( $marge.val() );
            if ( ! isNaN( c ) && c > 0 && ! isNaN( m ) ) { $prijs.val( komma( r2( c * ( 1 + m / 100 ) * btw() ), 2 ) ); }
            winst();
        } );
        $reken.on( 'focus', 'input', function () { var veld = this; setTimeout( function () { veld.select(); }, 0 ); } );
        $reken.on( 'keydown', 'input', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); $( '#gtxxl-bm-reken-ok' ).trigger( 'click' ); } } );
        $reken.on( 'click', '#gtxxl-bm-reken-terug', rekenDicht );
        $reken.on( 'click', '#gtxxl-bm-reken-ok', function () {
            var p = leesGetal( $prijs.val() ), l = rekenLijn;
            rekenDicht();
            if ( ! l || isNaN( p ) || p < 0 ) { return; }
            p = r2( p );
            // Gelijk aan de gewone prijs: weer de gewone prijs; anders een eigen prijs (een eigen korting vervalt).
            l.prijs = l.standaard != null && Math.abs( p - l.standaard ) < 0.005 ? null : p;
            l.korting = null;
            herreken();
        } );
        // Sluiten door ernaast te klikken: alleen als de klik buiten het venstertje begint én eindigt.
        var buiten = false;
        $reken.on( 'mousedown', function ( e ) { buiten = e.target === this; } );
        $reken.on( 'mouseup', function ( e ) { if ( buiten && e.target === this ) { rekenDicht(); } buiten = false; } );
        $overlay.on( 'click', '.gtxxl-bm__reken', function () { rekenOpen( vind( $( this ).data( 'sleutel' ) ) ); } );
    }

    function rekenOpen( l ) {
        if ( ! l ) { return; }
        rekenLijn = l;
        var btw = l.btw || 1, p = l.prijs != null ? l.prijs : ( l.metKorting != null ? l.metKorting : l.stuk );
        var procent = Math.round( ( btw - 1 ) * 100 );
        $reken.find( '#gtxxl-bm-reken-titel' ).text( l.naam );
        $reken.find( '.gtxxl-bm-reken__normaal' ).text( l.standaard != null ? tp( 'normaalPrijs', geld( l.standaard ) ) : '' );
        $reken.find( '#gtxxl-bm-reken-prijslabel' ).text( procent > 0 ? tp( 'prijsIncl', procent + ' %' ) : G.t.prijsZonder );
        $reken.find( '#gtxxl-bm-reken-inkoop' ).val( l.inkoop != null ? komma( l.inkoop, 2 ) : '' );
        $reken.find( '#gtxxl-bm-reken-prijs' ).val( p != null ? komma( p, 2 ) : '' ).trigger( 'input' );
        $reken.prop( 'hidden', false );
        $reken.find( '#gtxxl-bm-reken-marge' ).trigger( 'focus' );
    }

    function rekenDicht() {
        if ( $reken ) { $reken.prop( 'hidden', true ); }
        rekenLijn = null;
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

        // "Snelle bestelling" naast "Bestelling toevoegen". Op de pagina Offertes (dezelfde lijst, gefilterd) heet de
        // kop "Offertes" en staat er alleen "Snelle offerte".
        var $toevoegen = $( '.wrap .page-title-action' ).first();
        if ( $toevoegen.length && G.offertes ) {
            $( '.wrap h1.wp-heading-inline' ).first().text( G.t.offertes );
            $( '<a href="#" class="page-title-action" id="gtxxl-bm-offerte"></a>' ).text( G.t.knopOfferte ).insertAfter( $toevoegen );
            $toevoegen.remove();
        } else if ( $toevoegen.length ) {
            $( '<a href="#" class="page-title-action" id="gtxxl-bm-nieuw"></a>' ).text( G.t.knopNieuw ).insertAfter( $toevoegen );
        }
        $( document ).on( 'click', '#gtxxl-bm-nieuw', function ( e ) { e.preventDefault(); open( 'nieuw' ); } );
        $( document ).on( 'click', '#gtxxl-bm-offerte', function ( e ) { e.preventDefault(); open( 'offerte' ); } );
        $( document ).on( 'click', '#gtxxl-bm-wijzig', function ( e ) { e.preventDefault(); open( 'wijzig' ); } );

        // In een open of verlopen offerte heet de pagina "Offerte bewerken" en de kop "Offerte A23517".
        if ( G.wijzig && G.wijzig.nummer ) {
            $( '.wrap h1.wp-heading-inline' ).first().text( G.t.offerteBewerken );
            $( '.woocommerce-order-data__heading' ).first().contents().filter( function () {
                return 3 === this.nodeType && -1 !== this.nodeValue.indexOf( G.wijzig.nummer );
            } ).each( function () { this.nodeValue = ' ' + tp( 'offerteKop', G.wijzig.nummer ); } );
        }
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
        $overlay.find( '.gtxxl-wz' ).each( function () { this.addEventListener( 'wheel', houdScrollVast, { passive: false } ); } );
        // Sluiten door ernaast te klikken: alleen als de klik buiten het venster begint én eindigt.
        var buitenBegonnen = false;
        $overlay.on( 'mousedown', function ( e ) { buitenBegonnen = e.target === this; } );
        $overlay.on( 'mouseup', function ( e ) { if ( buitenBegonnen && e.target === this ) { sluit(); } buitenBegonnen = false; } );
        $( document ).on( 'keydown', function ( e ) {
            if ( 'Escape' !== e.key || ! $overlay.is( ':visible' ) || $( '.select2-container--open' ).length ) { return; }
            // Eerst de rekenhulp dicht, pas daarna het venster.
            if ( $reken && ! $reken.prop( 'hidden' ) ) { rekenDicht(); } else { sluit(); }
        } );
        rekenhulp();

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
            var invoer = $.trim( $( this ).val() ).replace( /\s|€/g, '' ).replace( ',', '.' );
            var prijs = '' === invoer ? NaN : parseFloat( invoer );
            if ( 'verzend' === $( this ).data( 'soort' ) ) {
                // Leeg of onleesbaar: weer de kosten uit de verzendtabel.
                var v = uitkomst && uitkomst.verzend;
                verzendPrijs = isNaN( prijs ) || prijs < 0 || ( v && v.standaard != null && Math.abs( prijs - v.standaard ) < 0.005 ) ? null : Math.round( prijs * 100 ) / 100;
                herreken();
                return;
            }
            var l = vind( $( this ).data( 'sleutel' ) );
            if ( ! l ) { return; }
            // Leeg, onleesbaar of gelijk aan de gewone prijs: terug naar de gewone prijs.
            l.prijs = isNaN( prijs ) || prijs < 0 || ( l.standaard != null && Math.abs( prijs - l.standaard ) < 0.005 ) ? null : Math.round( prijs * 100 ) / 100;
            // Een eigen prijs en een eigen korting gaan niet samen: de prijs wint.
            if ( l.prijs != null ) { l.korting = null; }
            herreken();
        } );
        // Korting op één product in procenten; leeg of 0 = weer de korting op alle producten (als die er is).
        $overlay.on( 'change', '.gtxxl-bm__regelkorting input', function () {
            var l = vind( $( this ).data( 'sleutel' ) );
            if ( ! l ) { return; }
            var p = parseFloat( $.trim( $( this ).val() ).replace( /[−\-%\s]/g, '' ).replace( ',', '.' ) );
            l.korting = isNaN( p ) || p <= 0 ? null : Math.min( 100, Math.round( p * 100 ) / 100 );
            // De korting rekent vanaf de gewone prijs, dus een eigen prijs vervalt.
            if ( l.korting ) { l.prijs = null; }
            herreken();
        } );
        // Aanklikken selecteert het hele bedrag, zodat je er meteen overheen typt.
        $overlay.on( 'focus', '.gtxxl-bm__prijs input, .gtxxl-bm__regelkorting input, .gtxxl-wz__aantal input', function () { var veld = this; setTimeout( function () { veld.select(); }, 0 ); } );
        $overlay.on( 'keydown', '.gtxxl-bm__prijs input, .gtxxl-bm__regelkorting input, .gtxxl-wz__aantal input', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); $( this ).trigger( 'blur' ); } } );
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

        // Korting in procenten: leeg of 0 = geen korting; meer dan 100 kan niet.
        $overlay.on( 'change', '#gtxxl-bm-korting', function () {
            var p = parseFloat( $.trim( $( this ).val() ).replace( '%', '' ).replace( ',', '.' ) );
            p = isNaN( p ) || p <= 0 ? '' : Math.min( 100, Math.round( p * 100 ) / 100 );
            $( this ).val( '' === p ? '' : String( p ).replace( '.', ',' ) );
            if ( String( p ) !== String( korting ) ) { korting = p; herreken(); }
        } );
        $overlay.on( 'keydown', '#gtxxl-bm-korting', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); $( this ).trigger( 'change' ); } } );
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
                // Naam en plaats boven, e-mailadres en laatste bestelling klein eronder.
                templateResult: function ( r ) {
                    if ( ! r.kop ) { return r.text; }
                    return $( '<span class="gtxxl-bm__treffer"><strong>' + veilig( r.kop ) + '</strong>' + ( r.sub ? '<small>' + veilig( r.sub ) + '</small>' : '' ) + '</span>' );
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
            $.each( G.landen, function ( i, l ) {
                if ( l.code !== code ) { return; }
                $form.find( '[data-veld="taal"]' ).val( l.taal );
                // Een factuuradres in een land waar we niet naar verzenden: meteen het afleveradres openen.
                if ( ! l.verzend ) {
                    $form.find( '[data-veld="ander"]' ).prop( 'checked', true );
                    $form.find( '.gtxxl-bm__aflever' ).show();
                }
            } );
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
