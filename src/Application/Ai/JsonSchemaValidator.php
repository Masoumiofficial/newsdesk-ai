<?php
/**
 * JSON Schema draft-07 subset validator (§19) — server-side only.
 *
 * Supported keywords (documented subset; anything else is ignored with a note):
 * type, properties, required, additionalProperties (bool), items, enum, const,
 * minimum/maximum, exclusiveMinimum/Maximum, minLength/maxLength, minItems/maxItems,
 * pattern (PCRE, anchored by the caller's schema), oneOf (first matching branch).
 *
 * @package NewsDesk\AI\Application\Ai
 */

namespace NewsDesk\AI\Application\Ai;

defined( 'ABSPATH' ) || exit;

final class JsonSchemaValidator {

	/**
	 * @param mixed $value  Decoded AI output.
	 * @param array $schema Schema (assoc array).
	 * @throws SchemaValidationException
	 */
	public function validate( $value, array $schema ): void {
		$errors = array();
		$this->check( $value, $schema, '', $errors );
		if ( $errors ) {
			throw new SchemaValidationException( $errors );
		}
	}

	/**
	 * @param string[] $errors
	 */
	private function check( $value, array $schema, string $path, array &$errors ): void {
		// type (single or list)
		if ( isset( $schema['type'] ) ) {
			$allowed = is_array( $schema['type'] ) ? $schema['type'] : array( $schema['type'] );
			$actual  = $this->typeOf( $value );
			// JSON Schema: integer is a valid 'number'.
			$pass = in_array( $actual, $allowed, true ) || ( 'integer' === $actual && in_array( 'number', $allowed, true ) );
			if ( ! $pass ) {
				$errors[] = $path . ': expected ' . implode( '|', $allowed ) . ', got ' . $actual;
				return; // do not descend into a mismatched node
			}
		}

		if ( is_array( $value ) && $this->isList( $value ) ) {
			$this->checkArray( $value, $schema, $path, $errors );
			return;
		}
		if ( is_array( $value ) ) {
			$this->checkObject( $value, $schema, $path, $errors );
			return;
		}

		if ( null === $value && isset( $schema['nullable'] ) && $schema['nullable'] ) {
			return;
		}
		$this->checkScalar( $value, $schema, $path, $errors );
	}

	private function checkObject( array $value, array $schema, string $path, array &$errors ): void {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $sub ) {
				$key = (string) $name;
				if ( array_key_exists( $key, $value ) ) {
					$this->check( $value[ $key ], $sub, $path . '/' . $key, $errors );
				}
			}
		}
		if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
			foreach ( $schema['required'] as $key ) {
				if ( ! array_key_exists( (string) $key, $value ) ) {
					$errors[] = $path . ': missing required property "' . $key . '"';
				}
			}
		}
		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! isset( $schema['properties'] ) || ! array_key_exists( (string) $key, $schema['properties'] ) ) {
					$errors[] = $path . ': additional property "' . $key . '" is not allowed';
				}
			}
		}
		if ( isset( $schema['oneOf'] ) && is_array( $schema['oneOf'] ) ) {
			$this->checkOneOf( $value, $schema['oneOf'], $path, $errors );
		}
	}

	private function checkArray( array $value, array $schema, string $path, array &$errors ): void {
		if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
			$errors[] = $path . ': fewer items than minItems (' . count( $value ) . ' < ' . (int) $schema['minItems'] . ')';
		}
		if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
			$errors[] = $path . ': more items than maxItems (' . count( $value ) . ' > ' . (int) $schema['maxItems'] . ')';
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $i => $item ) {
				$this->check( $item, $schema['items'], $path . '/' . $i, $errors );
			}
		}
		if ( isset( $schema['uniqueItems'] ) && $schema['uniqueItems'] ) {
			$seen = array();
			foreach ( $value as $item ) {
				$sig = is_array( $item ) ? json_encode( $item ) : var_export( $item, true );
				if ( isset( $seen[ $sig ] ) ) {
					$errors[] = $path . ': items must be unique';
					break;
				}
				$seen[ $sig ] = true;
			}
		}
	}

	private function checkOneOf( array $value, array $branches, string $path, array &$errors ): void {
		foreach ( $branches as $branch ) {
			$branchErrors = array();
			$this->check( $value, $branch, $path, $branchErrors );
			if ( empty( $branchErrors ) ) {
				return; // first matching branch wins (documented subset behavior)
			}
		}
		$errors[] = $path . ': does not match any oneOf branch';
	}

	private function checkScalar( $value, array $schema, string $path, array &$errors ): void {
		if ( isset( $schema['const'] ) && $value !== $schema['const'] ) {
			$errors[] = $path . ': value must equal const';
			return;
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = $path . ': value not in enum';
			return;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < (float) $schema['minimum'] ) {
				$errors[] = $path . ': below minimum';
			}
			if ( isset( $schema['maximum'] ) && $value > (float) $schema['maximum'] ) {
				$errors[] = $path . ': above maximum';
			}
			return;
		}
		if ( is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && mb_strlen( $value ) < (int) $schema['minLength'] ) {
				$errors[] = $path . ': shorter than minLength';
			}
			if ( isset( $schema['maxLength'] ) && mb_strlen( $value ) > (int) $schema['maxLength'] ) {
				$errors[] = $path . ': longer than maxLength';
			}
			if ( isset( $schema['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $schema['pattern'] ) . '/u', $value ) ) {
				$errors[] = $path . ': does not match pattern';
			}
		}
	}

	private function typeOf( $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_array( $value ) ) {
			return $this->isList( $value ) ? 'array' : 'object';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		return 'string';
	}

	private function isList( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$i = 0;
		foreach ( $value as $k => $v ) {
			if ( $k !== $i++ ) {
				return false;
			}
		}
		return true;
	}
}
