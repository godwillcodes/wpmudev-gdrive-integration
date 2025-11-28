module.exports = function (grunt) {
	require('load-grunt-tasks')(grunt)

	const pkg = grunt.file.readJSON('package.json')
	const paths = {
		releaseDir: `build/${pkg.name}`,
		archive: `./build/${pkg.name}-${pkg.version}.zip`,
	}

	/**
	 * Files that should be included in the production build.
	 *
	 * The goal is to ship only what is required for the plugin to run on a
	 * WordPress site:
	 * - PHP source (`app/`, `core/` and the main plugin file)
	 * - Compiled assets (`assets/`)
	 * - Translation files (`languages/`)
	 * - Optional changelog / readme for the end user
	 *
	 * Development and tooling files (tests, build config, source JS/SCSS,
	 * coding standards configs, etc.) are intentionally excluded to keep the
	 * final zip size small while retaining full functionality.
	 */
	const copyFiles = [
		'app/**',
		'core/**',
		'assets/**',
		'languages/**',
		'uninstall.php',
		'wpmudev-plugin-test.php',
		// Composer manifest so `composer:install` can install runtime deps.
		'composer.json',
		'composer.lock',
		// Optional documentation for the end user.
		'changelog.txt',
		'README.md',
		// Never ship the development vendor tree or sourcemaps.
		'!vendor/**',
		'!**/*.map',
		// Explicitly exclude development / tooling / source files.
		'!node_modules/**',
		'!src/**',
		'!tests/**',
		'!Gruntfile.js',
		'!gulpfile.js',
		'!webpack.config.js',
		'!phpcs.ruleset.xml',
		'!phpunit.xml.dist',
		'!QUESTIONS.md',
	]

	const excludeCopyFilesPro = copyFiles.slice(0)

	grunt.initConfig({
		pkg,
		paths,

		// Clean temp folders and release copies.
		clean: {
			temp: {
				src: ['**/*.tmp', '**/.afpDeleted*', '**/.DS_Store'],
				dot: true,
				filter: 'isFile',
			},
			assets: [
				'assets/css/**',
				'assets/js/**',
				'!assets/css/posts-maintenance.css',
				'!assets/js/posts-maintenance.js',
			],
			folder_v2: ['build/**'],
		},

		checktextdomain: {
			options: {
				text_domain: 'wpmudev-plugin-test',
				keywords: [
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d',
					'esc_attr_e:1,2d',
					'esc_attr_x:1,2c,3d',
					'_ex:1,2c,3d',
					'_n:1,2,4d',
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d',
				],
			},
			files: {
				src: [
					'app/templates/**/*.php',
					'core/**/*.php',
					'!core/external/**', // Exclude external libs.
					'google-analytics-async.php',
				],
				expand: true,
			},
		},

		copy: {
			pro: {
				files: [
					{
						expand: true,
						dot: true,
						cwd: '.',
						src: excludeCopyFilesPro,
						dest: '<%= paths.releaseDir %>/',
					},
				],
			},
		},

		compress: {
			pro: {
				options: {
					mode: 'zip',
					archive: '<%= paths.archive %>',
				},
				expand: true,
				cwd: '<%= paths.releaseDir %>/',
				src: ['**/*'],
				dest: '<%= pkg.name %>/',
			},
		},
	})

	grunt.loadNpmTasks('grunt-search')

	grunt.registerTask('version-compare', ['search'])
	grunt.registerTask('finish', function () {
		const json = grunt.file.readJSON('package.json')
		const file = './build/' + json.name + '-' + json.version + '.zip'
		grunt.log.writeln('Process finished.')

		grunt.log.writeln('----------')
	})

	grunt.registerTask('composer:install', 'Install production composer dependencies', function () {
		const done = this.async()
		const releaseDir = grunt.template.process('<%= paths.releaseDir %>')

		grunt.log.writeln('Installing Composer dependencies inside ' + releaseDir)

		grunt.util.spawn(
			{
				cmd: 'composer',
				args: [
					'install',
					'--no-dev',
					'--prefer-dist',
					'--optimize-autoloader',
					'--no-interaction',
					'--no-progress',
				],
				opts: { cwd: releaseDir },
			},
			function (error, result) {
				if (result && result.stdout) {
					grunt.log.writeln(result.stdout)
				}
				if (result && result.stderr) {
					grunt.log.error(result.stderr)
				}

				if (error) {
					grunt.fail.warn('Composer install failed')
					return done(false)
				}
				return done()
			}
		)
	})

	grunt.registerTask('build', [
		'checktextdomain',
		'copy:pro',
		'composer:install',
		'compress:pro',
	])

	grunt.registerTask('preBuildClean', [
		'clean:temp',
		'clean:assets',
		'clean:folder_v2',
	])
}
