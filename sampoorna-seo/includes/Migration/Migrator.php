<?php
/**
 * Migration engine: dry-run diff, non-destructive import, verification.
 *
 * Safety-first (build plan §4.9): diff previews every change before any write;
 * import only fills EMPTY Sampoorna fields (never overwrites our own values,
 * never touches the source plugin's data) and is idempotent + resumable via a
 * post-ID cursor; verify re-reads what we wrote and compares it to the source.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\Migration;

use Sampoorna\SEO\Meta\MetaStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs dry-run / import / verify over a migration Source.
 */
class Migrator {

	/** Per-post field rows captured for the dry-run/verify display. */
	const SAMPLE_CAP = 100;

	/**
	 * Dry-run: classify what an import would do, writing nothing.
	 *
	 * @param Source $source Source adapter.
	 * @param int    $limit  Max posts to scan (0 = all).
	 * @return array{counts:array<string,int>,posts:int,sample:array<int,array<string,mixed>>}
	 */
	public static function diff( Source $source, $limit = 0 ) {
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
			$ids = $source->target_ids( $after, $batch );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				++$posts;
				$after  = $id;
				$fields = $source->read( $id );
				foreach ( $fields as $field => $value ) {
					$current = MetaStore::get( $id, $field );
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
	 * Import one batch of posts after a cursor (fill-empty, non-destructive).
	 *
	 * @param Source $source   Source adapter.
	 * @param int    $batch    Posts per batch.
	 * @param int    $after_id Cursor: only posts with id greater than this.
	 * @return array{processed:int,written:int,last_id:int,remaining:int,total:int}
	 */
	public static function import( Source $source, $batch, $after_id ) {
		$batch   = max( 1, (int) $batch );
		$ids     = $source->target_ids( (int) $after_id, $batch );
		$written = 0;
		$last_id = (int) $after_id;

		foreach ( $ids as $id ) {
			$last_id = $id;
			$values  = self::fillable( $source, $id );
			if ( ! empty( $values ) ) {
				MetaStore::save( $id, $values );
				++$written;
			}
		}

		$total     = $source->count();
		$remaining = count( $source->target_ids( $last_id, 1 ) );

		return array(
			'processed' => count( $ids ),
			'written'   => $written,
			'last_id'   => $last_id,
			'remaining' => $remaining,
			'total'     => $total,
		);
	}

	/**
	 * Verify imported data against the source.
	 *
	 * @param Source $source Source adapter.
	 * @param int    $limit  Max posts to check (0 = all).
	 * @return array{checked:int,match:int,mismatch:int,sample:array<int,array<string,mixed>>}
	 */
	public static function verify( Source $source, $limit = 0 ) {
		$match    = 0;
		$mismatch = 0;
		$checked  = 0;
		$sample   = array();
		$after    = 0;
		$batch    = 200;

		while ( true ) {
			$ids = $source->target_ids( $after, $batch );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				++$checked;
				$after  = $id;
				$fields = $source->read( $id );
				foreach ( $fields as $field => $expected ) {
					$current = MetaStore::get( $id, $field );
					// We only fill empty fields, so a non-empty prior value is not a miss.
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
	 * @param Source $source Source adapter.
	 * @param int    $id     Post ID.
	 * @return array<string,string>
	 */
	private static function fillable( Source $source, $id ) {
		$out = array();
		foreach ( $source->read( $id ) as $field => $value ) {
			if ( '' === MetaStore::get( $id, $field ) ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Classify a single field for the dry-run diff.
	 *
	 * @param string $current Our current value.
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
