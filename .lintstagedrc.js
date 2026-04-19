module.exports = {
	'*.php': (files) => [
		`vendor/bin/phpcs --standard=phpcs.xml.dist ${files.map((f) => `'${f}'`).join(' ')}`,
		'vendor/bin/phpstan analyse --no-progress --memory-limit=512M',
	],
};
