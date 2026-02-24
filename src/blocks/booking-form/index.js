import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from './block.json';

registerBlockType( metadata.name, {
    edit: ( { attributes, setAttributes } ) => {
        const blockProps = useBlockProps();
        const { mode } = attributes;

        return (
            <>
                <InspectorControls>
                    <PanelBody title="Form Settings">
                        <SelectControl
                            label="Display Mode"
                            value={ mode }
                            options={ [
                                { label: 'Auto (from Global Settings)', value: 'auto' },
                                { label: 'Time-Slot Calendar', value: 'calendar' },
                                { label: 'Date-Range Search', value: 'search' },
                            ] }
                            onChange={ ( newMode ) => setAttributes( { mode: newMode } ) }
                            help="Choose which booking interface to display. 'Auto' is recommended."
                        />
                    </PanelBody>
                </InspectorControls>
                <div { ...blockProps }>
                    <ServerSideRender
                        block={ metadata.name }
                        attributes={ attributes }
                    />
                </div>
            </>
        );
    },
    // The save function is null because this is a dynamic block.
    save: () => null,
} );