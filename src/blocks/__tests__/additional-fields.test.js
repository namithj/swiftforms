const mockRegisterBlockType = jest.fn();
const mockUseBlockProps = jest.fn( ( props = {} ) => props );

mockUseBlockProps.save = jest.fn( ( props = {} ) => props );

jest.mock( '@wordpress/blocks', () => ( {
    registerBlockType: mockRegisterBlockType,
} ), { virtual: true } );

jest.mock( '@wordpress/block-editor', () => {
    const RichText = () => null;
    RichText.Content = () => null;

    return {
        InspectorControls: ( { children } ) => children,
        RichText,
        useBlockProps: mockUseBlockProps,
    };
}, { virtual: true } );

jest.mock( '@wordpress/components', () => ( {
    PanelBody: ( { children } ) => children,
    SelectControl: () => null,
    TextControl: () => null,
    ToggleControl: () => null,
} ), { virtual: true } );

describe( 'remaining SwiftForms field blocks', () => {
    beforeAll( () => {
        require( '../fields/number/index' );
        require( '../fields/tel/index' );
        require( '../fields/select/index' );
        require( '../fields/checkbox/index' );
    } );

    beforeEach( () => {
        mockUseBlockProps.mockClear();
        mockUseBlockProps.save.mockClear();
    } );

    test( 'registers the expected remaining field block names', () => {
        expect( mockRegisterBlockType.mock.calls.map( ( [ name ] ) => name ) ).toEqual(
            expect.arrayContaining( [
                'swiftforms/number-field',
                'swiftforms/tel-field',
                'swiftforms/select-field',
                'swiftforms/checkbox-field',
            ] )
        );
    } );

    test( 'serializes runtime metadata for the new field blocks', () => {
        const getSettings = ( blockName ) => mockRegisterBlockType.mock.calls.find( ( [ name ] ) => name === blockName )[ 1 ];

        const numberElement = getSettings( 'swiftforms/number-field' ).save( {
            attributes: { helpText: '', label: 'Guests', max: 10, min: 1, placeholder: '2', required: true, slug: 'guests', step: 1 },
        } );
        const telElement = getSettings( 'swiftforms/tel-field' ).save( {
            attributes: { helpText: '', label: 'Phone', placeholder: '+1 555 555 5555', required: false, slug: 'phone' },
        } );
        const selectElement = getSettings( 'swiftforms/select-field' ).save( {
            attributes: { helpText: '', label: 'Department', options: 'Sales\nSupport', required: true, slug: 'department' },
        } );
        const checkboxElement = getSettings( 'swiftforms/checkbox-field' ).save( {
            attributes: { checkboxLabel: 'I agree', helpText: '', label: 'Consent', required: true, slug: 'consent', value: 'yes' },
        } );

        expect( numberElement.props[ 'data-field-type' ] ).toBe( 'number' );
        expect( telElement.props[ 'data-field-type' ] ).toBe( 'tel' );
        expect( selectElement.props[ 'data-field-type' ] ).toBe( 'select' );
        expect( checkboxElement.props[ 'data-field-type' ] ).toBe( 'checkbox' );
    } );
} );