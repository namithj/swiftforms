const mockRegisterBlockType = jest.fn();
const mockUseBlockProps = jest.fn( ( props = {} ) => props );
const mockUseSelect = jest.fn();

mockUseBlockProps.save = jest.fn( ( props = {} ) => props );

jest.mock( '@wordpress/blocks', () => ( {
    registerBlockType: mockRegisterBlockType,
} ), { virtual: true } );

jest.mock( '@wordpress/block-editor', () => {
    const RichText = () => null;
    RichText.Content = () => null;

    const InnerBlocks = () => null;
    InnerBlocks.Content = () => null;

    return {
        InnerBlocks,
        InspectorControls: ( { children } ) => children,
        RichText,
        useBlockProps: mockUseBlockProps,
    };
}, { virtual: true } );

jest.mock( '@wordpress/data', () => ( {
    useSelect: mockUseSelect,
} ), { virtual: true } );

jest.mock( '@wordpress/components', () => ( {
    Notice: ( { children } ) => children,
    PanelBody: ( { children } ) => children,
    SelectControl: () => null,
} ), { virtual: true } );

describe( 'swiftforms/form block', () => {
    let settings;

    beforeAll( () => {
        require( '../form/index' );
        settings = mockRegisterBlockType.mock.calls.find( ( [ name ] ) => name === 'swiftforms/form' )[ 1 ];
    } );

    beforeEach( () => {
        mockUseBlockProps.mockClear();
        mockUseBlockProps.save.mockClear();
        mockUseSelect.mockReset();
        mockUseSelect.mockReturnValue( [
            {
                id: 25,
                title: {
                    raw: 'Contact us',
                },
            },
        ] );
    } );

    test( 'shows the saved form selector state instead of inner blocks', () => {
        settings.edit( {
            attributes: {
                formId: 25,
            },
            setAttributes: jest.fn(),
        } );

        expect( mockUseBlockProps.mock.calls[ 0 ][ 0 ] ).toEqual( { className: 'swiftforms-form-editor' } );

        const editElement = settings.edit( {
            attributes: {
                formId: 25,
            },
            setAttributes: jest.fn(),
        } );

        const descriptionElement = editElement.props.children.find(
            ( child ) => child?.props?.className === 'swiftforms-form-editor__description'
        );

        expect( descriptionElement.props.children ).toContain( 'Embedding Contact us. Manage fields and allowed content in the Forms post type.' );

        const previewElement = editElement.props.children.find(
            ( child ) => child?.props?.className === 'swiftforms-form-editor__preview'
        );

        expect( previewElement.props.children ).toContain( 'This block renders the selected form post on the frontend. Edit fields, notifications, captcha, and submit messaging on the form itself.' );
    } );

    test( 'saves dynamically and keeps legacy markup as a deprecation fallback', () => {
        expect( settings.save() ).toBeNull();
        expect( settings.deprecated ).toHaveLength( 1 );
    } );
} );