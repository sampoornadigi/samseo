<?php
/**
 * Reversible deployment of SEO meta changes from the control plane.
 *
 * Every applied change is journaled with its prior value (Database changes
 * table), so a deployment can be rolled back to the exact prior state. Rollback
 * is guarded: a change is only reverted if the live value still matches what we
 * deployed — a human edit since deploy is never clobbered. Apply is idempotent
 * per deploy_id.
 *
 * @package Sampoorna\SEO
 */

namespace Sampoorna\SEO\ControlPlane;

use Sampoorna\SEO\Core\Database;
use Sampoorna\SEO\Meta\MetaStore;
use Sampoorna\SEO\Meta\TermMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies and reverts control-plane changesets.
 */
class Deploy {

	/**
	 * Apply a changeset, journaling prior values. Idempotent per deploy_id.
	 *
	 * @param string                         $deploy_id Deployment ID.
	 * @param array<int,array<string,mixed>> $changes   List of { type, id, field, value }.
	 * @return array<string,mixed>
	 */
	public static function apply( $deploy_id, array $changes ) {
		$deploy_id = (string) $deploy_id;
		if ( '' === $deploy_id ) {
			return array(
				'error'   => 'missing_deploy_id',
				'applied' => 0,
			);
		}
		// Idempotent: re-applying an existing deployment is a no-op.
		if ( Database::deploy_exists( $deploy_id ) ) {
			return array(
				'deploy_id'  => $deploy_id,
				'applied'    => 0,
				'idempotent' => true,
			);
		}

		$fields  = MetaStore::fields();
		$applied = 0;
		foreach ( $changes as $change ) {
			$type  = isset( $change['type'] ) ? (string) $change['type'] : 'post';
			$id    = isset( $change['id'] ) ? (int) $change['id'] : 0;
			$field = isset( $change['field'] ) ? (string) $change['field'] : '';
			$value = isset( $change['value'] ) ? (string) $change['value'] : '';

			if ( 'option' === $type ) {
				// Site-level setting (config templating): no object id, allow-listed field.
				if ( ! Settings::has( $field ) ) {
					continue;
				}
				$id = 0;
			} elseif ( in_array( $type, array( 'post', 'term' ), true ) ) {
				if ( $id <= 0 || ! isset( $fields[ $field ] ) ) {
					continue;
				}
			} else {
				continue;
			}

			$old = self::read( $type, $id, $field );
			self::write( $type, $id, $field, $value );
			Database::record_change(
				array(
					'deploy_id'   => $deploy_id,
					'object_type' => $type,
					'object_id'   => $id,
					'field'       => $field,
					'old_value'   => $old,
					'new_value'   => self::read( $type, $id, $field ),
				)
			);
			++$applied;
		}

		return array(
			'deploy_id' => $deploy_id,
			'applied'   => $applied,
		);
	}

	/**
	 * Roll a deployment back to its prior state (guarded against later edits).
	 *
	 * @param string $deploy_id Deployment ID.
	 * @return array<string,mixed>
	 */
	public static function rollback( $deploy_id ) {
		$rows     = Database::changes_for_deploy( (string) $deploy_id, 'applied' );
		$restored = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$type  = (string) $row['object_type'];
			$id    = (int) $row['object_id'];
			$field = (string) $row['field'];

			// Only revert if the live value is still what we deployed.
			if ( self::read( $type, $id, $field ) !== (string) $row['new_value'] ) {
				++$skipped;
				continue;
			}
			self::write( $type, $id, $field, (string) $row['old_value'] );
			Database::set_change_status( (int) $row['id'], 'rolled_back' );
			++$restored;
		}

		return array(
			'deploy_id' => (string) $deploy_id,
			'restored'  => $restored,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Read a meta field for a post or term.
	 *
	 * @param string $type  post|term.
	 * @param int    $id    Object ID.
	 * @param string $field Logical field.
	 * @return string
	 */
	private static function read( $type, $id, $field ) {
		if ( 'term' === $type ) {
			return TermMeta::get( $id, $field );
		}
		if ( 'option' === $type ) {
			return Settings::read( $field );
		}
		return MetaStore::get( $id, $field );
	}

	/**
	 * Write a meta field for a post or term.
	 *
	 * @param string $type  post|term.
	 * @param int    $id    Object ID.
	 * @param string $field Logical field.
	 * @param string $value Value.
	 * @return void
	 */
	private static function write( $type, $id, $field, $value ) {
		if ( 'term' === $type ) {
			TermMeta::save( $id, array( $field => $value ) );
		} elseif ( 'option' === $type ) {
			Settings::write( $field, $value );
		} else {
			MetaStore::save( $id, array( $field => $value ) );
		}
	}
}
