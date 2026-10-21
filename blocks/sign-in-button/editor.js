/* Editor UI for the "Sign in with Identvia" block. No build step: plain wp.* globals. */
( function ( blocks, element, blockEditor, components, i18n, serverSideRender ) {
	var el = element.createElement;
	var __ = i18n.__;
	var opt = function ( v, l ) {
		return { value: v, label: l };
	};
	blocks.registerBlockType( 'identvia-login/sign-in-button', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( k ) {
				return function ( v ) {
					var o = {};
					o[ k ] = v;
					props.setAttributes( o );
				};
			};
			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Button', 'identvia-login' ) },
						el( components.SelectControl, { label: __( 'Text', 'identvia-login' ), value: a.text, onChange: set( 'text' ), options: [ opt( 'signin', __( 'Sign in with Identvia', 'identvia-login' ) ), opt( 'continue', __( 'Continue with Identvia', 'identvia-login' ) ), opt( 'signup', __( 'Sign up with Identvia', 'identvia-login' ) ) ] } ),
						el( components.SelectControl, { label: __( 'Style', 'identvia-login' ), value: a.theme, onChange: set( 'theme' ), options: [ opt( 'light', __( 'Light', 'identvia-login' ) ), opt( 'dark', __( 'Dark', 'identvia-login' ) ), opt( 'brand', __( 'Brand', 'identvia-login' ) ), opt( 'auto', __( 'Match system', 'identvia-login' ) ) ] } ),
						el( components.SelectControl, { label: __( 'Size', 'identvia-login' ), value: a.size, onChange: set( 'size' ), options: [ opt( 'small', __( 'Small', 'identvia-login' ) ), opt( 'medium', __( 'Medium', 'identvia-login' ) ), opt( 'large', __( 'Large', 'identvia-login' ) ) ] } ),
						el( components.SelectControl, { label: __( 'Shape', 'identvia-login' ), value: a.shape, onChange: set( 'shape' ), options: [ opt( 'rect', __( 'Square', 'identvia-login' ) ), opt( 'rounded', __( 'Rounded', 'identvia-login' ) ), opt( 'pill', __( 'Pill', 'identvia-login' ) ) ] } ),
						el( components.TextControl, { label: __( 'After sign-in, go to (local path)', 'identvia-login' ), value: a.redirect, onChange: set( 'redirect' ), placeholder: '/members' } )
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( 'span', { className: 'identvia-btn', 'data-theme': a.theme, 'data-size': a.size, 'data-shape': a.shape }, el( 'span', { className: 'idv-label' }, { signin: __( 'Sign in with Identvia', 'identvia-login' ), 'continue': __( 'Continue with Identvia', 'identvia-login' ), signup: __( 'Sign up with Identvia', 'identvia-login' ) }[ a.text ] ) )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender );
