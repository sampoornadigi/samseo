<?php
/**
 * Migration engine: dry-run diff, non-destructive import, verification.
 *
 * Safety-first (build plan §4.9): diff previews every change before any write;
 * import only fills EMPTY Sampoorna fields (never overwrites our own values,
 * never touches the source plugin's data) and is idempotent + resumable via an
 * object-ID cursor; verify re-reads what we wrote and compares it to the source.
 *
 * Object-type aware: the same logic runs for posts (Source::target_ids/read +
 * MetaStore) and terms (Source::term_ids/read_term + TermMeta), selected by the
 * $object_type argument and resolved in targets().
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TermMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs dry-run / import / verify over a migration Source.
 */
class Migrator {

	/** Per-object field rows captured for the dry-run/verify display. */
	const SAMPLE_CAP = 100;

	/**
	 * Resolve the source readers + target store for an object type.
	 *
	 * @param Source $source Source adapter.
	 * @param string $type   'post' or 'term'.
	 * @return array{count:callable,ids:callable,read:callable,get:callable,save:callable}
	 */
	private static function targets( Source $source, $type ) {
		if ( 'term' === $type ) {
			return array(
				'count' => static function () use ( $source ) {
					return $source->term_count();
				},
				'ids'   => static function ( $after, $limit ) use ( $source ) {
					return $source->term_ids( $after, $limit );
				},
				'read'  => static function ( $id ) use ( $source ) {
					return $source->read_term( $id );
				},
				'get'   => static function ( $id, $field ) {
					return TermMeta::get( $id, $field );
				},
				'save'  => static function ( $id, $values ) {
					TermMeta::save( $id, $values );
				},
			);
		}
		return array(
			'count' => static function () use ( $source ) {
				return $source->count();
			},
			'ids'   => static function ( $after, $limit ) use ( $source ) {
				return $source->target_ids( $after, $limit );
			},
			'read'  => static function ( $id ) use ( $source ) {
				return $source->read( $id );
			},
			'get'   => static function ( $id, $field ) {
				return MetaStore::get( $id, $field );
			},
			'save'  => static function ( $id, $values ) {
				MetaStore::save( $id, $values );
			},
		);
	}

	/**
	 * Dry-run: classify what an import would do, writing nothing.
	 *
	 * @param Source $source      Source adapter.
	 * @param int    $limit       Max objects to scan (0 = all).
	 * @param string $object_type 'post' or 'term'.
	 * @return array{counts:array<string,int>,posts:int,sample:array<int,array<string,mixed>>}
	 */
	public static function diff( Source $source, $limit = 0, $object_type = 'post' ) {
		$t      = self::targets( $source, $object_type );
		$counts = array(
			'add'         => 0,
			'same'        => 0,
			'skip_exists' => 0,
		);
		$sample = array();
		$posts  = 0;
		$after  = 0;
		$batch  = 200;

		while ( true ) {
			$ids = ( $t['ids'] )( $after, $batch );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				++$posts;
				$after  = $id;
				$fields = ( $t['read'] )( $id );
				foreach ( $fields as $field => $value ) {
					$current = ( $t['get'] )( $id, $field );
					$action  = self::classify( $current, $value );
					++$counts[ $action ];
					if ( count( $sample ) < self::SAMPLE_CAP ) {
						$sample[] = array(
							'post_id' => $id,
							'field'   => $field,
							'from'    => $current,
							'to'      => $value,
							'action'  => $action,
						);
					}
				}
				if ( $limit > 0 && $posts >= $limit ) {
					break 2;
				}
			}
			if ( count( $ids ) < $batch ) {
				break;
			}
		}

		return array(
			'counts' => $counts,
			'posts'  => $posts,
			'sample' => $sample,
		);
	}

	/**
	 * Import one batch of objects after a cursor (fill-empty, non-destructive).
	 *
	 * @param Source $source      Source adapter.
	 * @param int    $batch       Objects per batch.
	 * @param int    $after_id    Cursor: only objects with id greater than this.
	 * @param string $object_type 'post' or 'term'.
	 * @return array{processed:int,written:int,last_id:int,remaining:int,total:int}
	 */
	public static function import( Source $source, $batch, $after_id, $object_type = 'post' ) {
		$t       = self::targets( $source, $object_type );
		$batch   = max( 1, (int) $batch );
		$ids     = ( $t['ids'] )( (int) $after_id, $batch );
		$written = 0;
		$last_id = (int) $after_id;

		foreach ( $ids as $id ) {
			$last_id = $id;
			$values  = self::fillable( $t, $id );
			if ( ! empty( $values ) ) {
				( $t['save'] )( $id, $values );
				++$written;
			}
		}

		$remaining = count( ( $t['ids'] )( $last_id, 1 ) );

		return array(
			'processed' => count( $ids ),
			'written'   => $written,
			'last_id'   => $last_id,
			'remaining' => $remaining,
			'total'     => ( $t['count'] )(),
		);
	}

	/**
	 * Verify imported data against the source.
	 *
	 * @param Source $source      Source adapter.
	 * @param int    $limit       Max objects to check (0 = all).
	 * @param string $object_type 'post' or 'term'.
	 * @return array{checked:int,match:int,mismatch:int,sample:array<int,array<string,mixed>>}
	 */
	public static function verify( Source $source, $limit = 0, $object_type = 'post' ) {
		$t        = self::targets( $source, $object_type );
		$match    = 0;
		$mismatch = 0;
		$checked  = 0;
		$sample   = array();
		$after    = 0;
		$batch    = 200;

		while ( true ) {
			$ids = ( $t['ids'] )( $after, $batch );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				++$checked;
				$after  = $id;
				$fields = ( $t['read'] )( $id );
				foreach ( $fields as $field => $expected ) {
					$current = ( $t['get'] )( $id, $field );
					if ( $current === $expected ) {
						++$match;
					} else {
						++$mismatch;
						if ( count( $sample ) < self::SAMPLE_CAP ) {
							$sample[] = array(
								'post_id'  => $id,
								'field'    => $field,
								'expected' => $expected,
								'actual'   => $current,
							);
						}
					}
				}
				if ( $limit > 0 && $checked >= $limit ) {
					break 2;
				}
			}
			if ( count( $ids ) < $batch ) {
				break;
			}
		}

		return array(
			'checked'  => $checked,
			'match'    => $match,
			'mismatch' => $mismatch,
			'sample'   => $sample,
		);
	}

	/**
	 * The source fields that would be written (those where ours is empty).
	 *
	 * @param array{read:callable,get:callable} $t  Resolved target callables.
	 * @param int                               $id Object ID.
	 * @return array<string,string>
	 */
	private static function fillable( array $t, $id ) {
		$out = array();
		foreach ( ( $t['read'] )( $id ) as $field => $value ) {
			if ( '' === ( $t['get'] )( $id, $field ) ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Classify a single field for the dry-run diff.
	 *
	 * @param string $current  Our current value.
	 * @param string $incoming Normalized source value.
	 * @return string add|same|skip_exists
	 */
	private static function classify( $current, $incoming ) {
		if ( '' === $current ) {
			return 'add';
		}
		return $current === $incoming ? 'same' : 'skip_exists';
	}
}
