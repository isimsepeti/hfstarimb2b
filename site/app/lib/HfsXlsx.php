<?php
/**
 * Bağımlılıksız Excel (.xlsx) yazma / okuma.
 *
 * @package HFS_B2B
 */

class HfsXlsx {

	const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';

	/**
	 * Sayfaları xlsx dosyasına yazar.
	 *
	 * @param array  $sheets [ ['name' => 'Fiyatlar', 'rows' => [ ['a','b',1.2], ... ], 'col_types' => ['s','s','n'] ], ... ]
	 * @param string $filepath Hedef dosya.
	 * @return true|WP_Error
	 */
	public static function write( $sheets, $filepath ) {
		$sheets = array_values( (array) $sheets );
		if ( empty( $sheets ) ) {
			return new WP_Error( 'hfs_xlsx_empty', __( 'Yazılacak sayfa yok.', 'hfs-b2b' ) );
		}

		$overrides = array(
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
			'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
		);
		$wb_rels   = '';
		$wb_sheets = '';
		$rid       = 1;
		$files     = array();
		foreach ( $sheets as $i => $sheet ) {
			$n            = $i + 1;
			$overrides[]  = '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb_rels     .= '<Relationship Id="rId' . $rid . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$wb_sheets   .= '<sheet name="' . self::esc_attr( self::safe_sheet_name( isset( $sheet['name'] ) ? $sheet['name'] : ( 'Sayfa' . $n ) ) ) . '" sheetId="' . $n . '" r:id="rId' . $rid . '"/>';
			$files[ 'xl/worksheets/sheet' . $n . '.xml' ] = self::sheet_xml( $sheet );
			$rid++;
		}
		$style_rid = $rid;
		$wb_rels  .= '<Relationship Id="rId' . $style_rid . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>';

		$files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. implode( '', $overrides )
			. '</Types>';
		$files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="' . self::NS_PKG . '">'
			. '<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
		$files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="' . self::NS_PKG . '">' . $wb_rels . '</Relationships>';
		$files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
			. '<sheets>' . $wb_sheets . '</sheets>'
			. '</workbook>';
		$files['xl/styles.xml'] = self::styles_xml();

		return HfsZip::write( $filepath, $files );
	}

	/**
	 * xlsx dosyasından satırları okur. İsim verilen sayfa yoksa ilk sayfa kullanılır.
	 *
	 * @param string $filepath Dosya.
	 * @param string $prefer_sheet Tercih edilen sayfa adı.
	 * @return array|WP_Error Satırlar (her satır dizi).
	 */
	public static function read( $filepath, $prefer_sheet = '', $sheet_index = 0 ) {
		if ( ! is_readable( $filepath ) ) {
			return new WP_Error( 'hfs_xlsx_read', __( 'Excel dosyası okunamadı.', 'hfs-b2b' ) );
		}

		$zip_files = HfsZip::extract( $filepath );
		if ( is_wp_error( $zip_files ) ) {
			return $zip_files;
		}

		$workbook = self::zip_get( $zip_files, 'xl/workbook.xml' );
		if ( $workbook === false ) {
			return new WP_Error( 'hfs_xlsx_wb', __( 'Excel çalışma kitabı okunamadı.', 'hfs-b2b' ) );
		}

		$rels_xml = self::zip_get( $zip_files, 'xl/_rels/workbook.xml.rels' );
		$rel_map  = array();
		if ( $rels_xml !== false ) {
			$rels = self::load_xml( $rels_xml );
			if ( $rels ) {
				foreach ( $rels->Relationship as $rel ) {
					$rel_map[ (string) $rel['Id'] ] = (string) $rel['Target'];
				}
			}
		}

		$wb         = self::load_xml( $workbook );
		$sheet_path = '';
		if ( $wb ) {
			$ns = $wb->getNamespaces( true );
			$rns = isset( $ns['r'] ) ? $ns['r'] : self::NS_REL;
			$first_path = '';
			foreach ( $wb->sheets->sheet as $sheet ) {
				$r_id = (string) $sheet->attributes( $rns )['id'];
				$name = (string) $sheet['name'];
				$target = isset( $rel_map[ $r_id ] ) ? $rel_map[ $r_id ] : '';
				if ( $target === '' ) {
					continue;
				}
				if ( strpos( $target, '/' ) !== 0 && strpos( $target, 'xl/' ) !== 0 ) {
					$target = 'xl/' . ltrim( $target, '/' );
				} elseif ( strpos( $target, '/' ) === 0 ) {
					$target = ltrim( $target, '/' );
				}
				if ( $first_path === '' ) {
					$first_path = $target;
				}
				if ( $prefer_sheet !== '' && self::norm( $name ) === self::norm( $prefer_sheet ) ) {
					$sheet_path = $target;
					break;
				}
			}
			if ( $sheet_path === '' ) {
				$sheet_path = $first_path;
			}
		}
		if ( $sheet_path === '' ) {
			$sheet_path = 'xl/worksheets/sheet1.xml';
		}

		$shared = array();
		$sst    = self::zip_get( $zip_files, 'xl/sharedStrings.xml' );
		if ( $sst !== false ) {
			$sst_xml = self::load_xml( $sst );
			if ( $sst_xml ) {
				$sst_xml->registerXPathNamespace( 'm', self::NS_MAIN );
				$sis = $sst_xml->xpath( '//m:si' );
				if ( is_array( $sis ) ) {
					foreach ( $sis as $si ) {
						$shared[] = self::si_text( $si );
					}
				}
			}
		}

		$sheet_xml = self::zip_get( $zip_files, $sheet_path );
		if ( $sheet_xml === false ) {
			return new WP_Error( 'hfs_xlsx_sheet', __( 'Excel sayfası okunamadı.', 'hfs-b2b' ) );
		}

		$sheet = self::load_xml( $sheet_xml );
		if ( ! $sheet ) {
			return new WP_Error( 'hfs_xlsx_parse', __( 'Excel sayfası çözümlenemedi.', 'hfs-b2b' ) );
		}
		$sheet->registerXPathNamespace( 'm', self::NS_MAIN );
		$rows_xml = $sheet->xpath( '//m:sheetData/m:row' );
		$out      = array();
		if ( ! is_array( $rows_xml ) ) {
			return $out;
		}
		foreach ( $rows_xml as $row ) {
			$row->registerXPathNamespace( 'm', self::NS_MAIN );
			$cell_nodes = $row->xpath( './m:c' );
			if ( ! is_array( $cell_nodes ) ) {
				continue;
			}
			$cells = array();
			foreach ( $cell_nodes as $c ) {
				$ref = self::xml_attr( $c, 'r' );
				$idx = self::col_index( $ref );
				$cells[ $idx ] = self::cell_value( $c, $shared );
			}
			if ( empty( $cells ) ) {
				continue;
			}
			$max = max( array_keys( $cells ) );
			$line = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * @param array  $files Zip girişleri.
	 * @param string $name  Yol.
	 * @return string|false
	 */
	private static function zip_get( $files, $name ) {
		$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
		if ( isset( $files[ $name ] ) ) {
			return $files[ $name ];
		}
		foreach ( $files as $key => $val ) {
			if ( ltrim( str_replace( '\\', '/', (string) $key ), '/' ) === $name ) {
				return $val;
			}
		}
		return false;
	}

	private static function sheet_xml( $sheet ) {
		$rows      = isset( $sheet['rows'] ) && is_array( $sheet['rows'] ) ? $sheet['rows'] : array();
		$col_types = isset( $sheet['col_types'] ) && is_array( $sheet['col_types'] ) ? $sheet['col_types'] : array();
		$max_col   = 1;
		foreach ( $rows as $row ) {
			$max_col = max( $max_col, count( (array) $row ) );
		}
		$last_col = self::col_letter( $max_col );
		$last_row = max( 1, count( $rows ) );
		$xml      = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml     .= '<worksheet xmlns="' . self::NS_MAIN . '">';
		$xml     .= '<dimension ref="A1:' . $last_col . $last_row . '"/>';
		$xml     .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
		$xml     .= '<cols>';
		for ( $c = 1; $c <= $max_col; $c++ ) {
			$w = 18;
			if ( $c === 2 ) {
				$w = 48;
			} elseif ( $c === 3 ) {
				$w = 14;
			}
			$xml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
		}
		$xml .= '</cols>';
		$xml .= '<sheetData>';
		$r = 1;
		foreach ( $rows as $row ) {
			$row = array_values( (array) $row );
			$xml .= '<row r="' . $r . '">';
			foreach ( $row as $ci => $val ) {
				$letter = self::col_letter( $ci + 1 );
				$type   = isset( $col_types[ $ci ] ) ? $col_types[ $ci ] : 's';
				$is_hdr = ( $r === 1 );
				if ( $type === 'n' && ! $is_hdr && $val !== '' && $val !== null && is_numeric( $val ) ) {
					$style = ' s="2"';
					$xml  .= '<c r="' . $letter . $r . '"' . $style . '><v>' . self::esc( (string) $val ) . '</v></c>';
				} else {
					$style = $is_hdr ? ' s="1"' : '';
					$xml  .= '<c r="' . $letter . $r . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::esc( (string) $val ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
			$r++;
		}
		$xml .= '</sheetData></worksheet>';
		return $xml;
	}

	private static function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="' . self::NS_MAIN . '">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
			. '<fonts count="2">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
			. '<cellXfs count="3">'
			. '<xf xfId="0"/>'
			. '<xf xfId="0" fontId="1" applyFont="1"/>'
			. '<xf xfId="0" numFmtId="164" applyNumberFormat="1"/>'
			. '</cellXfs>'
			. '</styleSheet>';
	}

	private static function cell_value( $c, $shared ) {
		$t = self::xml_attr( $c, 't' );
		$c->registerXPathNamespace( 'm', self::NS_MAIN );
		if ( $t === 's' ) {
			$vs  = $c->xpath( './m:v' );
			$idx = isset( $vs[0] ) ? (int) (string) $vs[0] : 0;
			return isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
		}
		if ( $t === 'inlineStr' ) {
			$ts = $c->xpath( './/m:t' );
			return self::concat_nodes( $ts );
		}
		if ( $t === 'str' || $t === 'e' ) {
			$vs = $c->xpath( './m:v' );
			return isset( $vs[0] ) ? (string) $vs[0] : '';
		}
		$vs = $c->xpath( './m:v' );
		if ( isset( $vs[0] ) ) {
			return (string) $vs[0];
		}
		$ts = $c->xpath( './/m:t' );
		return self::concat_nodes( $ts );
	}

	private static function concat_nodes( $nodes ) {
		if ( ! is_array( $nodes ) ) {
			return '';
		}
		$text = '';
		foreach ( $nodes as $n ) {
			$text .= (string) $n;
		}
		return $text;
	}

	private static function xml_attr( $el, $name ) {
		$attrs = $el->attributes();
		if ( $attrs && isset( $attrs[ $name ] ) ) {
			return (string) $attrs[ $name ];
		}
		$attrs = $el->attributes( '' );
		if ( $attrs && isset( $attrs[ $name ] ) ) {
			return (string) $attrs[ $name ];
		}
		$raw = $el->asXML();
		if ( is_string( $raw ) && preg_match( '/\s' . preg_quote( $name, '/' ) . '="([^"]*)"/', $raw, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
		}
		return '';
	}

	private static function si_text( $si ) {
		$text = '';
		$si->registerXPathNamespace( 'm', self::NS_MAIN );
		$ts = $si->xpath( './/m:t' );
		if ( is_array( $ts ) ) {
			foreach ( $ts as $t ) {
				$text .= (string) $t;
			}
		}
		return $text;
	}

	private static function normalize_spreadsheet_xml( $str ) {
		if ( ! is_string( $str ) || $str === '' || strpos( $str, 'xmlns:x=' ) === false ) {
			return $str;
		}
		$str = preg_replace( '/xmlns:x="/', 'xmlns="', $str, 1 );
		$str = preg_replace( '/<(\/?)x:/', '<$1', $str );
		return is_string( $str ) ? $str : '';
	}

	private static function load_xml( $str ) {
		$str  = self::normalize_spreadsheet_xml( $str );
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $str, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $xml ? $xml : null;
	}

	private static function col_index( $ref ) {
		if ( ! preg_match( '/^([A-Za-z]+)/', (string) $ref, $m ) ) {
			return 0;
		}
		$letters = strtoupper( $m[1] );
		$n       = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = ( $n * 26 ) + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	private static function col_letter( $n ) {
		$s = '';
		while ( $n > 0 ) {
			$n--;
			$s = chr( 65 + ( $n % 26 ) ) . $s;
			$n = (int) floor( $n / 26 );
		}
		return $s;
	}

	private static function safe_sheet_name( $name ) {
		$name = preg_replace( '/[\\\\\/\*\?\:\[\]]/', ' ', (string) $name );
		$name = trim( $name );
		if ( $name === '' ) {
			$name = 'Sayfa';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 );
	}

	private static function esc( $s ) {
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s );
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function esc_attr( $s ) {
		return self::esc( $s );
	}

	private static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		return preg_replace( '/\s+/', '', $s );
	}
}
