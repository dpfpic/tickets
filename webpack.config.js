const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')

module.exports = {
	entry: {
		'tickets-main': path.resolve(__dirname, 'src/main.js'),
		'tickets-admin': path.resolve(__dirname, 'src/admin.js'),
	},
	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
	},
	// 'eval' et ses variantes utilisent eval() pour le mapping des sources,
	// ce que la CSP de Nextcloud (script-src sans 'unsafe-eval') bloque.
	devtool: 'source-map',
	resolve: {
		extensions: ['.js', '.vue'],
		alias: { vue$: 'vue/dist/vue.esm.js' },
	},
	module: {
		rules: [
			{ test: /\.vue$/, loader: 'vue-loader' },
			{ test: /\.js$/, exclude: /node_modules/, loader: 'babel-loader' },
			{ test: /\.css$/, use: [MiniCssExtractPlugin.loader, 'css-loader'] },
			{ test: /\.scss$/, use: [MiniCssExtractPlugin.loader, 'css-loader', 'sass-loader'] },
		],
	},
	plugins: [
		new VueLoaderPlugin(),
		// Génère css/tickets-main.css, chargé par templates/main.php via Util::addStyle()
		new MiniCssExtractPlugin({
			filename: path.join('..', 'css', '[name].css'),
		}),
	],
}
