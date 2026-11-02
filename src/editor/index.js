/**
 * SwiftForms editor script.
 *
 * Adds three things to the blocks that the block builder registers (the
 * builder's own editor bundle is not touched):
 *
 * 1. A "Preview confirmation" toggle in the toolbar of the Form block. The state
 *    lives in a small data store and is never saved. It puts a class on the form's
 *    wrapper; the editor stylesheet hides the Confirmation block without it.
 * 2. A notice in the Form block's inspector when the post is not published
 *    (previews of unpublished content cannot receive submissions).
 * 3. The field-name repair of field-name.js on the selected field block.
 */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { BlockControls, InspectorControls } from '@wordpress/block-editor';
import { Notice, PanelBody, ToolbarButton } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { STORE_NAME, registerEditorStore } from './store';
import { repairName } from './field-name';

const FORM_BLOCK = 'swiftforms/form';
const FIELD_PREFIX = 'swiftforms/field-';
const PREVIEW_CLASS = 'swiftforms-is-previewing-confirmation';

// Post types that have no publish status a visitor cares about.
const STATUSLESS_TYPES = [
	'wp_template',
	'wp_template_part',
	'wp_block',
	'wp_navigation',
];

registerEditorStore();

/**
 * The toolbar toggle.
 *
 * @param {Object} props          Props.
 * @param {string} props.clientId Form block client ID.
 * @return {Element} Toolbar button.
 */
function PreviewConfirmationToggle( { clientId } ) {
	const isOn = useSelect(
		( select ) => select( STORE_NAME ).isPreviewingConfirmation( clientId ),
		[ clientId ]
	);
	const { toggleConfirmationPreview } = useDispatch( STORE_NAME );

	return (
		<BlockControls group="other">
			<ToolbarButton
				isPressed={ isOn }
				onClick={ () => toggleConfirmationPreview( clientId ) }
			>
				{ __( 'Preview confirmation', 'swiftforms' ) }
			</ToolbarButton>
		</BlockControls>
	);
}

/**
 * Notice for content that is not published.
 *
 * @return {Element|null} Inspector notice or null.
 */
function UnpublishedNotice() {
	const { status, postType } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			status: editor?.getEditedPostAttribute?.( 'status' ),
			postType: editor?.getCurrentPostType?.(),
		};
	}, [] );

	if (
		'string' !== typeof status ||
		'' === status ||
		'publish' === status ||
		STATUSLESS_TYPES.includes( postType )
	) {
		return null;
	}

	return (
		<InspectorControls>
			<PanelBody>
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"Previews of unpublished content can't receive submissions.",
						'swiftforms'
					) }
				</Notice>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Repairs the name of the selected field block once the author stops typing.
 *
 * @param {Object}                  props               Block edit props.
 * @param {Object}                  props.attributes    Block attributes.
 * @param {(attrs: Object) => void} props.setAttributes Attribute setter.
 * @param {boolean}                 props.isSelected    Whether the block is selected.
 * @return {null} Renders nothing.
 */
function FieldNameRepair( { attributes, setAttributes, isSelected } ) {
	const name = attributes.name;
	useEffect( () => {
		if ( ! isSelected ) {
			return undefined;
		}
		const repaired = repairName( name );
		if ( repaired === name ) {
			return undefined;
		}
		const timer = setTimeout(
			() => setAttributes( { name: repaired } ),
			600
		);
		return () => clearTimeout( timer );
	}, [ name, isSelected, setAttributes ] );
	return null;
}

addFilter(
	'editor.BlockEdit',
	'swiftforms/block-edit',
	createHigherOrderComponent(
		( BlockEdit ) => ( props ) => {
			if ( FORM_BLOCK === props.name ) {
				return (
					<>
						{ props.isSelected && <UnpublishedNotice /> }
						<BlockEdit { ...props } />
						{ props.isSelected && (
							<PreviewConfirmationToggle
								clientId={ props.clientId }
							/>
						) }
					</>
				);
			}
			if (
				'string' === typeof props.name &&
				props.name.startsWith( FIELD_PREFIX )
			) {
				return (
					<>
						<BlockEdit { ...props } />
						<FieldNameRepair { ...props } />
					</>
				);
			}
			return <BlockEdit { ...props } />;
		},
		'withSwiftFormsControls'
	)
);

addFilter(
	'editor.BlockListBlock',
	'swiftforms/block-list-block',
	createHigherOrderComponent(
		( BlockListBlock ) => ( props ) => {
			const isOn = useSelect(
				( select ) =>
					FORM_BLOCK === props.name &&
					select( STORE_NAME ).isPreviewingConfirmation(
						props.clientId
					),
				[ props.name, props.clientId ]
			);
			if ( ! isOn ) {
				return <BlockListBlock { ...props } />;
			}
			return (
				<BlockListBlock
					{ ...props }
					className={ [ props.className, PREVIEW_CLASS ]
						.filter( Boolean )
						.join( ' ' ) }
				/>
			);
		},
		'withSwiftFormsPreviewClass'
	)
);
