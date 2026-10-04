<?php
/**
 * Generates languages/speedy-sensor.pot from the plugin source.
 *
 * Deliberately dependency free: the shipped plugin has no runtime Composer
 * dependency and the translation template stays on the same footing. It reads
 * the token stream with token_get_all() rather than guessing with regexes.
 *
 * Usage: php bin/make-pot.php
 *
 * @package Speedy_Sensor
 */

declare( strict_types = 1 );

const TEXT_DOMAIN = 'speedy-sensor';

/**
 * Gettext functions this extractor understands.
 *
 * Each entry maps to the number of leading string arguments that are
 * translatable: singular only, or singular and plural for the _n family.
 */
const FUNCTIONS = array(
	'__'             => 1,
	'_e'             => 1,
	'esc_html__'     => 1,
	'esc_html_e'     => 1,
	'esc_attr__'     => 1,
	'esc_attr_e'     => 1,
	'esc_textarea__' => 1,
	'_x'             => 2,
	'_ex'            => 2,
	'esc_html_x'     => 2,
	'_n'             => 2,
	'_nx'            => 3,
);

/**
 * Directories that hold no translatable strings.
 */
const SKIP_DIRS = array( 'vendor', 'node_modules', 'tests', 'bin', 'languages', '.git' );

/**
 * Collects translatable strings from the plugin source.
 *
 * @param string $root Plugin root directory.
 * @return array Map of key to list of file:line references.
 */
function collect_entries( string $root ): array {
	$entries = array();

	foreach ( source_files( $root ) as $file ) {
		$relative = ltrim( str_replace( $root, '', $file ), '/' );

		foreach ( extract_file( $file ) as $key => $line ) {
			if ( ! isset( $entries[ $key ] ) ) {
				$entries[ $key ] = array();
			}

			$entries[ $key ][] = $relative . ':' . $line;
		}
	}

	ksort( $entries );

	return $entries;
}

/**
 * Lists the PHP files that may hold translatable strings.
 *
 * @param string $root Plugin root directory.
 * @return string[]
 */
function source_files( string $root ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$relative = str_replace( $root, '', $file->getPathname() );

		foreach ( SKIP_DIRS as $fragment ) {
			if ( 0 === strpos( $relative, '/' . $fragment ) ) {
				continue 2;
			}
		}

		$files[] = $file->getPathname();
	}

	sort( $files );

	return $files;
}

/**
 * Extracts msgid to line mappings from one file.
 *
 * The returned key is the msgid, prefixed with the translators context and a
 * \4 separator when a context comment was found.
 *
 * @param string $file Absolute path.
 * @return array Map of key to line number.
 */
function extract_file( string $file ): array {
	$tokens  = token_get_all( (string) file_get_contents( $file ) );
	$count   = count( $tokens );
	$entries = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( FUNCTIONS[ $token[1] ] ) ) {
			continue;
		}

		// Skip method calls, static calls and any declaration.
		if ( ! is_call_expression( $tokens, $i ) ) {
			continue;
		}

		$msgid = first_string_argument( $tokens, $i );

		if ( null === $msgid ) {
			continue;
		}

		$context = find_context( $tokens, $i );
		$key     = ( null === $context ) ? $msgid : $context . "\4" . $msgid;

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = $token[2];
		}
	}

	return $entries;
}

/**
 * Whether the identifier at $index is a function call.
 *
 * @param array $tokens Token stream.
 * @param int   $index  Index of the identifier.
 * @return bool
 */
function is_call_expression( array $tokens, int $index ): bool {
	$count = count( $tokens );

	for ( $i = $index + 1; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( '(' !== $token ) {
			return false;
		}

		// A call is not preceded by an object or class operator.
		for ( $j = $index - 1; $j >= 0 && $j > $index - 12; $j-- ) {
			$before = $tokens[ $j ];

			if ( is_array( $before ) && in_array( $before[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return ! ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) );
		}

		return true;
	}

	return false;
}

/**
 * Reads the first literal string argument of a call.
 *
 * Concatenations and variables are not extractable and return null.
 *
 * @param array $tokens Token stream.
 * @param int   $index  Index of the function identifier.
 * @return string|null
 */
function first_string_argument( array $tokens, int $index ) {
	$count     = count( $tokens );
	$depth     = 0;
	$started   = false;

	for ( $i = $index + 1; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
			continue;
		}

		if ( '(' === $token ) {
			$depth++;
			$started = true;

			continue;
		}

		if ( ! $started || $depth < 1 ) {
			return null;
		}

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			return decode_literal( $token[1] );
		}

		// Variable, constant expression or operator before the msgid.
		if ( ! is_array( $token ) || ',' !== $token ) {
			return null;
		}
	}

	return null;
}

/**
 * Reads a translators comment sitting above the call.
 *
 * @param array $tokens Token stream.
 * @param int   $index  Index of the function identifier.
 * @return string|null
 */
function find_context( array $tokens, int $index ) {
	for ( $i = $index - 1; $i >= 0 && $i > $index - 40; $i-- ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			// Only an explicit translators comment is a context. Treating any
			// preceding docblock as context would turn every function summary
			// above a gettext call into a msgctxt.
			if ( false === stripos( $token[1], 'translators:' ) ) {
				return null;
			}

			return trim( preg_replace( '#^translators:\s*#i', '', trim( preg_replace( '#^/\*+|\*+/$|^//#', '', trim( $token[1] ) ) ) ) );
		}

		if ( ! is_array( $token ) ) {
			// Punctuation between the comment and the call is expected.
			if ( in_array( $token, array( ',', '(', ')', ';' ), true ) ) {
				continue;
			}

			return null;
		}

		if ( ! in_array( $token[0], array( T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING ), true ) ) {
			return null;
		}
	}

	return null;
}

/**
 * Decodes a PHP string literal into its runtime value.
 *
 * @param string $literal Raw literal including quotes.
 * @return string
 */
function decode_literal( string $literal ): string {
	$quote = substr( $literal, 0, 1 );
	$body  = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}

	return str_replace(
		array( '\\"', '\\\\', '\\n', '\\t', '\\r' ),
		array( '"', '\\', "\n", "\t", "\r" ),
		$body
	);
}

/**
 * Escapes a string for a POT file.
 *
 * @param string $value Raw string.
 * @return string
 */
function escape_pot( string $value ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t", "\r" ),
		array( '\\\\', '\\"', '\\n', '\\t', '' ),
		$value
	);
}

/**
 * Renders the POT file.
 *
 * @param array $entries Collected entries.
 * @return string
 */
function render_pot( array $entries ): string {
	$header = "# Copyright (C) Speedy\n"
		. "# This file is distributed under the GPL-2.0-or-later license.\n"
		. "msgid \"\"\n"
		. "msgstr \"\"\n"
		. "\"Project-Id-Version: Speedy Sensor\\n\"\n"
		. "\"Report-Msgid-Bugs-To: https://speedy.site/\\n\"\n"
		. "\"MIME-Version: 1.0\\n\"\n"
		. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
		. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
		. "\"Language-Team: https://speedy.site/\\n\"\n"
		. "\"X-Domain: " . TEXT_DOMAIN . "\\n\"\n\n";

	$out = $header;

	foreach ( $entries as $key => $references ) {
		$parts  = explode( "\4", (string) $key, 2 );
		$is_ctx = count( $parts ) > 1;
		$msgid  = $is_ctx ? $parts[1] : $parts[0];

		$out .= '#: ' . implode( ' ', $references ) . "\n";

		if ( $is_ctx ) {
			$out .= "#. translators: " . $parts[0] . "\n";
			$out .= 'msgctxt "' . escape_pot( $parts[0] ) . "\"\n";
		}

		$out .= 'msgid "' . escape_pot( $msgid ) . "\"\n";
		$out .= "msgstr \"\"\n\n";
	}

	return $out;
}

$root   = dirname( __DIR__ );
$target = $root . '/languages/' . TEXT_DOMAIN . '.pot';

if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0755, true ) ) {
	fwrite( STDERR, 'Could not create the languages directory.' . PHP_EOL );

	exit( 1 );
}

$entries = collect_entries( $root );

file_put_contents( $target, render_pot( $entries ) );

printf( 'Wrote %s with %d strings.%s', $target, count( $entries ), PHP_EOL );