<?php
declare(strict_types=1);

if (!class_exists('WP_Error', false)) {
    class WP_Error
    {
        public function __construct(private string $code, private string $message)
        {
        }
        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $v): bool
    {
        return $v instanceof WP_Error;
    }
}
if (!function_exists('__')) {
    function __(string $s, string $d = ''): string
    {
        return $s;
    }
}

class HfsZip {

	/**
	 * Dosya map'ini zip olarak kaydet.
	 *
	 * @param string $filepath Hedef.
	 * @param array  $files    [ 'path/in/zip' => 'içerik' ].
	 * @return true|WP_Error
	 */
	public static function write( $filepath, $files ) {
		$blob = self::build_store_zip( $files );
		if ( $blob === '' ) {
			return new WP_Error( 'hfs_zip_empty', __( 'Excel dosyası oluşturulamadı.', 'hfs-b2b' ) );
		}
		if ( file_exists( $filepath ) ) {
			@unlink( $filepath );
		}
		$ok = file_put_contents( $filepath, $blob );
		if ( $ok === false ) {
			return new WP_Error( 'hfs_zip_write', __( 'Excel dosyası diske yazılamadı.', 'hfs-b2b' ) );
		}
		return true;
	}

	/**
	 * Zip içinden bir giriş oku.
	 *
	 * @param string $filepath Zip yolu.
	 * @param string $name     Giriş adı.
	 * @return string|false
	 */
	public static function get( $filepath, $name ) {
		$map = self::extract( $filepath );
		if ( is_wp_error( $map ) ) {
			return false;
		}
		$name = self::norm_name( $name );
		if ( isset( $map[ $name ] ) ) {
			return $map[ $name ];
		}
		foreach ( $map as $key => $val ) {
			if ( self::norm_name( $key ) === $name ) {
				return $val;
			}
		}
		return false;
	}

	/**
	 * Tüm zip girişlerini çıkar.
	 *
	 * @param string $filepath Zip.
	 * @return array|WP_Error
	 */
	public static function extract( $filepath ) {
		if ( ! is_readable( $filepath ) ) {
			return new WP_Error( 'hfs_zip_read', __( 'Excel dosyası okunamadı.', 'hfs-b2b' ) );
		}

		$bin = file_get_contents( $filepath );
		if ( $bin === false || strlen( $bin ) < 22 ) {
			return new WP_Error( 'hfs_zip_open', __( 'Bu bir geçerli Excel (.xlsx) dosyası değil.', 'hfs-b2b' ) );
		}
		$native = self::extract_from_string( $bin );
		if ( ! is_wp_error( $native ) ) {
			return $native;
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( $zip->open( $filepath ) === true ) {
				$out = array();
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$stat = $zip->statIndex( $i );
					if ( ! $stat || empty( $stat['name'] ) ) {
						continue;
					}
					$data = $zip->getFromIndex( $i );
					if ( $data !== false ) {
						$out[ self::norm_name( $stat['name'] ) ] = $data;
					}
				}
				$zip->close();
				if ( ! empty( $out ) ) {
					return $out;
				}
			}
		}

		return $native;
	}

	/**
	 * @param array $files Dosyalar.
	 * @return string
	 */
	private static function build_store_zip( $files ) {
		$now  = getdate();
		$time = ( (int) $now['hours'] << 11 ) | ( (int) $now['minutes'] << 5 ) | (int) max( 0, floor( (int) $now['seconds'] / 2 ) );
		$date = ( ( (int) $now['year'] - 1980 ) << 9 ) | ( (int) $now['mon'] << 5 ) | (int) $now['mday'];
		$flag = 0x0800; // UTF-8

		$local   = '';
		$central = '';
		$offset  = 0;
		$count   = 0;

		foreach ( $files as $name => $data ) {
			$name = str_replace( '\\', '/', (string) $name );
			$name = ltrim( $name, '/' );
			$data = (string) $data;
			$len  = strlen( $data );
			$nlen = strlen( $name );
			$crc  = self::crc32_pack( $data );

			$header = pack( 'VvvvvvVVVvv', 0x04034b50, 20, $flag, 0, $time, $date, $crc, $len, $len, $nlen, 0 );
			$entry  = $header . $name . $data;
			$local .= $entry;

			$central .= pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,
				20,
				20,
				$flag,
				0,
				$time,
				$date,
				$crc,
				$len,
				$len,
				$nlen,
				0,
				0,
				0,
				0,
				0,
				$offset
			) . $name;

			$offset += strlen( $entry );
			$count++;
		}

		if ( $count === 0 ) {
			return '';
		}

		$eocd = pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen( $central ), $offset, 0 );
		return $local . $central . $eocd;
	}

	/**
	 * @param string $bin Zip içeriği.
	 * @return array|WP_Error
	 */
	private static function extract_from_string( $bin ) {
		$eocd = self::find_eocd( $bin );
		if ( $eocd === null ) {
			return new WP_Error( 'hfs_zip_eocd', __( 'Bu bir geçerli Excel (.xlsx) dosyası değil.', 'hfs-b2b' ) );
		}

		$count  = $eocd['count'];
		$cd_off = $eocd['cd_offset'];
		$pos    = $cd_off;
		$out    = array();
		$len    = strlen( $bin );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( $pos + 46 > $len ) {
				break;
			}
			$sig = self::u32( $bin, $pos );
			if ( $sig !== 0x02014b50 ) {
				break;
			}
			$flag    = self::u16( $bin, $pos + 8 );
			$method  = self::u16( $bin, $pos + 10 );
			$csz     = self::u32( $bin, $pos + 20 );
			$usz     = self::u32( $bin, $pos + 24 );
			$nlen    = self::u16( $bin, $pos + 28 );
			$elen    = self::u16( $bin, $pos + 30 );
			$clen    = self::u16( $bin, $pos + 32 );
			$local   = self::u32( $bin, $pos + 42 );
			$name    = substr( $bin, $pos + 46, $nlen );
			$pos    += 46 + $nlen + $elen + $clen;

			if ( $name === '' || substr( $name, -1 ) === '/' ) {
				continue;
			}
			if ( $flag & 1 ) {
				continue;
			}

			if ( $local + 30 > $len ) {
				continue;
			}
			$lnlen = self::u16( $bin, $local + 26 );
			$lelen = self::u16( $bin, $local + 28 );
			$data_off = $local + 30 + $lnlen + $lelen;
			$payload  = substr( $bin, $data_off, $csz );
			if ( $payload === false ) {
				continue;
			}

			$decoded = self::decompress( $payload, $method, $usz );
			if ( $decoded === false ) {
				return new WP_Error(
					'hfs_zip_deflate',
					__( 'Excel dosyası okunamadı (sıkıştırma desteği yok). Dosyayı yeniden .xlsx olarak kaydedip tekrar deneyin.', 'hfs-b2b' )
				);
			}
			$out[ self::norm_name( $name ) ] = $decoded;
		}

		if ( empty( $out ) ) {
			return new WP_Error( 'hfs_zip_open', __( 'Bu bir geçerli Excel (.xlsx) dosyası değil.', 'hfs-b2b' ) );
		}
		return $out;
	}

	private static function decompress( $data, $method, $usz ) {
		$method = (int) $method;
		if ( $method === 0 ) {
			return $data;
		}
		if ( $method !== 8 ) {
			return false;
		}
		if ( function_exists( 'gzinflate' ) ) {
			$out = @gzinflate( $data );
			if ( $out !== false ) {
				return $out;
			}
		}
		if ( function_exists( 'zlib_decode' ) ) {
			$out = @zlib_decode( $data );
			if ( $out !== false ) {
				return $out;
			}
		}
		if ( $usz === 0 && $data === '' ) {
			return '';
		}
		return false;
	}

	private static function find_eocd( $bin ) {
		$len = strlen( $bin );
		$max = min( $len - 22, 65535 );
		for ( $i = 0; $i <= $max; $i++ ) {
			$pos = $len - 22 - $i;
			if ( $pos < 0 ) {
				break;
			}
			if ( substr( $bin, $pos, 4 ) !== "PK\x05\x06" ) {
				continue;
			}
			return array(
				'count'     => self::u16( $bin, $pos + 10 ),
				'cd_size'   => self::u32( $bin, $pos + 12 ),
				'cd_offset' => self::u32( $bin, $pos + 16 ),
			);
		}
		return null;
	}

	private static function crc32_pack( $data ) {
		return crc32( $data ) & 0xFFFFFFFF;
	}

	private static function u16( $bin, $off ) {
		$u = unpack( 'v', substr( $bin, $off, 2 ) );
		return $u ? (int) $u[1] : 0;
	}

	private static function u32( $bin, $off ) {
		$u = unpack( 'V', substr( $bin, $off, 4 ) );
		if ( ! $u ) {
			return 0;
		}
		$n = $u[1];
		if ( $n < 0 ) {
			$n += 4294967296;
		}
		return (int) $n;
	}

	private static function norm_name( $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		return ltrim( $name, '/' );
	}
}
