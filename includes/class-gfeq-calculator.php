<?php
/**
 * Pure quota matching. No WordPress or Gravity Forms calls.
 *
 * A rule matches when every choice in the rule is among the selected values.
 * Checkbox fields can match more than one rule. A choice is full when any
 * rule that the current selections determine, and that contains that choice,
 * cannot take the requested amount.
 */

if ( ! class_exists( 'GFEQ_Calculator' ) ) {

	class GFEQ_Calculator {

		const DEFAULT_FULL_MESSAGE = '(Full)';

		/**
		 * Payment statuses that free a seat. Pending, Paid, Processing and
		 * Authorized keep the seat. An empty status is a form without payment.
		 *
		 * @return string[]
		 */
		public static function released_payment_statuses() {
			return array( 'failed', 'refunded', 'voided', 'cancelled', 'canceled' );
		}

		/**
		 * Active entries hold a seat, including unpaid Pending payments.
		 * Trash, Spam and a released payment status do not.
		 *
		 * @param string $entry_status   Gravity Forms entry status.
		 * @param string $payment_status Payment add-on status, or empty.
		 */
		public static function entry_counts( $entry_status, $payment_status ) {
			if ( strtolower( trim( (string) $entry_status ) ) !== 'active' ) {
				return false;
			}

			$payment = strtolower( trim( (string) $payment_status ) );
			if ( $payment === '' ) {
				return true;
			}

			return ! in_array( $payment, self::released_payment_statuses(), true );
		}

		/**
		 * @param mixed $raw Number-field value.
		 */
		public static function normalize_quantity( $raw ) {
			if ( is_int( $raw ) || is_float( $raw ) ) {
				return $raw > 0 ? (float) $raw : 0.0;
			}

			$text = trim( (string) $raw );
			if ( $text === '' ) {
				return 0.0;
			}

			$text = str_replace( array( ',', ' ' ), '', $text );
			if ( ! is_numeric( $text ) ) {
				return 0.0;
			}

			$number = (float) $text;
			return $number > 0 ? $number : 0.0;
		}

		/**
		 * Stable identity for one combination. Field order does not matter.
		 *
		 * @param array<int|string, string> $choices Field id => choice value.
		 */
		public static function rule_key( array $choices ) {
			$normalized = array();
			foreach ( $choices as $field_id => $value ) {
				$normalized[ (string) (int) $field_id ] = (string) $value;
			}

			$ids = array_keys( $normalized );
			usort(
				$ids,
				static function ( $left, $right ) {
					return (int) $left <=> (int) $right;
				}
			);

			$ordered = array();
			foreach ( $ids as $id ) {
				$ordered[ $id ] = $normalized[ $id ];
			}

			return (string) json_encode( $ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		/**
		 * @param array<int, array{choices: array<string, string>, limit: float|null, full_message: string}> $rules
		 * @return array<string, array{choices: array<string, string>, limit: float|null, full_message: string}>
		 */
		public static function index_rules( array $rules ) {
			$indexed = array();
			foreach ( $rules as $rule ) {
				if ( empty( $rule['choices'] ) || ! is_array( $rule['choices'] ) ) {
					continue;
				}
				$indexed[ self::rule_key( $rule['choices'] ) ] = $rule;
			}
			return $indexed;
		}

		/**
		 * Seats already taken, keyed by combination.
		 *
		 * @param array<int, array{values: array<string, string[]>, quantity?: mixed}> $entries
		 * @param array<int, array{choices: array<string, string>, limit: float|null, full_message: string}> $rules
		 * @param string                                                                                      $count_mode entity|number
		 * @return array<string, float>
		 */
		public static function usage( array $entries, array $rules, $count_mode ) {
			$indexed = self::index_rules( $rules );
			$usage   = array();

			foreach ( $entries as $entry ) {
				$amount = ( $count_mode === 'number' ) ? self::normalize_quantity( isset( $entry['quantity'] ) ? $entry['quantity'] : 0 ) : 1.0;
				if ( $amount <= 0 ) {
					continue;
				}

				$values = isset( $entry['values'] ) && is_array( $entry['values'] ) ? $entry['values'] : array();
				foreach ( $indexed as $key => $rule ) {
					if ( ! self::values_cover_rule( $values, $rule['choices'] ) ) {
						continue;
					}
					if ( ! isset( $usage[ $key ] ) ) {
						$usage[ $key ] = 0.0;
					}
					$usage[ $key ] += $amount;
				}
			}

			return $usage;
		}

		/**
		 * @param array<string, string[]> $selections Field id => selected values. Incomplete fields are empty.
		 * @param mixed                   $quantity   Requested amount in number mode. Ignored in entity mode.
		 * @param array<string, array{choices: array<int, array{value: string, text: string}>}> $field_meta
		 * @return array<string, array<string, array{full: bool, message: string}>>
		 */
		public static function option_states( array $selections, $quantity, $count_mode, array $rules, array $usage, array $field_meta ) {
			$requested = ( $count_mode === 'number' ) ? self::normalize_quantity( $quantity ) : 1.0;
			$states    = array();

			foreach ( $field_meta as $field_id => $meta ) {
				$field_id            = (string) $field_id;
				$states[ $field_id ] = array();
				$choices             = isset( $meta['choices'] ) && is_array( $meta['choices'] ) ? $meta['choices'] : array();

				foreach ( $choices as $choice ) {
					$value = isset( $choice['value'] ) ? (string) $choice['value'] : '';
					if ( $value === '' ) {
						continue;
					}
					$result                        = self::choice_is_full( $field_id, $value, $selections, $requested, $count_mode, $rules, $usage );
					$states[ $field_id ][ $value ] = array(
						'full'    => $result['full'],
						'message' => $result['full'] ? $result['message'] : '',
					);
				}
			}

			return $states;
		}

		/**
		 * Selected choices that can no longer be submitted.
		 *
		 * @return array<int, array{field_id: string, value: string, message: string}>
		 */
		public static function violations( array $selections, $quantity, $count_mode, array $rules, array $usage, array $field_meta ) {
			$states     = self::option_states( $selections, $quantity, $count_mode, $rules, $usage, $field_meta );
			$violations = array();

			foreach ( $selections as $field_id => $values ) {
				$field_id = (string) $field_id;
				foreach ( (array) $values as $value ) {
					$value = (string) $value;
					if ( $value === '' || empty( $states[ $field_id ][ $value ]['full'] ) ) {
						continue;
					}
					$violations[] = array(
						'field_id' => $field_id,
						'value'    => $value,
						'message'  => $states[ $field_id ][ $value ]['message'],
					);
				}
			}

			return $violations;
		}

		/**
		 * @param array<string, string[]>   $values
		 * @param array<int|string, string> $choices
		 */
		private static function values_cover_rule( array $values, array $choices ) {
			foreach ( $choices as $field_id => $expected ) {
				$selected = isset( $values[ (string) $field_id ] ) ? $values[ (string) $field_id ] : array();
				$found    = false;
				foreach ( (array) $selected as $value ) {
					if ( (string) $value === (string) $expected ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * @return array{full: bool, message: string}
		 */
		private static function choice_is_full( $field_id, $choice_value, array $selections, $requested, $count_mode, array $rules, array $usage ) {
			foreach ( self::index_rules( $rules ) as $key => $rule ) {
				if ( ! self::rule_applies_to_choice( $rule['choices'], $field_id, $choice_value, $selections ) ) {
					continue;
				}

				$limit = self::limit_value( $rule );
				if ( $limit === null ) {
					continue;
				}

				$used      = isset( $usage[ $key ] ) ? (float) $usage[ $key ] : 0.0;
				$remaining = $limit - $used;
				$message   = self::full_message( $rule );

				if ( $remaining <= 0.000001 ) {
					return array(
						'full'    => true,
						'message' => $message,
					);
				}

				// A blank quantity has not asked for seats yet. Filled combinations still block above.
				if ( $count_mode === 'number' && $requested <= 0 ) {
					continue;
				}

				if ( $requested - $remaining > 0.000001 ) {
					return array(
						'full'    => true,
						'message' => $message,
					);
				}
			}

			return array(
				'full'    => false,
				'message' => '',
			);
		}

		/**
		 * The rule is determined when every other field is already selected
		 * and this choice is the rule's value for the field being tested.
		 *
		 * @param array<int|string, string> $choices
		 * @param array<string, string[]>   $selections
		 */
		private static function rule_applies_to_choice( array $choices, $field_id, $choice_value, array $selections ) {
			$matched_field = false;

			foreach ( $choices as $id => $expected ) {
				if ( (string) (int) $id === (string) (int) $field_id ) {
					if ( (string) $expected !== (string) $choice_value ) {
						return false;
					}
					$matched_field = true;
					continue;
				}

				$selected = isset( $selections[ (string) (int) $id ] ) ? $selections[ (string) (int) $id ] : array();
				$found    = false;
				foreach ( (array) $selected as $value ) {
					if ( (string) $value === (string) $expected ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					return false;
				}
			}

			return $matched_field;
		}

		/**
		 * @param array{limit?: float|null|string} $rule
		 * @return float|null Null means the combination is unlimited.
		 */
		private static function limit_value( array $rule ) {
			if ( ! array_key_exists( 'limit', $rule ) || $rule['limit'] === null || $rule['limit'] === '' ) {
				return null;
			}
			return (float) $rule['limit'];
		}

		/**
		 * @param array{full_message?: string} $rule
		 */
		private static function full_message( array $rule ) {
			$message = isset( $rule['full_message'] ) ? trim( (string) $rule['full_message'] ) : '';
			return $message === '' ? self::DEFAULT_FULL_MESSAGE : $message;
		}
	}
}
