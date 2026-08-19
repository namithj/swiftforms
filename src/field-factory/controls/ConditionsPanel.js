/**
 * Shared conditional-visibility editor, used by every field block except
 * `swf/field-hidden` (a hidden field is never conditionally shown/hidden —
 * it just always submits its fixed value).
 */

import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	SelectControl,
	Button,
	Card,
	CardBody,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const OPERATORS = [
	{ label: __( 'equals', 'swiftforms' ), value: 'equals' },
	{ label: __( 'does not equal', 'swiftforms' ), value: 'not_equals' },
	{ label: __( 'contains', 'swiftforms' ), value: 'contains' },
	{ label: __( 'is empty', 'swiftforms' ), value: 'empty' },
	{ label: __( 'is not empty', 'swiftforms' ), value: 'not_empty' },
];

const EMPTY_CONDITIONS = { enabled: false, action: 'show', groups: [] };

/**
 * Every other field-block instance in the form, for the rule "field" picker.
 *
 * @param {string} clientId This block's clientId, excluded from the list.
 */
export function useSiblingFields( clientId ) {
	return useSelect(
		( select ) => {
			const { getBlocks } = select( blockEditorStore );

			const flatten = ( blocks ) =>
				blocks.flatMap( ( block ) => [
					block,
					...flatten( block.innerBlocks || [] ),
				] );

			return flatten( getBlocks() )
				.filter(
					( block ) =>
						block.name.startsWith( 'swf/field-' ) &&
						block.name !== 'swf/field-hidden' &&
						block.clientId !== clientId
				)
				.map( ( block ) => ( {
					slug: block.attributes.slug,
					label: block.attributes.label || block.attributes.slug,
				} ) )
				.filter( ( field ) => field.slug );
		},
		[ clientId ]
	);
}

export default function ConditionsPanel( { conditions, onChange, clientId } ) {
	const value = { ...EMPTY_CONDITIONS, ...conditions };
	const siblings = useSiblingFields( clientId );

	const updateGroups = ( groups ) => onChange( { ...value, groups } );

	const updateRule = ( groupIndex, ruleIndex, changes ) => {
		const groups = value.groups.map( ( group, gi ) =>
			gi !== groupIndex
				? group
				: group.map( ( rule, ri ) =>
						ri !== ruleIndex ? rule : { ...rule, ...changes }
				  )
		);
		updateGroups( groups );
	};

	const addRule = ( groupIndex ) => {
		const groups = value.groups.map( ( group, gi ) =>
			gi !== groupIndex
				? group
				: [
						...group,
						{
							field: siblings[ 0 ]?.slug || '',
							operator: 'equals',
							value: '',
						},
				  ]
		);
		updateGroups( groups );
	};

	const removeRule = ( groupIndex, ruleIndex ) => {
		const groups = value.groups
			.map( ( group, gi ) =>
				gi !== groupIndex
					? group
					: group.filter( ( _, ri ) => ri !== ruleIndex )
			)
			.filter( ( group ) => group.length > 0 );
		updateGroups( groups );
	};

	const addGroup = () => {
		updateGroups( [
			...value.groups,
			[
				{
					field: siblings[ 0 ]?.slug || '',
					operator: 'equals',
					value: '',
				},
			],
		] );
	};

	if ( ! siblings.length ) {
		return (
			<PanelBody
				title={ __( 'Conditional Logic', 'swiftforms' ) }
				initialOpen={ false }
			>
				<p>
					{ __(
						'Add another field to this form to set up conditional logic.',
						'swiftforms'
					) }
				</p>
			</PanelBody>
		);
	}

	return (
		<PanelBody
			title={ __( 'Conditional Logic', 'swiftforms' ) }
			initialOpen={ false }
		>
			<ToggleControl
				label={ __( 'Enable conditional logic', 'swiftforms' ) }
				checked={ !! value.enabled }
				onChange={ ( enabled ) => onChange( { ...value, enabled } ) }
			/>

			{ value.enabled && (
				<>
					<SelectControl
						label={ __( 'Action', 'swiftforms' ) }
						value={ value.action }
						options={ [
							{
								label: __(
									'Show this field if…',
									'swiftforms'
								),
								value: 'show',
							},
							{
								label: __(
									'Hide this field if…',
									'swiftforms'
								),
								value: 'hide',
							},
						] }
						onChange={ ( action ) =>
							onChange( { ...value, action } )
						}
					/>

					{ value.groups.map( ( group, groupIndex ) => (
						<Card
							key={ groupIndex }
							size="small"
							style={ { marginBottom: '8px' } }
						>
							<CardBody>
								{ groupIndex > 0 && (
									<p>
										<strong>
											{ __( 'OR', 'swiftforms' ) }
										</strong>
									</p>
								) }
								{ group.map( ( rule, ruleIndex ) => (
									<div
										key={ ruleIndex }
										style={ { marginBottom: '8px' } }
									>
										{ ruleIndex > 0 && (
											<p>{ __( 'AND', 'swiftforms' ) }</p>
										) }
										<SelectControl
											label={ __(
												'Field',
												'swiftforms'
											) }
											value={ rule.field }
											options={ siblings.map(
												( field ) => ( {
													label: field.label,
													value: field.slug,
												} )
											) }
											onChange={ ( field ) =>
												updateRule(
													groupIndex,
													ruleIndex,
													{ field }
												)
											}
										/>
										<SelectControl
											label={ __(
												'Condition',
												'swiftforms'
											) }
											value={ rule.operator }
											options={ OPERATORS }
											onChange={ ( operator ) =>
												updateRule(
													groupIndex,
													ruleIndex,
													{ operator }
												)
											}
										/>
										{ ! [ 'empty', 'not_empty' ].includes(
											rule.operator
										) && (
											<input
												type="text"
												className="components-text-control__input"
												placeholder={ __(
													'Value',
													'swiftforms'
												) }
												value={ rule.value }
												onChange={ ( event ) =>
													updateRule(
														groupIndex,
														ruleIndex,
														{
															value: event.target
																.value,
														}
													)
												}
											/>
										) }
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												removeRule(
													groupIndex,
													ruleIndex
												)
											}
										>
											{ __(
												'Remove rule',
												'swiftforms'
											) }
										</Button>
									</div>
								) ) }
								<Button
									variant="secondary"
									size="small"
									onClick={ () => addRule( groupIndex ) }
								>
									{ __( 'Add AND rule', 'swiftforms' ) }
								</Button>
							</CardBody>
						</Card>
					) ) }

					<Button variant="secondary" onClick={ addGroup }>
						{ __( 'Add OR group', 'swiftforms' ) }
					</Button>
				</>
			) }
		</PanelBody>
	);
}
