import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Notice,
	ExternalLink,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './editor.scss';
import './style.scss';

function adminUrl( path ) {
	const base =
		window.smartlogixSwiftFormsEditorSettings?.adminUrl || '/wp-admin/';
	return base + path;
}

function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();
	const { formId } = attributes;

	const forms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords(
				'postType',
				'smartlogix_swf_form',
				{
					per_page: -1,
					status: 'publish,draft',
				}
			),
		[]
	);

	const options = [
		{ label: __( '— Select a form —', 'swiftforms' ), value: 0 },
		...( forms || [] ).map( ( form ) => ( {
			label: form.title.rendered || `#${ form.id }`,
			value: form.id,
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Form', 'swiftforms' ) }>
					<SelectControl
						label={ __( 'Saved form', 'swiftforms' ) }
						value={ formId }
						options={ options }
						onChange={ ( value ) =>
							setAttributes( { formId: Number( value ) } )
						}
					/>
					{ !! formId && (
						<p>
							<ExternalLink
								href={ adminUrl(
									`post.php?post=${ formId }&action=edit`
								) }
							>
								{ __( 'Edit fields', 'swiftforms' ) }
							</ExternalLink>
							{ ' · ' }
							<ExternalLink
								href={ adminUrl(
									`post.php?post=${ formId }&action=edit#smartlogix-swiftforms-form-settings`
								) }
							>
								{ __( 'Settings', 'swiftforms' ) }
							</ExternalLink>
							{ ' · ' }
							<ExternalLink
								href={ adminUrl(
									`edit.php?post_type=smartlogix_swf_entry&smartlogix_swf_entry_form=smartlogix-swiftforms-form-${ formId }`
								) }
							>
								{ __( 'Entries', 'swiftforms' ) }
							</ExternalLink>
						</p>
					) }
				</PanelBody>
			</InspectorControls>

			{ ! formId && (
				<Notice status="warning" isDismissible={ false }>
					{ forms && forms.length === 0
						? __(
								'No forms yet. Create one under SwiftForms → Add New, then come back and pick it here.',
								'swiftforms'
						  )
						: __(
								'Choose a form in the block sidebar.',
								'swiftforms'
						  ) }
				</Notice>
			) }

			{ !! formId && (
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			) }
		</div>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => null,
} );
