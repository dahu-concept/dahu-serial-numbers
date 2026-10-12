/*
 * The packing screen.
 *
 * No framework and no build step. This file is read by whoever maintains the
 * plugin, and a bench screen that needs a toolchain to change a label is a
 * bench screen nobody changes.
 *
 * Every string it can show was translated on the server and arrives in the
 * config block: a script that carries its own English shows English to a
 * French workshop and nobody can see why.
 */
( function () {
	'use strict';

	var configNode = document.getElementById( 'dahu-packing-config' );

	if ( ! configNode ) {
		return;
	}

	var config = JSON.parse( configNode.textContent );
	var t = config.strings;
	var root = document.getElementById( 'dahu-packing-root' );

	/*
	 * sessionStorage, not localStorage. A bench tablet is shared, and a token
	 * that survives the tab being closed is a token the next person inherits.
	 * It survives a reload, which is what a shop floor actually needs.
	 */
	var STORE_KEY = 'dahu-packing-token';

	var state = {
		token: null,
		stationLabel: '',
		view: 'signIn',
		queue: [],
		order: null,
		lineId: 0,
		busy: false,
		message: null
	};

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	function readToken() {
		try {
			return window.sessionStorage.getItem( STORE_KEY );
		} catch ( e ) {
			// Private browsing, or storage switched off. The screen still
			// works for the length of this page view.
			return null;
		}
	}

	function writeToken( token ) {
		try {
			if ( token ) {
				window.sessionStorage.setItem( STORE_KEY, token );
			} else {
				window.sessionStorage.removeItem( STORE_KEY );
			}
		} catch ( e ) {
			// Nothing to do: state.token is the live copy either way.
		}
	}

	/* ---------------------------------------------------------------------
	 * The API
	 * ------------------------------------------------------------------ */

	function request( method, path, body ) {
		var options = {
			method: method,
			headers: { 'Content-Type': 'application/json' }
		};

		if ( state.token ) {
			options.headers.Authorization = 'Bearer ' + state.token;
		}

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return window.fetch( config.root + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( response.ok ) {
					return data;
				}

				var error = new Error( ( data && data.message ) || t.offline );
				error.code = data && data.code;
				error.status = response.status;
				throw error;
			} );
		}, function () {
			/*
			 * The network itself failed, so the request never reached the
			 * shop. Said plainly, because the person at the bench has to know
			 * whether to scan again — and scanning again after a request that
			 * did arrive would be a double assignment.
			 */
			var error = new Error( t.offline );
			error.code = 'offline';
			throw error;
		} );
	}

	function handleError( error ) {
		if ( error.status === 401 && error.code !== 'dahu_serial_numbers_auth_invalid' ) {
			signOut( t.sessionOver );
			return;
		}

		state.message = { tone: 'error', text: error.message };
		render();
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	function signIn( login, password ) {
		state.busy = true;
		render();

		request( 'POST', '/auth/login', { login: login, password: password } )
			.then( function ( data ) {
				state.token = data.token;
				state.stationLabel = data.station_label;
				writeToken( data.token );
				state.busy = false;
				state.message = null;
				loadQueue();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	function signOut( message ) {
		if ( state.token ) {
			// Best effort: the token is dropped locally whether or not the
			// shop answers, because the person is walking away now.
			request( 'POST', '/auth/logout' ).catch( function () {} );
		}

		state.token = null;
		state.stationLabel = '';
		state.order = null;
		state.queue = [];
		state.view = 'signIn';
		state.message = message ? { tone: 'warning', text: message } : null;
		writeToken( null );
		render();
	}

	function loadQueue( search ) {
		state.busy = true;
		render();

		var path = '/orders';

		if ( search ) {
			path += '?q=' + encodeURIComponent( search );
		}

		request( 'GET', path )
			.then( function ( data ) {
				state.queue = data.orders || [];
				state.order = null;
				state.view = 'queue';
				state.busy = false;
				render();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	function openOrder( id ) {
		state.busy = true;
		render();

		request( 'GET', '/orders/' + id )
			.then( function ( data ) {
				setOrder( data.order );
				state.view = 'order';
				state.busy = false;
				state.message = null;
				render();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	function setOrder( order ) {
		state.order = order;

		/*
		 * Pick the line automatically when there is only one that still owes
		 * units. On most orders there is, and making somebody tap a line
		 * before every scan is a tap forty times a day for nothing.
		 */
		var owing = ( order.lines || [] ).filter( function ( line ) {
			return line.serialised && line.serials.length < line.required;
		} );

		if ( owing.length === 1 ) {
			state.lineId = owing[0].order_item_id;
		} else if ( ! owing.some( function ( line ) {
			return line.order_item_id === state.lineId;
		} ) ) {
			state.lineId = 0;
		}
	}

	function scan( serial ) {
		if ( ! serial || ! state.lineId ) {
			return;
		}

		state.busy = true;
		render();

		request( 'POST', '/orders/' + state.order.id + '/serials', {
			serial: serial,
			order_item_id: state.lineId
		} )
			.then( function ( data ) {
				setOrder( data.order );
				state.busy = false;
				state.message = data.warning
					? { tone: 'warning', text: data.warning }
					: { tone: 'success', text: data.serial };
				render();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	function unassign( serial ) {
		if ( ! window.confirm( fill( t.removeConfirm, '%s', serial ) ) ) {
			return;
		}

		state.busy = true;
		render();

		request( 'DELETE', '/orders/' + state.order.id + '/serials/' + encodeURIComponent( serial ) )
			.then( function ( data ) {
				setOrder( data.order );
				state.busy = false;
				state.message = null;
				render();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	function ship( carrier, tracking ) {
		state.busy = true;
		state.message = { tone: 'info', text: t.shipping };
		render();

		request( 'POST', '/orders/' + state.order.id + '/shipment', {
			carrier: carrier,
			tracking: tracking
		} )
			.then( function () {
				state.busy = false;
				state.message = { tone: 'success', text: t.shipped };
				loadQueue();
			} )
			.catch( function ( error ) {
				state.busy = false;
				handleError( error );
			} );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/*
	 * Put a value into a translated string.
	 *
	 * Written with a function rather than `String.replace( '%s', value )`,
	 * because the replacement string of `replace()` reads `$&`, `$'` and a
	 * backtick form as instructions. A product called "Moteur $& 250W" would
	 * come back mangled — and the product name is the one thing here that
	 * comes from outside, so it is the one place where it matters.
	 */
	function fill( template, token, value ) {
		return String( template ).replace( token, function () {
			return value;
		} );
	}

	function element( tag, className, text ) {
		var node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		if ( text !== undefined && text !== null ) {
			// textContent throughout, never innerHTML: every string below
			// passes through a serial number, a product name or a customer
			// name, and all three come from outside this file.
			node.textContent = text;
		}

		return node;
	}

	function render() {
		root.textContent = '';
		root.appendChild( renderHeader() );

		if ( state.message ) {
			root.appendChild( renderMessage() );
		}

		if ( state.view === 'signIn' ) {
			root.appendChild( renderSignIn() );
		} else if ( state.view === 'queue' ) {
			root.appendChild( renderQueue() );
		} else if ( state.view === 'order' ) {
			root.appendChild( renderOrder() );
		}

		focusScan();
	}

	function renderHeader() {
		var header = element( 'header', 'dahu-packing__header' );

		header.appendChild( element( 'span', 'dahu-packing__shop', config.shop ) );

		if ( state.token ) {
			var right = element( 'div', 'dahu-packing__header-actions' );
			right.appendChild( element( 'span', 'dahu-packing__station', state.stationLabel ) );

			var out = element( 'button', 'dahu-packing__button dahu-packing__button--quiet', t.signOut );
			out.type = 'button';
			out.addEventListener( 'click', function () {
				signOut( null );
			} );
			right.appendChild( out );

			header.appendChild( right );
		}

		return header;
	}

	function renderMessage() {
		var box = element( 'div', 'dahu-packing__message dahu-packing__message--' + state.message.tone );
		box.appendChild( element( 'span', 'dahu-packing__message-text', state.message.text ) );

		var close = element( 'button', 'dahu-packing__message-close', '×' );
		close.type = 'button';
		close.setAttribute( 'aria-label', t.dismiss );
		close.addEventListener( 'click', function () {
			state.message = null;
			render();
		} );

		box.appendChild( close );

		return box;
	}

	function renderSignIn() {
		var form = element( 'form', 'dahu-packing__panel dahu-packing__signin' );

		var loginLabel = element( 'label', 'dahu-packing__label', t.login );
		var login = element( 'input', 'dahu-packing__field' );
		login.type = 'text';
		login.autocomplete = 'username';
		login.required = true;
		loginLabel.appendChild( login );

		var passLabel = element( 'label', 'dahu-packing__label', t.password );
		var pass = element( 'input', 'dahu-packing__field' );
		pass.type = 'password';
		pass.autocomplete = 'current-password';
		pass.required = true;
		passLabel.appendChild( pass );

		var submit = element( 'button', 'dahu-packing__button', state.busy ? t.working : t.signIn );
		submit.type = 'submit';
		submit.disabled = state.busy;

		form.appendChild( loginLabel );
		form.appendChild( passLabel );
		form.appendChild( submit );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			signIn( login.value, pass.value );
		} );

		return form;
	}

	function renderQueue() {
		var panel = element( 'section', 'dahu-packing__panel' );

		var bar = element( 'div', 'dahu-packing__bar' );
		bar.appendChild( element( 'h1', 'dahu-packing__title', t.queue ) );

		var searchForm = element( 'form', 'dahu-packing__search' );
		var search = element( 'input', 'dahu-packing__field' );
		search.type = 'search';
		search.placeholder = t.search;
		searchForm.appendChild( search );

		var go = element( 'button', 'dahu-packing__button dahu-packing__button--quiet', t.refresh );
		go.type = 'submit';
		searchForm.appendChild( go );

		searchForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			loadQueue( search.value );
		} );

		bar.appendChild( searchForm );
		panel.appendChild( bar );

		if ( ! state.queue.length ) {
			panel.appendChild( element( 'p', 'dahu-packing__empty', state.busy ? t.working : t.nothingToPack ) );

			return panel;
		}

		var list = element( 'ul', 'dahu-packing__queue' );

		state.queue.forEach( function ( order ) {
			var item = element( 'li', 'dahu-packing__queue-item' );
			var button = element( 'button', 'dahu-packing__order' );
			button.type = 'button';

			button.appendChild( element( 'span', 'dahu-packing__order-number', '#' + order.number ) );
			button.appendChild( element( 'span', 'dahu-packing__order-customer', order.customer ) );

			var badge = order.serials_ready
				? element( 'span', 'dahu-packing__badge dahu-packing__badge--ready', t.ready )
				: element( 'span', 'dahu-packing__badge', fill( t.owed, '%d', order.serials_owed ) );

			button.appendChild( badge );

			button.addEventListener( 'click', function () {
				openOrder( order.id );
			} );

			item.appendChild( button );
			list.appendChild( item );
		} );

		panel.appendChild( list );

		return panel;
	}

	function renderOrder() {
		var panel = element( 'section', 'dahu-packing__panel' );
		var order = state.order;

		var bar = element( 'div', 'dahu-packing__bar' );

		var back = element( 'button', 'dahu-packing__button dahu-packing__button--quiet', t.back );
		back.type = 'button';
		back.addEventListener( 'click', function () {
			loadQueue();
		} );

		bar.appendChild( back );
		// The dash only when there is a name after it. A guest order with no
		// billing name showed "#6226 — " on the bench, which reads as a name
		// that failed to load rather than as an order that has none.
		var heading = order.customer ? '#' + order.number + ' — ' + order.customer : '#' + order.number;
		bar.appendChild( element( 'h1', 'dahu-packing__title', heading ) );
		panel.appendChild( bar );

		( order.lines || [] ).forEach( function ( line ) {
			panel.appendChild( renderLine( line ) );
		} );

		if ( order.serials_ready ) {
			panel.appendChild( renderShip() );
		} else {
			panel.appendChild( renderScan() );
		}

		return panel;
	}

	function renderLine( line ) {
		var block = element( 'div', 'dahu-packing__line' );
		var head = element( 'div', 'dahu-packing__line-head' );

		head.appendChild( element( 'span', 'dahu-packing__line-name', line.name ) );

		if ( ! line.serialised ) {
			head.appendChild( element( 'span', 'dahu-packing__badge', t.noSerialNeeded ) );
			block.appendChild( head );

			return block;
		}

		var count = fill( fill( t.ofQuantity, '%1$d', line.serials.length ), '%2$d', line.required );
		head.appendChild( element( 'span', 'dahu-packing__badge' + ( line.serials.length >= line.required ? ' dahu-packing__badge--ready' : '' ), count ) );

		if ( line.serials.length < line.required ) {
			var pick = element( 'button', 'dahu-packing__button dahu-packing__button--quiet', t.pickLine );
			pick.type = 'button';
			pick.setAttribute( 'aria-pressed', String( state.lineId === line.order_item_id ) );

			if ( state.lineId === line.order_item_id ) {
				pick.className += ' is-selected';
			}

			pick.addEventListener( 'click', function () {
				state.lineId = line.order_item_id;
				render();
			} );

			head.appendChild( pick );
		}

		block.appendChild( head );

		if ( line.serials.length ) {
			var list = element( 'ul', 'dahu-packing__serials' );

			line.serials.forEach( function ( entry ) {
				var item = element( 'li', 'dahu-packing__serial' );
				item.appendChild( element( 'code', 'dahu-packing__serial-value', entry.serial ) );

				var remove = element( 'button', 'dahu-packing__remove', t.remove );
				remove.type = 'button';
				remove.addEventListener( 'click', function () {
					unassign( entry.serial );
				} );

				item.appendChild( remove );
				list.appendChild( item );
			} );

			block.appendChild( list );
		}

		return block;
	}

	function renderScan() {
		var form = element( 'form', 'dahu-packing__scanbox' );

		if ( ! state.lineId ) {
			form.appendChild( element( 'p', 'dahu-packing__empty', t.chooseLine ) );

			return form;
		}

		var line = ( state.order.lines || [] ).filter( function ( candidate ) {
			return candidate.order_item_id === state.lineId;
		} )[0];

		form.appendChild( element( 'p', 'dahu-packing__scanning', fill( t.scanInto, '%s', line ? line.name : '' ) ) );

		var field = element( 'input', 'dahu-packing__field dahu-packing__field--scan js-scan' );
		field.type = 'text';
		field.placeholder = t.scan;
		field.autocomplete = 'off';
		field.autocapitalize = 'characters';
		field.spellcheck = false;
		field.disabled = state.busy;

		form.appendChild( field );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var value = field.value;
			/*
			 * Cleared before the request rather than after it. A barcode
			 * reader types the next code the moment the next label is under
			 * it, and a field still holding the last one would concatenate
			 * the two into a serial that belongs to nobody.
			 */
			field.value = '';
			scan( value );
		} );

		return form;
	}

	function renderShip() {
		var form = element( 'form', 'dahu-packing__shipbox' );

		form.appendChild( element( 'p', 'dahu-packing__ready', t.allScanned ) );

		var carrierLabel = element( 'label', 'dahu-packing__label', t.carrier );
		var carrier = element( 'input', 'dahu-packing__field' );
		carrier.type = 'text';
		carrier.autocomplete = 'off';
		carrier.value = state.order.carrier || '';
		carrierLabel.appendChild( carrier );

		var trackingLabel = element( 'label', 'dahu-packing__label', t.tracking );
		var tracking = element( 'input', 'dahu-packing__field' );
		tracking.type = 'text';
		tracking.autocomplete = 'off';
		tracking.value = state.order.tracking || '';
		trackingLabel.appendChild( tracking );

		var submit = element( 'button', 'dahu-packing__button dahu-packing__button--primary', state.busy ? t.working : t.ship );
		submit.type = 'submit';
		submit.disabled = state.busy;

		form.appendChild( carrierLabel );
		form.appendChild( trackingLabel );
		form.appendChild( submit );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			ship( carrier.value, tracking.value );
		} );

		return form;
	}

	function focusScan() {
		var field = root.querySelector( '.js-scan' );

		if ( field && ! state.busy ) {
			field.focus();
		}
	}

	/* ---------------------------------------------------------------------
	 * Start
	 * ------------------------------------------------------------------ */

	state.token = readToken();

	if ( state.token ) {
		loadQueue();
	} else {
		render();
	}
}() );
