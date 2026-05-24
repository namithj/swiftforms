const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'fields/email/index': path.resolve(process.cwd(), 'src/blocks/fields/email/index.js'),
        'fields/file/index': path.resolve(process.cwd(), 'src/blocks/fields/file/index.js'),
        'fields/text/index': path.resolve(process.cwd(), 'src/blocks/fields/text/index.js'),
        'fields/textarea/index': path.resolve(process.cwd(), 'src/blocks/fields/textarea/index.js'),
        'fields/url/index': path.resolve(process.cwd(), 'src/blocks/fields/url/index.js'),
        'form/index': path.resolve(process.cwd(), 'src/blocks/form/index.js'),
        'form/view': path.resolve(process.cwd(), 'src/blocks/form/view.js'),
    },
    output: {
        ...defaultConfig.output,
        filename: '[name].js',
        path: path.resolve(process.cwd(), 'dist'),
    },
};