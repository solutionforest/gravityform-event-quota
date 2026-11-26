<?php
/**
 * Loads Active entries for one form and keeps the raw choice values.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFEQ_Usage {

	/**
	 * @param array    $form            Gravity Forms form.
	 * @param GF_Field[] $quota_fields  Choice fields that define a combination.
	 * @param GF_Field|null $quantity_field Number field used in number mode.
	 * @return array<int, array{values: array<string, string[]>, quantity: float}>
	 */
	public static function load( array $form, array $quota_fields, $quantity_field ) {
		global $wpdb;

		$form_id     = absint( rgar( $form, 'id' ) );
		$entry_table = self::table_name( GFFormsModel::get_entry_table_name() );
		$meta_table  = self::table_name( GFFormsModel::get_entry_meta_table_name() );
		if ( $form_id <= 0 || $entry_table === '' || $meta_table === '' ) {
			return array();
		}

		$entry_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payment_status FROM {$entry_table} WHERE form_id = %d AND status = 'active'",
				$form_id
			),
			ARRAY_A
		);

		$ids = array();
		foreach ( (array) $entry_rows as $row ) {
			if ( ! GFEQ_Calculator::entry_counts( 'active', $row['payment_status'] ) ) {
				continue;
			}
			$ids[] = (int) $row['id'];
		}

		if ( empty( $ids ) ) {
			return array();
		}

		$key_map = self::meta_key_map( $quota_fields, $quantity_field );
		if ( empty( $key_map ) ) {
			return array();
		}

		$grouped = array();
		foreach ( $ids as $id ) {
			$grouped[ $id ] = array(
				'values'   => array(),
				'quantity' => 0.0,
			);
		}

		foreach ( self::query_meta( $meta_table, $form_id, $ids, array_keys( $key_map ) ) as $meta ) {
			$entry_id = (int) $meta['entry_id'];
			if ( ! isset( $grouped[ $entry_id ] ) || ! isset( $key_map[ (string) $meta['meta_key'] ] ) ) {
				continue;
			}

			$target = $key_map[ (string) $meta['meta_key'] ];
			if ( $target['kind'] === 'quantity' ) {
				$grouped[ $entry_id ]['quantity'] = GFEQ_Calculator::normalize_quantity( $meta['meta_value'] );
				continue;
			}

			$raw = is_string( $meta['meta_value'] ) ? trim( $meta['meta_value'] ) : $meta['meta_value'];
			if ( $raw === '' || $raw === null ) {
				continue;
			}
			$grouped[ $entry_id ]['values'][ $target['field_id'] ][] = (string) $raw;
		}

		return array_values( $grouped );
	}

	/**
	 * @param GF_Field[]    $quota_fields
	 * @param GF_Field|null $quantity_field
	 * @return array<string, array{kind: string, field_id: string}>
	 */
	private static function meta_key_map( array $quota_fields, $quantity_field ) {
		$map = array();

		foreach ( $quota_fields as $field ) {
			$field_id = (string) $field->id;
			if ( $field->get_input_type() === 'checkbox' && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					if ( isset( $input['id'] ) ) {
						$map[ (string) $input['id'] ] = array(
							'kind'     => 'choice',
							'field_id' => $field_id,
						);
					}
				}
			}
			$map[ $field_id ] = array(
				'kind'     => 'choice',
				'field_id' => $field_id,
			);
		}

		if ( $quantity_field ) {
			$map[ (string) $quantity_field->id ] = array(
				'kind'     => 'quantity',
				'field_id' => (string) $quantity_field->id,
			);
		}

		return $map;
	}

	/**
	 * @param int[]    $ids
	 * @param string[] $keys
	 * @return array<int, array<string, mixed>>
	 */
	private static function query_meta( $table, $form_id, array $ids, array $keys ) {
		global $wpdb;

		$rows = array();
		foreach ( array_chunk( $ids, 500 ) as $id_chunk ) {
			$id_placeholders  = implode( ',', array_fill( 0, count( $id_chunk ), '%d' ) );
			$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$sql              = "SELECT entry_id, meta_key, meta_value FROM {$table} WHERE form_id = %d AND entry_id IN ({$id_placeholders}) AND meta_key IN ({$key_placeholders})";
			$params           = array_merge( array( (int) $form_id ), $id_chunk, $keys );
			$found            = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
			if ( is_array( $found ) ) {
				$rows = array_merge( $rows, $found );
			}
		}

		return $rows;
	}

	/**
	 * Gravity Forms table names are internal. Reject anything else before interpolation.
	 *
	 * @param string $name
	 */
	private static function table_name( $name ) {
		$name = (string) $name;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			return '';
		}
		return $name;
	}
}
