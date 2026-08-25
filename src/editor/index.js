import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import './style.scss';

const DEFAULT_STYLES = {
	'--link-page-background-color': '#121212',
	'--link-page-text-color': '#e5e5e5',
	'--link-page-link-text-color': '#ffffff',
	'--link-page-button-bg-color': '#0b5394',
	'--link-page-button-hover-bg-color': '#53940b',
	'--link-page-background-type': 'color',
	'--link-page-background-gradient-start': '#0b5394',
	'--link-page-background-gradient-end': '#53940b',
	'--link-page-background-gradient-direction': 'to right',
	'--link-page-title-font-size': '2.1em',
	'--link-page-button-radius': '8px',
	'--link-page-profile-img-size': '30%',
	overlay: '1',
};

const text = ( value, fallback = '' ) =>
	typeof value === 'string' ? value : fallback;
const tempId = ( type ) =>
	`temp-${ type }-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2, 8 ) }`;
const storageKey = ( adapter, id ) =>
	`ec-link-page-editor:dirty:${ adapter }:${ id }`;

export const normalizeDocument = ( value = {} ) => {
	const page = value.link_page || value.page || {};
	const identity = value.identity || value.subject || {};
	let sections = page.link_sections || page.links || [];
	if ( sections.length && ! Array.isArray( sections[ 0 ]?.links ) ) {
		sections = [ { id: '', section_title: '', links: sections } ];
	}
	return {
		identity: {
			id: identity.id || identity.term_id || 0,
			name: text( identity.name || identity.title ),
			slug: text( identity.slug ),
			imageId: identity.image_id || identity.profile_image_id || 0,
			imageUrl: text(
				identity.image_url ||
					identity.profile_image_url ||
					identity.snapshot?.image_url
			),
		},
		page: {
			id: page.link_page_id || page.id || 0,
			bio: text( page.bio || identity.snapshot?.description ),
			links: Array.isArray( sections ) ? sections : [],
			styles: {
				...DEFAULT_STYLES,
				...( page.css_vars || page.styles || {} ),
			},
			settings: page.settings || {},
			backgroundImageId: page.background_image_id || 0,
			backgroundImageUrl: text( page.background_image_url ),
			publicUrl: text( page.public_url || identity.public_url ),
		},
		socials: Array.isArray( value.socials )
			? value.socials
			: identity.snapshot?.social_links || [],
	};
};

const Field = ( { label, children, help } ) => (
	<label className="ec-lpe-field">
		<span>{ label }</span>
		{ children }
		{ help && <small>{ help }</small> }
	</label>
);

function LinksPanel( { draft, change } ) {
	const updateSection = ( index, patch ) => {
		const links = [ ...draft.page.links ];
		links[ index ] = { ...links[ index ], ...patch };
		change( { page: { ...draft.page, links } } );
	};
	const move = ( index, direction ) => {
		const destination = index + direction;
		if ( destination < 0 || destination >= draft.page.links.length ) return;
		const links = [ ...draft.page.links ];
		[ links[ index ], links[ destination ] ] = [
			links[ destination ],
			links[ index ],
		];
		change( { page: { ...draft.page, links } } );
	};
	return (
		<div className="ec-tab ec-tab--links">
			{ draft.page.links.map( ( section, sectionIndex ) => (
				<fieldset
					className="ec-lpe-section"
					key={ section.id || sectionIndex }
				>
					<div className="ec-lpe-section__header">
						<input
							aria-label="Section title"
							value={ section.section_title || '' }
							placeholder="Section title (optional)"
							onChange={ ( event ) =>
								updateSection( sectionIndex, {
									section_title: event.target.value,
								} )
							}
						/>
						<button
							type="button"
							aria-label="Move section up"
							onClick={ () => move( sectionIndex, -1 ) }
						>
							Up
						</button>
						<button
							type="button"
							aria-label="Move section down"
							onClick={ () => move( sectionIndex, 1 ) }
						>
							Down
						</button>
						<button
							type="button"
							aria-label="Remove section"
							onClick={ () =>
								change( {
									page: {
										...draft.page,
										links: draft.page.links.filter(
											( _, index ) =>
												index !== sectionIndex
										),
									},
								} )
							}
						>
							Remove
						</button>
					</div>
					<div className="ec-lpe-section__content">
						{ ( section.links || [] ).map( ( link, linkIndex ) => (
							<div
								className="ec-link-item"
								key={ link.id || linkIndex }
							>
								<div className="ec-link-item__fields">
									<input
										aria-label="Link title"
										required
										value={ link.link_text || '' }
										placeholder="Link title"
										onChange={ ( event ) => {
											const items = [ ...section.links ];
											items[ linkIndex ] = {
												...link,
												link_text: event.target.value,
											};
											updateSection( sectionIndex, {
												links: items,
											} );
										} }
									/>
									<input
										aria-label="Link URL"
										type="url"
										required
										value={ link.link_url || '' }
										placeholder="https://..."
										onChange={ ( event ) => {
											const items = [ ...section.links ];
											items[ linkIndex ] = {
												...link,
												link_url: event.target.value,
											};
											updateSection( sectionIndex, {
												links: items,
											} );
										} }
									/>
									{ draft.page.settings
										.link_expiration_enabled && (
										<input
											aria-label="Expiration date"
											type="datetime-local"
											value={ link.expires_at || '' }
											onChange={ ( event ) => {
												const items = [
													...section.links,
												];
												items[ linkIndex ] = {
													...link,
													expires_at:
														event.target.value,
												};
												updateSection( sectionIndex, {
													links: items,
												} );
											} }
										/>
									) }
								</div>
								<button
									type="button"
									aria-label="Remove link"
									onClick={ () =>
										updateSection( sectionIndex, {
											links: section.links.filter(
												( _, index ) =>
													index !== linkIndex
											),
										} )
									}
								>
									Remove
								</button>
							</div>
						) ) }
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								updateSection( sectionIndex, {
									links: [
										...( section.links || [] ),
										{
											id: tempId( 'link' ),
											link_text: '',
											link_url: '',
										},
									],
								} )
							}
						>
							Add Link
						</button>
					</div>
				</fieldset>
			) ) }
			<button
				type="button"
				className="button-2"
				onClick={ () =>
					change( {
						page: {
							...draft.page,
							links: [
								...draft.page.links,
								{
									id: tempId( 'section' ),
									section_title: '',
									links: [],
								},
							],
						},
					} )
				}
			>
				Add Section
			</button>
		</div>
	);
}

function CustomizePanel( { draft, change, adapter, identityId, fonts } ) {
	const styles = draft.page.styles;
	const setting = ( key, value ) =>
		change( {
			page: {
				...draft.page,
				settings: { ...draft.page.settings, [ key ]: value },
			},
		} );
	const style = ( key, value ) =>
		change( {
			page: { ...draft.page, styles: { ...styles, [ key ]: value } },
		} );
	const upload = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		if ( ! file || ! adapter.upload ) return;
		const result = await adapter.upload( 'background', identityId, file );
		change( {
			page: {
				...draft.page,
				backgroundImageId: result.attachment_id,
				backgroundImageUrl: result.url,
			},
		} );
	};
	return (
		<div className="ec-tab ec-tab--customize">
			{ fonts.length > 0 && (
				<>
					<Field label="Title Font">
						<select
							value={
								styles[ '--link-page-title-font-family' ] || ''
							}
							onChange={ ( event ) =>
								style(
									'--link-page-title-font-family',
									event.target.value
								)
							}
						>
							{ fonts.map( ( font ) => (
								<option key={ font.value } value={ font.value }>
									{ font.label }
								</option>
							) ) }
						</select>
					</Field>
					<Field label="Body Font">
						<select
							value={
								styles[ '--link-page-body-font-family' ] || ''
							}
							onChange={ ( event ) =>
								style(
									'--link-page-body-font-family',
									event.target.value
								)
							}
						>
							{ fonts.map( ( font ) => (
								<option key={ font.value } value={ font.value }>
									{ font.label }
								</option>
							) ) }
						</select>
					</Field>
				</>
			) }
			<Field label="Background Type">
				<select
					value={ styles[ '--link-page-background-type' ] }
					onChange={ ( event ) =>
						style(
							'--link-page-background-type',
							event.target.value
						)
					}
				>
					<option value="color">Solid Color</option>
					<option value="gradient">Gradient</option>
					<option value="image">Image</option>
				</select>
			</Field>
			{ styles[ '--link-page-background-type' ] === 'color' && (
				<Field label="Background Color">
					<input
						type="color"
						value={ styles[ '--link-page-background-color' ] }
						onChange={ ( event ) =>
							style(
								'--link-page-background-color',
								event.target.value
							)
						}
					/>
				</Field>
			) }
			{ styles[ '--link-page-background-type' ] === 'gradient' && (
				<>
					<Field label="Gradient Start">
						<input
							type="color"
							value={
								styles[
									'--link-page-background-gradient-start'
								]
							}
							onChange={ ( event ) =>
								style(
									'--link-page-background-gradient-start',
									event.target.value
								)
							}
						/>
					</Field>
					<Field label="Gradient End">
						<input
							type="color"
							value={
								styles[ '--link-page-background-gradient-end' ]
							}
							onChange={ ( event ) =>
								style(
									'--link-page-background-gradient-end',
									event.target.value
								)
							}
						/>
					</Field>
				</>
			) }
			{ styles[ '--link-page-background-type' ] === 'image' &&
				adapter.upload && (
					<Field label="Background Image">
						<input
							type="file"
							accept="image/*"
							onChange={ upload }
						/>
					</Field>
				) }
			<Field label="Text Color">
				<input
					type="color"
					value={ styles[ '--link-page-text-color' ] }
					onChange={ ( event ) =>
						style( '--link-page-text-color', event.target.value )
					}
				/>
			</Field>
			<Field label="Button Color">
				<input
					type="color"
					value={ styles[ '--link-page-button-bg-color' ] }
					onChange={ ( event ) =>
						style(
							'--link-page-button-bg-color',
							event.target.value
						)
					}
				/>
			</Field>
			<Field label="Button Radius">
				<input
					type="range"
					min="0"
					max="50"
					value={
						parseInt( styles[ '--link-page-button-radius' ], 10 ) ||
						0
					}
					onChange={ ( event ) =>
						style(
							'--link-page-button-radius',
							`${ event.target.value }px`
						)
					}
				/>
			</Field>
			<label>
				<input
					type="checkbox"
					checked={ styles.overlay !== '0' }
					onChange={ ( event ) =>
						style( 'overlay', event.target.checked ? '1' : '0' )
					}
				/>{ ' ' }
				Overlay
			</label>
			<Field label="Image Shape">
				<select
					value={
						draft.page.settings.profile_image_shape || 'circle'
					}
					onChange={ ( event ) =>
						setting( 'profile_image_shape', event.target.value )
					}
				>
					<option value="circle">Circle</option>
					<option value="square">Square</option>
					<option value="rectangle">Rectangle</option>
				</select>
			</Field>
		</div>
	);
}

function AdvancedPanel( { draft, change } ) {
	const settings = draft.page.settings;
	const set = ( key, value ) =>
		change( {
			page: { ...draft.page, settings: { ...settings, [ key ]: value } },
		} );
	return (
		<div className="ec-tab ec-tab--advanced">
			<label>
				<input
					type="checkbox"
					checked={ !! settings.link_expiration_enabled }
					onChange={ ( event ) =>
						set( 'link_expiration_enabled', event.target.checked )
					}
				/>{ ' ' }
				Enable Link Expiration Dates
			</label>
			<label>
				<input
					type="checkbox"
					checked={ !! settings.redirect_enabled }
					onChange={ ( event ) =>
						set( 'redirect_enabled', event.target.checked )
					}
				/>{ ' ' }
				Enable Temporary Redirect
			</label>
			{ settings.redirect_enabled && (
				<Field label="Redirect URL">
					<input
						type="url"
						value={ settings.redirect_target_url || '' }
						onChange={ ( event ) =>
							set( 'redirect_target_url', event.target.value )
						}
					/>
				</Field>
			) }
			<label>
				<input
					type="checkbox"
					checked={ settings.youtube_embed_enabled === false }
					onChange={ ( event ) =>
						set( 'youtube_embed_enabled', ! event.target.checked )
					}
				/>{ ' ' }
				Disable Inline YouTube Player
			</label>
			<Field label="Meta Pixel ID">
				<input
					value={ settings.meta_pixel_id || '' }
					onChange={ ( event ) =>
						set( 'meta_pixel_id', event.target.value )
					}
				/>
			</Field>
			<Field label="Google Tag ID">
				<input
					value={ settings.google_tag_id || '' }
					onChange={ ( event ) =>
						set( 'google_tag_id', event.target.value )
					}
				/>
			</Field>
		</div>
	);
}

function Preview( { draft } ) {
	const style = { ...draft.page.styles };
	if ( draft.page.backgroundImageUrl ) {
		style.backgroundImage = `url(${ draft.page.backgroundImageUrl })`;
		style.backgroundSize = 'cover';
	}
	return (
		<div className="ec-preview-wrapper">
			<div
				className="extrch-link-page-container extrch-link-page-preview-container"
				style={ style }
			>
				<div
					className={ `extrch-link-page-content-wrapper${
						draft.page.styles.overlay === '0' ? ' no-overlay' : ''
					}` }
				>
					<div className="extrch-link-page-header-content">
						<div
							className={ `extrch-link-page-profile-img shape-${
								draft.page.settings.profile_image_shape ||
								'circle'
							}${ draft.identity.imageUrl ? '' : ' no-image' }` }
						>
							{ draft.identity.imageUrl && (
								<img src={ draft.identity.imageUrl } alt="" />
							) }
						</div>
						<h1 className="extrch-link-page-title">
							{ draft.identity.name }
						</h1>
						{ draft.page.bio && (
							<div className="extrch-link-page-bio">
								{ draft.page.bio }
							</div>
						) }
					</div>
					{ draft.socials.length > 0 && (
						<div className="extrch-link-page-socials">
							{ draft.socials.map( ( item, index ) => (
								<a
									key={ item.id || index }
									href={ item.url || '#' }
									className="extrch-social-icon"
									aria-label={ item.label || item.type }
								>
									<i className={ item.icon_class } />
								</a>
							) ) }
						</div>
					) }
					{ draft.page.links.map( ( section, index ) => (
						<div key={ section.id || index }>
							{ section.section_title && (
								<div className="extrch-link-page-section-title">
									{ section.section_title }
								</div>
							) }
							<div className="extrch-link-page-links">
								{ ( section.links || [] ).map(
									( link, itemIndex ) => (
										<a
											key={ link.id || itemIndex }
											href={ link.link_url || '#' }
											className="extrch-link-page-link"
										>
											<span className="extrch-link-page-link-text">
												{ link.link_text ||
													'Untitled Link' }
											</span>
										</a>
									)
								) }
							</div>
						</div>
					) ) }
					<div className="extrch-link-page-powered">
						Powered by Extra Chill
					</div>
				</div>
			</div>
		</div>
	);
}

export function Editor( { configuration } ) {
	const adapter = window.ecLinkPageEditorAdapters?.[ configuration.adapter ];
	const identities = configuration.identities || [];
	const [ identityId, setIdentityId ] = useState(
		configuration.initialIdentity || identities[ 0 ]?.id
	);
	const [ draft, setDraft ] = useState( null );
	const [ phase, setPhase ] = useState(
		configuration.status === 'not_provisioned' ? 'absent' : 'loading'
	);
	const [ message, setMessage ] = useState( '' );
	const [ qrCode, setQrCode ] = useState( '' );
	const [ dirty, setDirty ] = useState( false );
	const panels = adapter?.panels || [];
	const tabs = [
		'info',
		'links',
		'socials',
		'customize',
		'advanced',
		...panels.map( ( panel ) => panel.id ),
	];
	const [ active, setActive ] = useState( 'info' );
	const current =
		identities.find(
			( item ) => String( item.id ) === String( identityId )
		) || {};

	const accept = ( value ) => {
		const normalized = normalizeDocument( value );
		normalized.identity = {
			...normalized.identity,
			id: identityId,
			name: normalized.identity.name || current.label || current.name,
			imageUrl: normalized.identity.imageUrl || current.imageUrl,
		};
		normalized.page.publicUrl =
			normalized.page.publicUrl || current.publicUrl || '';
		setDraft( normalized );
		setDirty( false );
		setPhase( 'ready' );
		sessionStorage.removeItem(
			storageKey( configuration.adapter, identityId )
		);
	};
	const load = async () => {
		if ( ! adapter?.read || ! identityId ) {
			setPhase( 'unavailable' );
			return;
		}
		setPhase( 'loading' );
		setMessage( '' );
		try {
			const saved = sessionStorage.getItem(
				storageKey( configuration.adapter, identityId )
			);
			if (
				saved &&
				window.confirm(
					'You have unsaved Link Page changes. Restore them?'
				)
			) {
				setDraft( JSON.parse( saved ) );
				setDirty( true );
				setPhase( 'ready' );
				return;
			}
			accept( await adapter.read( identityId ) );
		} catch ( error ) {
			if ( error?.data?.status === 404 ) setPhase( 'absent' );
			else {
				setMessage(
					error?.message || 'Link Page management could not load.'
				);
				setPhase( 'error' );
			}
		}
	};
	useEffect( () => {
		load();
	}, [ identityId ] );
	useEffect( () => {
		const warn = ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );
	const change = ( patch ) =>
		setDraft( ( value ) => {
			const next = { ...value, ...patch };
			setDirty( true );
			sessionStorage.setItem(
				storageKey( configuration.adapter, identityId ),
				JSON.stringify( next )
			);
			adapter?.onDirtyChange?.( true );
			return next;
		} );
	const save = async () => {
		setPhase( 'saving' );
		setMessage( '' );
		try {
			accept( await adapter.save( identityId, draft ) );
			setMessage( 'Saved!' );
			adapter?.onDirtyChange?.( false );
		} catch ( error ) {
			setMessage( error?.message || 'Changes could not be saved.' );
			setPhase( 'error' );
		}
	};
	const provision = async () => {
		if ( ! adapter?.provision ) return;
		setPhase( 'saving' );
		try {
			accept( await adapter.provision( identityId ) );
			setMessage( 'Link Page created.' );
		} catch ( error ) {
			setMessage( error?.message || 'Link Page could not be created.' );
			setPhase( 'error' );
		}
	};
	if ( ! adapter )
		return (
			<div className="notice notice-warning">
				<p>Link Page editor runtime is unavailable.</p>
			</div>
		);
	if ( phase === 'loading' )
		return (
			<div className="ec-editor-loading" role="status">
				Loading editor...
			</div>
		);
	if ( phase === 'absent' )
		return (
			<div className="ec-lpe-empty">
				<p>This identity does not have a Link Page yet.</p>
				{ adapter.provision && (
					<button className="button-1" onClick={ provision }>
						Create Link Page
					</button>
				) }
			</div>
		);
	if ( phase === 'unavailable' )
		return (
			<div className="notice notice-warning">
				<p>Link Page management is unavailable.</p>
			</div>
		);
	if ( phase === 'error' )
		return (
			<div className="notice notice-error" role="alert">
				<p>{ message }</p>
				<button className="button-2" onClick={ load }>
					Retry
				</button>
			</div>
		);
	if ( ! draft ) return null;
	return (
		<div className="ec-editor">
			<header className="ec-editor__header">
				<div>
					{ draft.page.publicUrl && (
						<>
							<a
								href={ draft.page.publicUrl }
								target="_blank"
								rel="noreferrer"
							>
								{ draft.page.publicUrl }
							</a>
							{ adapter.qrCode && (
								<button
									type="button"
									className="button-2 button-small"
									onClick={ async () =>
										setQrCode(
											await adapter.qrCode(
												draft.page.publicUrl,
												300
											)
										)
									}
								>
									QR Code
								</button>
							) }
						</>
					) }
				</div>
				<div className="ec-editor__actions">
					{ identities.length > 1 && (
						<select
							aria-label="Link Page identity"
							value={ identityId }
							onChange={ ( event ) => {
								if (
									! dirty ||
									window.confirm(
										'Discard unsaved changes and switch?'
									)
								)
									setIdentityId( event.target.value );
							} }
						>
							{ identities.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ item.label || item.name }
								</option>
							) ) }
						</select>
					) }
					{ message && <span role="status">{ message }</span> }
					<button
						className="button-1"
						disabled={ phase === 'saving' || ! dirty }
						onClick={ save }
					>
						{ phase === 'saving'
							? 'Saving...'
							: dirty
							? 'Save changes'
							: 'Saved' }
					</button>
				</div>
			</header>
			<div className="ec-editor__body">
				<div className="ec-editor__sidebar">
					<nav className="ec-lpe-tabs" aria-label="Editor sections">
						{ tabs.map( ( tab ) => (
							<button
								type="button"
								key={ tab }
								className={ active === tab ? 'is-active' : '' }
								onClick={ () => setActive( tab ) }
							>
								{ panels.find( ( item ) => item.id === tab )
									?.label ||
									tab[ 0 ].toUpperCase() + tab.slice( 1 ) }
							</button>
						) ) }
					</nav>
					<div className="ec-lpe-panel">
						{ active === 'info' && (
							<div className="ec-tab">
								<Field label="Display Name">
									<input
										value={ draft.identity.name }
										onChange={ ( event ) =>
											change( {
												identity: {
													...draft.identity,
													name: event.target.value,
												},
											} )
										}
									/>
								</Field>
								<Field label="Link Page Bio">
									<textarea
										rows="4"
										value={ draft.page.bio }
										onChange={ ( event ) =>
											change( {
												page: {
													...draft.page,
													bio: event.target.value,
												},
											} )
										}
									/>
								</Field>
								{ adapter.infoPanel?.( {
									draft,
									change,
									identityId,
								} ) }
							</div>
						) }
						{ active === 'links' && (
							<LinksPanel draft={ draft } change={ change } />
						) }
						{ active === 'socials' && (
							<div className="ec-tab">
								<p>
									Social links are managed by the owning
									platform.
								</p>
								{ adapter.socialsPanel?.( {
									draft,
									change,
									identityId,
									configuration,
								} ) }
							</div>
						) }
						{ active === 'customize' && (
							<CustomizePanel
								draft={ draft }
								change={ change }
								adapter={ adapter }
								identityId={ identityId }
								fonts={ configuration.fonts || [] }
							/>
						) }
						{ active === 'advanced' && (
							<AdvancedPanel draft={ draft } change={ change } />
						) }
						{ panels
							.find( ( panel ) => panel.id === active )
							?.render( { draft, change, identityId } ) }
					</div>
				</div>
				<aside className="ec-editor__preview-region">
					<h2>Preview</h2>
					<p>Live preview of your public Link Page.</p>
					<Preview draft={ draft } />
				</aside>
			</div>
			{ qrCode && (
				<div
					className="ec-qr-modal"
					role="dialog"
					aria-modal="true"
					aria-label="Link Page QR Code"
				>
					<div className="ec-qr-modal__content">
						<button type="button" onClick={ () => setQrCode( '' ) }>
							Close
						</button>
						<img src={ qrCode } alt="Link Page QR Code" />
						<a
							className="button-2"
							href={ qrCode }
							download="link-page-qr-code.png"
						>
							Download for Print
						</a>
					</div>
				</div>
			) }
		</div>
	);
}

export const mount = ( target, configuration ) => {
	if ( ! target ) return null;
	const root = createRoot( target );
	root.render( <Editor configuration={ configuration } /> );
	return () => root.unmount();
};

window.ExtraChillLinkPageEditor = { mount, normalizeDocument };
window.ecLinkPageEditorAdapters = window.ecLinkPageEditorAdapters || {};

const boot = () =>
	document
		.querySelectorAll( '[data-link-page-editor-config]' )
		.forEach( ( node ) => {
			if ( node.dataset.mounted ) return;
			const target = document.getElementById(
				node.dataset.linkPageEditorConfig
			);
			if ( target ) {
				node.dataset.mounted = 'true';
				mount( target, JSON.parse( node.textContent ) );
			}
		} );
document.readyState === 'loading'
	? document.addEventListener( 'DOMContentLoaded', boot )
	: boot();
