import { useSelect } from '@wordpress/data';
import { Button, PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const OPERATOR_OPTIONS = [
    { label: __( 'is', 'swiftforms' ), value: 'equals' },
    { label: __( 'is not', 'swiftforms' ), value: 'not_equals' },
    { label: __( 'contains', 'swiftforms' ), value: 'contains' },
    { label: __( 'is empty', 'swiftforms' ), value: 'empty' },
    { label: __( 'is not empty', 'swiftforms' ), value: 'not_empty' },
];

const VALUELESS_OPERATORS = [ 'empty', 'not_empty' ];

const stripMarkup = ( value ) => ( value || '' ).replace( /<[^>]+>/g, '' ).trim();

/**
 * Lists the other SwiftForms field blocks in the editor a condition can reference.
 *
 * @param {string} clientId This block's client id.
 * @return {Array<{slug: string, label: string}>} Sibling fields.
 */
export const useSiblingFields = ( clientId ) =>
    useSelect(
        ( select ) => {
            const { getBlocks } = select( 'core/block-editor' );
            const flatten = ( blocks ) =>
                blocks.flatMap( ( block ) => [ block, ...flatten( block.innerBlocks || [] ) ] );

            return flatten( getBlocks() )
                .filter(
                    ( block ) =>
                        block.clientId !== clientId &&
                        block.name.startsWith( 'swiftforms/' ) &&
                        block.name.endsWith( '-field' ) &&
                        block.attributes?.slug
                )
                .map( ( block ) => ( {
                    slug: block.attributes.slug,
                    label: stripMarkup( block.attributes.label ) || block.attributes.slug,
                } ) );
        },
        [ clientId ]
    );

const normalizeConditions = ( conditions ) => ( {
    enabled: conditions?.enabled === true,
    action: conditions?.action === 'hide' ? 'hide' : 'show',
    groups: Array.isArray( conditions?.groups )
        ? conditions.groups.filter( Array.isArray ).map( ( group ) => group.filter( ( rule ) => rule && typeof rule === 'object' ) )
        : [],
} );

/**
 * Inspector panel for a field block's conditional visibility rules.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.clientId      Field block client id.
 * @param {Object}   props.conditions    Current conditions attribute.
 * @param {Function} props.setAttributes Block setAttributes callback.
 */
export const ConditionsPanel = ( { clientId, conditions, setAttributes } ) => {
    const siblings = useSiblingFields( clientId );
    const current = normalizeConditions( conditions );

    const commit = ( next ) => setAttributes( { conditions: next } );

    const newRule = () => ( { field: siblings[ 0 ]?.slug || '', operator: 'equals', value: '' } );

    const updateRule = ( groupIndex, ruleIndex, patch ) => {
        const groups = current.groups.map( ( group, gi ) =>
            gi === groupIndex ? group.map( ( rule, ri ) => ( ri === ruleIndex ? { ...rule, ...patch } : rule ) ) : group
        );
        commit( { ...current, groups } );
    };

    const removeRule = ( groupIndex, ruleIndex ) => {
        const groups = current.groups
            .map( ( group, gi ) => ( gi === groupIndex ? group.filter( ( _, ri ) => ri !== ruleIndex ) : group ) )
            .filter( ( group ) => group.length > 0 );
        commit( { ...current, groups } );
    };

    const addRule = ( groupIndex ) => {
        const groups = current.groups.map( ( group, gi ) => ( gi === groupIndex ? [ ...group, newRule() ] : group ) );
        commit( { ...current, groups } );
    };

    const addGroup = () => commit( { ...current, groups: [ ...current.groups, [ newRule() ] ] } );

    const fieldOptions = [
        { label: __( 'Select a field…', 'swiftforms' ), value: '' },
        ...siblings.map( ( sibling ) => ( { label: `${ sibling.label } (${ sibling.slug })`, value: sibling.slug } ) ),
    ];

    return (
        <PanelBody title={ __( 'Conditional Logic', 'swiftforms' ) } initialOpen={ false }>
            <ToggleControl
                label={ __( 'Enable conditional logic', 'swiftforms' ) }
                checked={ current.enabled }
                onChange={ ( enabled ) =>
                    commit( { ...current, enabled, groups: enabled && current.groups.length === 0 ? [ [ newRule() ] ] : current.groups } )
                }
            />
            { current.enabled && (
                <>
                    <SelectControl
                        label={ __( 'Action when rules match', 'swiftforms' ) }
                        value={ current.action }
                        options={ [
                            { label: __( 'Show this field', 'swiftforms' ), value: 'show' },
                            { label: __( 'Hide this field', 'swiftforms' ), value: 'hide' },
                        ] }
                        onChange={ ( action ) => commit( { ...current, action } ) }
                    />
                    { current.groups.map( ( group, groupIndex ) => (
                        <div className="swiftforms-conditions__group" key={ groupIndex }>
                            { groupIndex > 0 && <p className="swiftforms-conditions__or">{ __( '— or —', 'swiftforms' ) }</p> }
                            { group.map( ( rule, ruleIndex ) => (
                                <div className="swiftforms-conditions__rule" key={ ruleIndex }>
                                    <SelectControl
                                        label={ __( 'Field', 'swiftforms' ) }
                                        value={ rule.field || '' }
                                        options={ fieldOptions }
                                        onChange={ ( field ) => updateRule( groupIndex, ruleIndex, { field } ) }
                                    />
                                    <SelectControl
                                        label={ __( 'Condition', 'swiftforms' ) }
                                        value={ rule.operator || 'equals' }
                                        options={ OPERATOR_OPTIONS }
                                        onChange={ ( operator ) => updateRule( groupIndex, ruleIndex, { operator } ) }
                                    />
                                    { ! VALUELESS_OPERATORS.includes( rule.operator ) && (
                                        <TextControl
                                            label={ __( 'Value', 'swiftforms' ) }
                                            value={ rule.value || '' }
                                            onChange={ ( value ) => updateRule( groupIndex, ruleIndex, { value } ) }
                                        />
                                    ) }
                                    <Button
                                        variant="link"
                                        isDestructive
                                        onClick={ () => removeRule( groupIndex, ruleIndex ) }
                                    >
                                        { __( 'Remove rule', 'swiftforms' ) }
                                    </Button>
                                </div>
                            ) ) }
                            <Button variant="secondary" onClick={ () => addRule( groupIndex ) }>
                                { __( 'Add rule (and)', 'swiftforms' ) }
                            </Button>
                        </div>
                    ) ) }
                    <p>
                        <Button variant="secondary" onClick={ addGroup }>
                            { __( 'Add "or" group', 'swiftforms' ) }
                        </Button>
                    </p>
                </>
            ) }
        </PanelBody>
    );
};
