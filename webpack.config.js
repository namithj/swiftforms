const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'fields/checkbox/index': path.resolve(process.cwd(), 'includes/blocks/fields/checkbox/index.js'),
        'fields/email/index': path.resolve(process.cwd(), 'includes/blocks/fields/email/index.js'),
        'fields/file/index': path.resolve(process.cwd(), 'includes/blocks/fields/file/index.js'),
        'fields/number/index': path.resolve(process.cwd(), 'includes/blocks/fields/number/index.js'),
        'fields/select/index': path.resolve(process.cwd(), 'includes/blocks/fields/select/index.js'),
        'fields/tel/index': path.resolve(process.cwd(), 'includes/blocks/fields/tel/index.js'),
        'fields/text/index': path.resolve(process.cwd(), 'includes/blocks/fields/text/index.js'),
        'fields/textarea/index': path.resolve(process.cwd(), 'includes/blocks/fields/textarea/index.js'),
        'fields/url/index': path.resolve(process.cwd(), 'includes/blocks/fields/url/index.js'),
        'form/index': path.resolve(process.cwd(), 'includes/blocks/form/index.js'),
        'form/view': path.resolve(process.cwd(), 'includes/blocks/form/view.js'),
    },
    output: {
        ...defaultConfig.output,
        filename: '[name].js',
        path: path.resolve(process.cwd(), 'dist'),
    },
};