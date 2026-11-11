/* Saiteki SEO admin - v2.0 */
( function () {
    'use strict';

    var cfg = window.SaitekiAdmin || {};
    var toastTimer = null;

    function toast( message, isError ) {
        var el = document.getElementById( 'stk-toast' );
        if ( ! el ) return;
        el.textContent = message;
        el.className = 'show' + ( isError ? ' error' : '' );
        window.clearTimeout( toastTimer );
        toastTimer = window.setTimeout( function () { el.className = ''; }, isError ? 6000 : 2200 );
    }

    // Sends one admin-ajax request and always resolves with { ok, message, data }.
    function send( action, fields ) {
        var body = fields instanceof FormData ? fields : new FormData();
        if ( ! ( fields instanceof FormData ) ) {
            Object.keys( fields || {} ).forEach( function ( key ) { body.append( key, fields[ key ] ); } );
        }
        body.append( 'action', action );
        body.append( 'nonce', cfg.nonce );

        return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
            .then( function ( response ) { return response.json(); } )
            .then( function ( json ) {
                var data = ( json && json.data ) || {};
                return { ok: !! ( json && json.success ), message: data.message || '', data: data };
            } )
            .catch( function () {
                return { ok: false, message: cfg.i18n.error, data: {} };
            } );
    }

    function finish( result, resultEl ) {
        var message = result.message || ( result.ok ? cfg.i18n.saved : cfg.i18n.error );
        if ( resultEl ) {
            resultEl.textContent = message;
            resultEl.className = 'stk-result ' + ( result.ok ? 'ok' : 'fail' );
        } else {
            toast( message, ! result.ok );
        }
        if ( result.ok && result.data.reload ) {
            if ( resultEl ) toast( message, false );
            window.setTimeout( function () { window.location.reload(); }, 900 );
        }
    }

    // Switches save instantly.
    document.addEventListener( 'change', function ( event ) {
        var input = event.target;
        if ( ! input.matches || ! input.matches( 'input[data-stk-toggle]' ) ) return;

        input.disabled = true;
        send( 'saiteki_toggle', { key: input.getAttribute( 'data-stk-toggle' ), val: input.checked ? '1' : '0' } ).then( function ( result ) {
            input.disabled = false;
            if ( ! result.ok ) input.checked = ! input.checked;
            finish( result, null );
        } );
    } );

    // Forms.
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target;
        if ( ! form.matches || ! form.matches( 'form.stk-form' ) ) return;
        event.preventDefault();

        var button = form.querySelector( 'button[type="submit"]' );
        var label = button ? button.textContent : '';
        var body = new FormData( form );
        body.append( 'section', form.getAttribute( 'data-stk-section' ) );

        if ( button ) { button.disabled = true; button.textContent = cfg.i18n.working; }
        send( 'saiteki_save', body ).then( function ( result ) {
            if ( button ) { button.disabled = false; button.textContent = label; }
            if ( result.ok ) {
                var secret = form.querySelector( 'textarea[name="google_keys"]' );
                if ( secret ) secret.value = '';
            }
            finish( result, null );
        } );
    } );

    // Buttons.
    document.addEventListener( 'click', function ( event ) {
        var copy = event.target.closest ? event.target.closest( '[data-stk-copy]' ) : null;
        if ( copy ) {
            var text = copy.getAttribute( 'data-stk-copy' );
            if ( navigator.clipboard && navigator.clipboard.writeText ) {
                navigator.clipboard.writeText( text ).then( function () { toast( cfg.i18n.copied, false ); } );
            } else {
                var helper = document.createElement( 'textarea' );
                helper.value = text;
                document.body.appendChild( helper );
                helper.select();
                try { document.execCommand( 'copy' ); toast( cfg.i18n.copied, false ); } catch ( e ) {}
                document.body.removeChild( helper );
            }
            return;
        }

        var button = event.target.closest ? event.target.closest( '[data-stk-do]' ) : null;
        if ( ! button ) return;

        var question = button.getAttribute( 'data-stk-confirm' );
        if ( question && ! window.confirm( question ) ) return;

        var resultId = button.getAttribute( 'data-stk-result' );
        var resultEl = resultId ? document.getElementById( resultId ) : null;
        var label = button.textContent;
        var fields = { 'do': button.getAttribute( 'data-stk-do' ) };
        if ( button.hasAttribute( 'data-stk-pos' ) ) fields.pos = button.getAttribute( 'data-stk-pos' );

        button.disabled = true;
        button.textContent = cfg.i18n.working;
        if ( resultEl ) { resultEl.textContent = ''; resultEl.className = 'stk-result'; }

        send( 'saiteki_action', fields ).then( function ( result ) {
            button.disabled = false;
            button.textContent = label;
            finish( result, resultEl );
        } );
    } );

    // Live preview of the sitemap URLs while typing. The server applies the same rules on save.
    var preview = document.getElementById( 'stk-sitemap-preview' );
    if ( preview ) {
        var dirInput = document.getElementById( 'stk-sitemap-dir' );
        var indexInput = document.getElementById( 'stk-sitemap-index' );
        var prefixInput = document.getElementById( 'stk-sitemap-prefix' );
        var pages = parseInt( preview.getAttribute( 'data-pages' ), 10 ) || 1;

        var cleanDir = function ( value ) {
            return value.split( /[\/\\]+/ ).map( function ( part ) {
                return part.replace( /[^A-Za-z0-9._-]/g, '' ).replace( /^\.+|\.+$/g, '' );
            } ).filter( Boolean ).slice( 0, 6 ).join( '/' );
        };
        var cleanIndex = function ( value ) {
            var name = value.split( /[\/\\]/ ).pop().replace( /[^A-Za-z0-9._-]/g, '' ).replace( /\.xml$/i, '' ).replace( /^\.+|\.+$/g, '' );
            return ( name || 'sitemap_index' ) + '.xml';
        };
        var cleanPrefix = function ( value ) {
            return value.replace( /[^A-Za-z0-9_-]/g, '' ).replace( /^-+|-+$/g, '' ) || 'sitemap';
        };
        var set = function ( name, value ) {
            var el = preview.querySelector( '[data-stk-preview="' + name + '"]' );
            if ( el ) { el.textContent = value; el.style.display = value ? '' : 'none'; }
        };
        var update = function () {
            var dir = cleanDir( dirInput.value );
            var base = cfg.homeUrl + ( dir ? dir + '/' : '' );
            var prefix = cleanPrefix( prefixInput.value );
            set( 'index', base + cleanIndex( indexInput.value ) );
            set( 'pages', base + prefix + '-pages.xml' );
            set( 'first', base + prefix + '-1.xml' );
            set( 'last', pages > 1 ? base + prefix + '-' + pages + '.xml' : '' );
        };

        [ dirInput, indexInput, prefixInput ].forEach( function ( input ) { input.addEventListener( 'input', update ); } );
        update();
    }
}() );
