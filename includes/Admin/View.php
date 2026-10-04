<?php
/**
 * Rendering helpers shared by the admin screens.
 *
 * Everything here returns or echoes already-escaped markup. Keeping the
 * escaping next to the formatting means a screen author cannot forget it.
 *
 * @package Speedy_Sensor
 */

namespace Speedy_Sensor\Admin;

use Speedy_Sensor\Scanner\PluginScanner;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting and small markup builders.
 */
final class View {

	/**
	 * Core Web Vitals thresholds, in milliseconds, good / poor.
	 */
	const THRESHOLDS = array(
		'lcp'  => array( 2500, 4000 ),
		'cls'  => array( 0.1, 0.25 ),
		'inp'  => array( 200, 500 ),
		'ttfb' => array( 800, 1800 ),
	);

	/**
	 * Formats a byte count for display.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function bytes( $bytes ) {
		return PluginScanner::format_bytes( $bytes );
	}

	/**
	 * Grades one metric against its threshold.
	 *
	 * @param string $metric One of lcp, cls, inp, ttfb.
	 * @param mixed  $value  Measured value.
	 * @return string One of good, needs-improvement, poor, or empty.
	 */
	public static function grade( $metric, $value ) {
		if ( ! isset( self::THRESHOLDS[ $metric ] ) || ! is_numeric( $value ) ) {
			return '';
		}

		list( $good, $poor ) = self::THRESHOLDS[ $metric ];

		if ( (float) $value <= $good ) {
			return 'good';
		}

		return ( (float) $value > $poor ) ? 'poor' : 'needs-improvement';
	}

	/**
	 * Worst grade across the metrics supplied, ignoring gaps.
	 *
	 * @param array $values Map of metric to value.
	 * @return string
	 */
	public static function overall_grade( array $values ) {
		$order = array( 'good', 'needs-improvement', 'poor' );
		$worst = '';

		foreach ( $order as $grade ) {
			foreach ( $values as $metric => $value ) {
				if ( self::grade( $metric, $value ) === $grade ) {
					$worst = $grade;
					break 2;
				}
			}
		}

		return $worst;
	}

	/**
	 * Human label for a grade.
	 *
	 * @param string $grade Grade.
	 * @return string
	 */
	public static function grade_label( $grade ) {
		switch ( $grade ) {
			case 'good':
				return __( 'Good', 'speedy-sensor' );

			case 'needs-improvement':
				return __( 'Needs work', 'speedy-sensor' );

			case 'poor':
				return __( 'Poor', 'speedy-sensor' );

			default:
				return __( 'No data', 'speedy-sensor' );
		}
	}

	/**
	 * Builds a stat tile.
	 *
	 * @param string $label Label text.
	 * @param string $value Formatted value.
	 * @param string $note  Optional supporting line.
	 * @param string $tone  Optional tone class.
	 * @return string
	 */
	public static function tile( $label, $value, $note = '', $tone = '' ) {
		$html  = '<div class="speedy-tile' . ( '' !== $tone ? ' speedy-tile--' . esc_attr( $tone ) : '' ) . '">';
		$html .= '<p class="speedy-tile__label">' . esc_html( $label ) . '</p>';
		$html .= '<p class="speedy-tile__value">' . esc_html( $value ) . '</p>';

		if ( '' !== $note ) {
			$html .= '<p class="speedy-tile__note">' . esc_html( $note ) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Human text for a stored finding.
	 *
	 * Findings are persisted as codes and values, never as translated strings,
	 * so the report stays translatable after it has been written.
	 *
	 * @param string $code  Finding code.
	 * @param int    $value Finding value.
	 * @return string
	 */
	public static function finding_label( $code, $value ) {
		switch ( $code ) {
			case 'high_autoload':
				return sprintf(
					/* translators: %s: formatted byte size. */
					__( '%s of autoloaded options, loaded on every request', 'speedy-sensor' ),
					self::bytes( $value )
				);

			case 'many_transients':
				return sprintf(
					/* translators: %s: number of records. */
					__( '%s temporary records that may never expire', 'speedy-sensor' ),
					number_format_i18n( (int) $value )
				);

			case 'large_tables':
				return sprintf(
					/* translators: %s: formatted byte size. */
					__( '%s of table data', 'speedy-sensor' ),
					self::bytes( $value )
				);

			case 'many_options':
				return sprintf(
					/* translators: %s: number of options. */
					__( '%s options in the database', 'speedy-sensor' ),
					number_format_i18n( (int) $value )
				);

			case 'inactive_plugin':
				return __( 'Installed but not active', 'speedy-sensor' );

			case 'must_use_plugin':
				return __( 'Must-use plugin, always loaded', 'speedy-sensor' );

			default:
				return '';
		}
	}

	/**
	 * Renders an inline SVG sparkline from a series.
	 *
	 * @param array  $points List of arrays with date and value keys.
	 * @param string $label  Accessible label for the chart.
	 * @param int    $width  Viewbox width.
	 * @param int    $height Viewbox height.
	 * @return string SVG markup, or an empty string when there is no data.
	 */
	public static function sparkline( array $points, $label, $width = 640, $height = 56 ) {
		$values = array();

		foreach ( $points as $index => $point ) {
			$values[ $index ] = isset( $point['value'] ) && is_numeric( $point['value'] )
				? (float) $point['value']
				: null;
		}

		$plotted = array_filter(
			$values,
			static function ( $value ) {
				return null !== $value;
			}
		);

		if ( count( $plotted ) < 2 ) {
			return '';
		}

		$min = min( $plotted );
		$max = max( $plotted );

		if ( $max <= $min ) {
			$max = $min + 1;
		}

		$count   = count( $values );
		$step    = $count > 1 ? $width / ( $count - 1 ) : $width;
		$coords  = array();
		$markers = array();

		foreach ( $values as $index => $value ) {
			if ( null === $value ) {
				continue;
			}

			$x = round( $index * $step, 2 );
			$y = round( $height - ( ( ( $value - $min ) / ( $max - $min ) ) * $height ), 2 );

			$coords[] = $x . ',' . $y;

			$date      = isset( $points[ $index ]['date'] ) ? (string) $points[ $index ]['date'] : '';
			$markers[] = '<circle cx="' . esc_attr( $x ) . '" cy="' . esc_attr( $y ) . '" r="2.5"><title>'
				. esc_html( $date . ' — ' . $value ) . '</title></circle>';
		}

		return sprintf(
			'<svg class="speedy-spark" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s" focusable="false">'
				. '<polyline points="%4$s" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />'
				. '%5$s'
				. '</svg>',
			(int) $width,
			(int) $height,
			esc_attr( $label ),
			esc_attr( implode( ' ', $coords ) ),
			implode( '', $markers )
		);
	}
}
