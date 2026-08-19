/**
 * Builds the `edit` component shared by every `smartlogix-swiftforms/field-*` block.
 *
 * A field block's index.js is ~10 lines: import its block.json, describe
 * what makes it different (a type label, default slug, a canvas preview,
 * and optionally extra inspector controls), and call registerFieldBlock().
 * Everything else — the label/slug/help/required controls, duplicate-slug
 * warning, and the conditional logic panel — lives here exactly once.
 */

import {
	InspectorControls,
	useBlockProps,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { maybeDeriveSlug, slugify } from '../shared/slug';
import ConditionsPanel from './controls/ConditionsPanel';

/**
 * Whether another field block in this post already uses the same slug.
 *
 * @param {string} clientId This block's clientId.
 * @param {string} slug     This block's current slug.
 */
function useIsDuplicateSlug( clientId, slug ) {
	return useSelect(
		( select ) => {
			if ( ! slug ) {
				return false;
			}

			const { getBlocks } = select( blockEditorStore );

			const flatten = ( blocks ) =>
				blocks.flatMap( ( block ) => [
					block,
					...flatten( block.innerBlocks || [] ),
				] );

			return flatten( getBlocks() ).some(
				( block ) =>
					block.clientId !== clientId &&
					block.name.startsWith( 'smartlogix-swiftforms/field-' ) &&
					block.attributes.slug === slug
			);
		},
		[ clientId, slug ]
	);
}

/**
 * @param {Object}        options
 * @param {string}        options.typeLabel             Human label, e.g. "Text".
 * @param {string}        options.defaultSlug           This type's default slug attribute value.
 * @param {Function}      options.renderPreview         ( attributes ) => JSX canvas preview.
 * @param {Function|null} [options.renderExtraControls] ( { attributes, setAttributes } ) => JSX extra inspector controls.
 * @param {boolean}       [options.hasLabel]            Whether this type has a label/slug pair (false for hidden).
 * @param {boolean}       [options.hasRequired]         Whether this type has a Required toggle.
 * @param {boolean}       [options.hasHelp]             Whether this type has a help-text control.
 * @param {boolean}       [options.hasConditions]       Whether this type supports conditional visibility.
 */
export function createFieldEdit( {
	typeLabel,
	defaultSlug,
	renderPreview,
	renderExtraControls = null,
	hasLabel = true,
	hasRequired = true,
	hasHelp = true,
	hasConditions = true,
} ) {
	return function FieldEdit( { attributes, setAttributes, clientId } ) {
		const {
			label = '',
			slug = '',
			helpText = '',
			required = false,
			conditions,
		} = attributes;
		const isDuplicateSlug = useIsDuplicateSlug( clientId, slug );
		const blockProps = useBlockProps( { className: 'swf-editor-field' } );

		const onLabelChange = ( nextLabel ) => {
			setAttributes( {
				label: nextLabel,
				slug: maybeDeriveSlug( nextLabel, label, slug, defaultSlug ),
			} );
		};

		const duplicateNotice = isDuplicateSlug ? (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Another field already uses this name — one of them will overwrite the other on submit.',
					'swiftforms'
				) }
			</Notice>
		) : null;

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ sprintf(
							/* translators: %s: field type label, e.g. "Text". */
							__( '%s Field', 'swiftforms' ),
							typeLabel
						) }
						initialOpen
					>
						{ hasLabel && (
							<TextControl
								label={ __( 'Label', 'swiftforms' ) }
								value={ label }
								onChange={ onLabelChange }
							/>
						) }
						<TextControl
							label={ __( 'Field name (slug)', 'swiftforms' ) }
							help={ __(
								'Used as the entry column name and the {field:slug} email placeholder.',
								'swiftforms'
							) }
							value={ slug }
							onChange={ ( value ) =>
								setAttributes( { slug: slugify( value ) } )
							}
						/>
						{ duplicateNotice }
						{ renderExtraControls &&
							renderExtraControls( {
								attributes,
								setAttributes,
							} ) }
						{ hasHelp && (
							<TextareaControl
								label={ __( 'Help text', 'swiftforms' ) }
								value={ helpText }
								onChange={ ( value ) =>
									setAttributes( { helpText: value } )
								}
							/>
						) }
						{ hasRequired && (
							<ToggleControl
								label={ __( 'Required', 'swiftforms' ) }
								checked={ !! required }
								onChange={ ( value ) =>
									setAttributes( { required: value } )
								}
							/>
						) }
					</PanelBody>
					{ hasConditions && (
						<ConditionsPanel
							conditions={ conditions }
							onChange={ ( value ) =>
								setAttributes( { conditions: value } )
							}
							clientId={ clientId }
						/>
					) }
				</InspectorControls>

				<div { ...blockProps }>
					{ hasLabel && (
						<div className="swf-editor-field__label-row">
							<strong>{ label || typeLabel }</strong>
							{ hasRequired && required && (
								<span className="swf-editor-field__required">
									*
								</span>
							) }
							<span className="swf-editor-field__type-badge">
								{ typeLabel }
							</span>
						</div>
					) }
					{ duplicateNotice && (
						<div className="swf-editor-field__duplicate-notice">
							{ duplicateNotice }
						</div>
					) }
					<div className="swf-editor-field__preview">
						{ renderPreview( attributes ) }
					</div>
					{ hasHelp && helpText && (
						<p className="swf-editor-field__help">{ helpText }</p>
					) }
				</div>
			</>
		);
	};
}
