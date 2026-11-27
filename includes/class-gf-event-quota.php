<?php
/**
 * Gravity Forms form-settings, entry counting, and choice blocking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_Event_Quota extends GFAddOn {

	const MAX_RULES = 500;

	protected $_version                  = GFEQ_VERSION;
	protected $_min_gravityforms_version = '2.6';
	protected $_slug                     = 'gf-event-quota';
	protected $_path                     = 'gravityform-event-quota/gravityform-event-quota.php';
	protected $_full_path                = GFEQ_FILE;
	protected $_title                    = 'Event Quota for Gravity Forms';
	protected $_short_title              = 'Quota';
	protected $_capabilities_form_settings = 'gravityforms_edit_forms';

	/**
	 * @var GF_Event_Quota|null
	 */
	private static $_instance = null;

	/**
	 * Parsed config for the current request, without entry totals.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private $config_cache = array();

	/**
	 * Choice display state for the form currently being rendered.
	 * [form_id][field_id][choice_value] => label, full, message.
	 *
	 * @var array<int, array<string, array<string, array<string, mixed>>>>
	 */
	private $display_states = array();

	/**
	 * MySQL lock held until the entry is stored.
	 *
	 * @var string
	 */
	private $lock_name = '';

	/**
	 * @return GF_Event_Quota
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	public function init_admin() {
		parent::init_admin();
		$this->register_runtime_hooks();
	}

	public function init_frontend() {
		parent::init_frontend();
		$this->register_runtime_hooks();
	}

	public function init_ajax() {
		parent::init_ajax();
		$this->register_runtime_hooks();
	}

	/**
	 * AJAX form posts and the public form do not share an init path.
	 */
	private function register_runtime_hooks() {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_filter( 'gform_pre_render', array( $this, 'prepare_display' ), 20 );
		add_filter( 'gform_field_choice_markup_pre_render', array( $this, 'filter_choice_markup' ), 10, 4 );
		add_filter( 'gform_validation', array( $this, 'validate_quota' ), 50 );
		add_action( 'wp_ajax_gfeq_check_quota', array( $this, 'ajax_check_quota' ) );
		add_action( 'wp_ajax_nopriv_gfeq_check_quota', array( $this, 'ajax_check_quota' ) );
		add_action( 'gform_entry_created', array( $this, 'release_lock' ), 1, 2 );
		add_action( 'shutdown', array( $this, 'release_lock' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function scripts() {
		return array(
			array(
				'handle'    => 'gfeq-admin',
				'src'       => GFEQ_URL . 'assets/js/admin.js',
				'version'   => $this->_version,
				'deps'      => array(),
				'in_footer' => true,
				'callback'  => array( $this, 'localize_admin_script' ),
				'enqueue'   => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
			array(
				'handle'    => 'gfeq-frontend',
				'src'       => GFEQ_URL . 'assets/js/frontend.js',
				'version'   => $this->_version,
				'deps'      => array( 'jquery' ),
				'in_footer' => true,
				'callback'  => array( $this, 'localize_frontend_script' ),
				'enqueue'   => array(
					array( $this, 'should_enqueue_frontend' ),
				),
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function styles() {
		return array(
			array(
				'handle'  => 'gfeq-admin',
				'src'     => GFEQ_URL . 'assets/css/admin.css',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
			array(
				'handle'  => 'gfeq-frontend',
				'src'     => GFEQ_URL . 'assets/css/frontend.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( $this, 'should_enqueue_frontend' ),
				),
			),
		);
	}

	/**
	 * @param array $form
	 * @param bool  $is_ajax
	 */
	public function should_enqueue_frontend( $form, $is_ajax ) {
		unset( $is_ajax );
		if ( is_admin() && ! wp_doing_ajax() && ! $this->is_preview() ) {
			return false;
		}

		return (bool) $this->config( $form );
	}

	/**
	 * @param array $form
	 * @param bool  $is_ajax
	 */
	public function localize_admin_script( $form = array(), $is_ajax = false ) {
		unset( $is_ajax );
		if ( empty( $form['id'] ) ) {
			$form = $this->get_current_form();
		}
		if ( empty( $form['id'] ) ) {
			return;
		}

		wp_localize_script( 'gfeq-admin', 'gfeqAdmin', $this->admin_config( $form ) );
	}

	/**
	 * @param array $form
	 * @param bool  $is_ajax
	 */
	public function localize_frontend_script( $form = array(), $is_ajax = false ) {
		unset( $is_ajax );
		$config = $this->config( $form );
		if ( ! $config ) {
			return;
		}

		$fields = array();
		foreach ( $config['fields'] as $field ) {
			$fields[] = array(
				'id'        => (int) $field->id,
				'inputType' => $field->get_input_type(),
			);
		}

		$payload = array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'gfeq_check_' . absint( $form['id'] ) ),
			'fields'          => $fields,
			'quantityFieldId' => $config['quantity_field'] ? (int) $config['quantity_field']->id : 0,
		);

		wp_add_inline_script(
			'gfeq-frontend',
			'window.gfeqForms = window.gfeqForms || {}; window.gfeqForms[' . absint( $form['id'] ) . '] = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
	}

	/**
	 * @param array $form
	 * @return array<int, array<string, mixed>>
	 */
	public function form_settings_fields( $form ) {
		$field_choices = array();
		foreach ( $this->choice_fields( $form ) as $field ) {
			$label = trim( wp_strip_all_tags( (string) $field->label ) );
			if ( $label === '' ) {
				$label = 'Field ' . $field->id;
			}
			$field_choices[] = array(
				'name'  => (string) $field->id,
				'label' => $label . ' (' . $this->field_type_label( $field ) . ')',
			);
		}

		return array(
			array(
				'title'       => 'Event Quota for Gravity Forms',
				'description' => 'Build a quota from the choices of several fields. For example, Date = 11/28 and Time = Morning. The form checks the quota whenever those choices change. A full choice is disabled and its label gets the full message. Only Active entries count. Unpaid Pending payments keep a seat. Trash, Spam, failed, refunded, and cancelled payments do not.',
				'fields'      => array(
					array(
						'name'    => 'enabled',
						'label'   => 'Enable',
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'enabled',
								'label' => 'Enable quota for this form',
							),
						),
					),
					array(
						'name'        => 'quota_fields',
						'label'       => 'Quota fields',
						'type'        => 'checkbox',
						'data_format' => 'array',
						'choices'     => $field_choices,
						'description' => 'Drop Down, Radio, and Checkbox. Each checked checkbox option uses its own combination.',
					),
					array(
						'name'          => 'count_mode',
						'label'         => 'Count',
						'type'          => 'radio',
						'default_value' => 'entity',
						'horizontal'    => true,
						'choices'       => array(
							array(
								'value' => 'entity',
								'label' => 'Subtract 1 for each selected combination',
							),
							array(
								'value' => 'number',
								'label' => 'Subtract the number field value for each selected combination',
							),
						),
					),
					array(
						'name'       => 'quantity_field',
						'label'      => 'Number field',
						'type'       => 'field_select',
						'args'       => array(
							'input_types' => array( 'number', 'quantity' ),
						),
						'dependency' => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'count_mode',
									'values' => array( 'number' ),
								),
							),
						),
						'description' => 'Make this field required. If the number is larger than the seats left, that option is marked full and disabled.',
					),
					array(
						'name'  => 'rules',
						'label' => '',
						'type'  => 'hidden',
					),
					array(
						'name'  => 'rules_editor',
						'label' => 'Combination limits',
						'type'  => 'html',
						'html'  => '<p class="description">Leave the limit blank for unlimited. 0 means full. The full message is appended to the choice label, for example "Morning ' . esc_html( $this->default_full_message() ) . '".</p><div id="gfeq-rules-root"></div>',
					),
				),
			),
		);
	}

	/**
	 * @param array $form
	 * @param array $settings
	 * @return bool
	 */
	public function save_form_settings( $form, $settings ) {
		$existing_meta = GFFormsModel::get_form_meta( $form['id'] );
		if ( is_array( $existing_meta ) ) {
			$form = $existing_meta;
		}

		$settings          = $this->prepare_settings_for_save( $form, $settings );
		$existing_settings = rgar( $form, $this->get_slug() );
		if ( is_array( $existing_settings ) ) {
			$settings = array_merge( $existing_settings, $settings );
		}
		unset( $settings['rules_editor'], $settings[''] );

		$form[ $this->get_slug() ] = $settings;
		$this->config_cache        = array();

		return false !== GFFormsModel::update_form_meta( $form['id'], $form );
	}

	/**
	 * Mark choices that are already full before the browser runs its check.
	 *
	 * @param array $form
	 * @return array
	 */
	public function prepare_display( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}

		$form_id = (int) $form['id'];
		unset( $this->display_states[ $form_id ] );

		if ( GFCommon::is_form_editor() || GFCommon::is_entry_detail() ) {
			return $form;
		}

		$config = $this->config( $form );
		if ( ! $config ) {
			return $form;
		}

		$states = GFEQ_Calculator::option_states(
			$this->current_selections( $form, $config['fields'] ),
			$this->current_quantity( $form, $config ),
			$config['count_mode'],
			$config['rules'],
			$this->usage_for( $form, $config ),
			$config['field_meta']
		);

		foreach ( $config['fields'] as $field ) {
			$field_id = (string) $field->id;
			foreach ( $config['field_meta'][ $field_id ]['choices'] as $choice ) {
				$value = $choice['value'];
				$state = isset( $states[ $field_id ][ $value ] ) ? $states[ $field_id ][ $value ] : array();
				$this->display_states[ $form_id ][ $field_id ][ $value ] = array(
					'label'   => $choice['text'],
					'full'    => ! empty( $state['full'] ),
					'message' => empty( $state['full'] ) ? '' : $this->translate_full_message( isset( $state['message'] ) ? $state['message'] : '' ),
				);
			}
		}

		return $form;
	}

	/**
	 * @param string   $html
	 * @param array    $choice
	 * @param GF_Field $field
	 * @param string   $value
	 * @return string
	 */
	public function filter_choice_markup( $html, $choice, $field, $value ) {
		unset( $value );
		if ( ! is_object( $field ) || ! is_array( $choice ) ) {
			return $html;
		}

		$resolved = $this->resolve_choice_value( $field, $choice );
		$form_id  = (int) $field->formId;
		$field_id = (string) $field->id;
		if ( $resolved === '' || ! isset( $this->display_states[ $form_id ][ $field_id ][ $resolved ] ) ) {
			return $html;
		}

		$state = $this->display_states[ $form_id ][ $field_id ][ $resolved ];
		$attr  = ' data-gfeq-label="' . esc_attr( $state['label'] ) . '"';
		if ( ! empty( $state['full'] ) ) {
			$attr .= ' disabled="disabled"';
		}

		$html = $this->inject_tag_attributes( $html, $attr );
		if ( ! empty( $state['full'] ) && $state['message'] !== '' ) {
			$html = $this->append_visible_label( $html, $state['label'], $state['message'] );
		}

		return $html;
	}

	/**
	 * @param array $result
	 * @return array
	 */
	public function validate_quota( $result ) {
		if ( empty( $result['form'] ) || ! is_array( $result['form'] ) ) {
			return $result;
		}

		$form = $result['form'];
		if ( rgpost( 'gform_save' ) || ! $this->is_final_submission( $form ) ) {
			return $result;
		}

		$config = $this->config( $form );
		if ( ! $config ) {
			return $result;
		}

		if ( ! $this->acquire_lock( $form['id'] ) ) {
			return $this->fail_fields(
				$result,
				array(
					(string) $config['fields'][0]->id => array( 'Quota is updating. Please submit again.' ),
				)
			);
		}

		$violations = GFEQ_Calculator::violations(
			$this->current_selections( $form, $config['fields'] ),
			$this->current_quantity( $form, $config ),
			$config['count_mode'],
			$config['rules'],
			$this->usage_for( $form, $config ),
			$config['field_meta']
		);

		if ( ! empty( $violations ) ) {
			$this->release_lock();
			return $this->fail_fields( $result, $this->messages_by_field( $config, $violations ) );
		}

		if ( empty( $result['is_valid'] ) ) {
			$this->release_lock();
		}

		return $result;
	}

	public function ajax_check_quota() {
		$form_id = absint( rgpost( 'form_id' ) );
		check_ajax_referer( 'gfeq_check_' . $form_id, 'nonce' );

		$form = GFAPI::get_form( $form_id );
		if ( empty( $form ) ) {
			wp_send_json_error( array( 'message' => 'Form not found.' ), 404 );
		}

		$config = $this->config( $form );
		if ( ! $config ) {
			wp_send_json_success( array( 'options' => array() ) );
		}

		$states = GFEQ_Calculator::option_states(
			$this->sanitize_posted_selections( $config, rgpost( 'selections' ) ),
			rgpost( 'quantity' ),
			$config['count_mode'],
			$config['rules'],
			$this->usage_for( $form, $config ),
			$config['field_meta']
		);

		wp_send_json_success( array( 'options' => $this->translate_option_states( $states ) ) );
	}

	/**
	 * @param mixed ...$unused Entry hooks pass the entry through; shutdown does not.
	 */
	public function release_lock( ...$unused ) {
		unset( $unused );
		if ( $this->lock_name === '' ) {
			return;
		}

		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name ) );
		$this->lock_name = '';
	}

	/**
	 * @param array $form
	 * @return array<string, mixed>|null
	 */
	private function config( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return null;
		}

		$form_id = (int) $form['id'];
		if ( array_key_exists( $form_id, $this->config_cache ) ) {
			return $this->config_cache[ $form_id ];
		}

		$settings = $this->get_form_settings( $form );
		if ( ! is_array( $settings ) || (string) rgar( $settings, 'enabled' ) !== '1' ) {
			$this->config_cache[ $form_id ] = null;
			return null;
		}

		$fields = $this->selected_quota_fields( $form, $settings );
		$rules  = $this->sanitize_rules( isset( $settings['rules'] ) && is_array( $settings['rules'] ) ? $settings['rules'] : array(), $form, wp_list_pluck( $fields, 'id' ) );
		if ( empty( $fields ) || empty( $rules ) ) {
			$this->config_cache[ $form_id ] = null;
			return null;
		}

		$count_mode     = ( isset( $settings['count_mode'] ) && $settings['count_mode'] === 'number' ) ? 'number' : 'entity';
		$quantity_field = null;
		if ( $count_mode === 'number' ) {
			foreach ( $this->number_fields( $form ) as $field ) {
				if ( (string) $field->id === (string) rgar( $settings, 'quantity_field' ) ) {
					$quantity_field = $field;
					break;
				}
			}
			if ( ! $quantity_field ) {
				$count_mode = 'entity';
			}
		}

		$runtime_rules = array();
		foreach ( $rules as $rule ) {
			$runtime_rules[] = array(
				'choices'      => $rule['choices'],
				'limit'        => ( $rule['limit'] === '' || $rule['limit'] === null ) ? null : (float) $rule['limit'],
				'full_message' => $rule['full_message'],
			);
		}

		$this->config_cache[ $form_id ] = array(
			'fields'          => $fields,
			'rules'           => $runtime_rules,
			'count_mode'      => $count_mode,
			'quantity_field'  => $quantity_field,
			'field_meta'      => $this->field_meta( $fields ),
		);

		return $this->config_cache[ $form_id ];
	}

	/**
	 * @param array $form
	 * @param array $config
	 * @return array<string, float>
	 */
	private function usage_for( $form, array $config ) {
		$entries = array();
		foreach ( GFEQ_Usage::load( $form, $config['fields'], $config['quantity_field'] ) as $entry ) {
			$values = array();
			foreach ( $config['fields'] as $field ) {
				$field_id             = (string) $field->id;
				$incoming             = isset( $entry['values'][ $field_id ] ) ? $entry['values'][ $field_id ] : array();
				$values[ $field_id ]  = $this->normalize_list( $field, $incoming );
			}
			$entries[] = array(
				'values'   => $values,
				'quantity' => isset( $entry['quantity'] ) ? $entry['quantity'] : 0,
			);
		}

		return GFEQ_Calculator::usage( $entries, $config['rules'], $config['count_mode'] );
	}

	/**
	 * @param array $form
	 * @return array<string, mixed>
	 */
	private function admin_config( $form ) {
		$settings = $this->get_form_settings( $form );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$fields = array();
		foreach ( $this->choice_fields( $form ) as $field ) {
			$label = trim( wp_strip_all_tags( (string) $field->label ) );
			$fields[] = array(
				'id'      => (string) $field->id,
				'label'   => $label !== '' ? $label : ( 'Field ' . $field->id ),
				'choices' => $this->choice_definitions( $field ),
			);
		}

		return array(
			'fields'           => $fields,
			'rules'            => isset( $settings['rules'] ) && is_array( $settings['rules'] ) ? array_values( $settings['rules'] ) : array(),
			'defaultMessage'   => $this->default_full_message(),
			'maxCombinations'  => self::MAX_RULES,
		);
	}

	/**
	 * @param array $form
	 * @param array $settings
	 * @return array
	 */
	private function prepare_settings_for_save( $form, $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['enabled']    = ( isset( $settings['enabled'] ) && (string) $settings['enabled'] === '1' ) ? '1' : '0';
		$settings['count_mode'] = ( isset( $settings['count_mode'] ) && $settings['count_mode'] === 'number' ) ? 'number' : 'entity';

		$allowed = array();
		foreach ( $this->choice_fields( $form ) as $field ) {
			$allowed[] = (int) $field->id;
		}

		$selected = array();
		foreach ( (array) rgar( $settings, 'quota_fields' ) as $field_id ) {
			$field_id = absint( $field_id );
			if ( in_array( $field_id, $allowed, true ) ) {
				$selected[] = $field_id;
			}
		}
		$settings['quota_fields'] = array_values( array_unique( $selected ) );

		$quantity_id = absint( rgar( $settings, 'quantity_field' ) );
		$quantity_ok = false;
		foreach ( $this->number_fields( $form ) as $field ) {
			if ( (int) $field->id === $quantity_id ) {
				$quantity_ok = true;
				break;
			}
		}
		$settings['quantity_field'] = $quantity_ok ? (string) $quantity_id : '';

		$rules = rgar( $settings, 'rules' );
		if ( is_string( $rules ) ) {
			$decoded = json_decode( wp_unslash( $rules ), true );
			$rules   = is_array( $decoded ) ? $decoded : array();
		}
		$settings['rules'] = $this->sanitize_rules( is_array( $rules ) ? $rules : array(), $form, $settings['quota_fields'] );

		return $settings;
	}

	/**
	 * @param array    $rules
	 * @param array    $form
	 * @param int[]    $field_ids
	 * @return array<int, array{choices: array<string, string>, limit: string, full_message: string}>
	 */
	private function sanitize_rules( array $rules, $form, array $field_ids ) {
		$expected = array_map( 'strval', array_map( 'absint', $field_ids ) );
		sort( $expected, SORT_NATURAL );

		$definitions = array();
		foreach ( $this->choice_fields( $form ) as $field ) {
			$field_id = (string) $field->id;
			if ( in_array( $field_id, $expected, true ) ) {
				$definitions[ $field_id ] = $this->choice_definitions( $field );
			}
		}

		$clean = array();
		$seen  = array();
		foreach ( $rules as $rule ) {
			if ( count( $clean ) >= self::MAX_RULES ) {
				break;
			}
			if ( ! is_array( $rule ) || empty( $rule['choices'] ) || ! is_array( $rule['choices'] ) ) {
				continue;
			}

			$choices = array();
			foreach ( $rule['choices'] as $field_id => $value ) {
				$field_id = (string) absint( $field_id );
				$value    = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( $value === '' || ! isset( $definitions[ $field_id ] ) ) {
					continue;
				}
				foreach ( $definitions[ $field_id ] as $choice ) {
					if ( $choice['value'] === $value ) {
						$choices[ $field_id ] = $value;
						break;
					}
				}
			}

			// PHP casts numeric string keys such as "1" to integers, so compare both sides as strings.
			$ids = array_map( 'strval', array_keys( $choices ) );
			sort( $ids, SORT_NATURAL );
			if ( $ids !== $expected ) {
				continue;
			}

			$key = GFEQ_Calculator::rule_key( $choices );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$limit_raw = isset( $rule['limit'] ) && is_scalar( $rule['limit'] ) ? trim( (string) $rule['limit'] ) : '';
			if ( $limit_raw === '' ) {
				$limit = '';
			} elseif ( is_numeric( $limit_raw ) && (float) $limit_raw >= 0 ) {
				$limit = ( (float) $limit_raw == (int) $limit_raw ) ? (string) (int) $limit_raw : (string) (float) $limit_raw;
			} else {
				continue;
			}

			$message = isset( $rule['full_message'] ) ? sanitize_text_field( (string) $rule['full_message'] ) : '';
			$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 80 ) : substr( $message, 0, 80 );
			if ( $message === '' ) {
				$message = GFEQ_Calculator::DEFAULT_FULL_MESSAGE;
			}

			$clean[] = array(
				'choices'      => $choices,
				'limit'        => $limit,
				'full_message' => $message,
			);
		}

		return $clean;
	}

	/**
	 * @param array $form
	 * @return GF_Field[]
	 */
	private function choice_fields( $form ) {
		$fields = array();
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $fields;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) || ! empty( $field->displayOnly ) ) {
				continue;
			}
			if ( isset( $field->visibility ) && $field->visibility === 'administrative' ) {
				continue;
			}
			$type = $field->get_input_type();
			if ( ! in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				continue;
			}
			if ( empty( $field->choices ) || ! is_array( $field->choices ) ) {
				continue;
			}
			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * @param array $form
	 * @return GF_Field[]
	 */
	private function number_fields( $form ) {
		$fields = array();
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $fields;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			if ( in_array( $field->get_input_type(), array( 'number', 'quantity' ), true ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param array $form
	 * @param array $settings
	 * @return GF_Field[]
	 */
	private function selected_quota_fields( $form, array $settings ) {
		$wanted = array();
		foreach ( (array) rgar( $settings, 'quota_fields' ) as $field_id ) {
			$wanted[ (string) absint( $field_id ) ] = true;
		}

		$selected = array();
		foreach ( $this->choice_fields( $form ) as $field ) {
			if ( isset( $wanted[ (string) $field->id ] ) ) {
				$selected[] = $field;
			}
		}

		return $selected;
	}

	/**
	 * @param GF_Field[] $fields
	 * @return array<string, array{choices: array<int, array{value: string, text: string}>}>
	 */
	private function field_meta( array $fields ) {
		$meta = array();
		foreach ( $fields as $field ) {
			$meta[ (string) $field->id ] = array(
				'choices' => $this->choice_definitions( $field ),
			);
		}
		return $meta;
	}

	/**
	 * @param GF_Field $field
	 * @return array<int, array{value: string, text: string}>
	 */
	private function choice_definitions( $field ) {
		$definitions = array();
		$seen        = array();
		foreach ( (array) $field->choices as $choice ) {
			$value = $this->resolve_choice_value( $field, $choice );
			if ( $value === '' || isset( $seen[ $value ] ) ) {
				continue;
			}
			$seen[ $value ] = true;
			$text           = isset( $choice['text'] ) ? (string) $choice['text'] : '';
			$definitions[]  = array(
				'value' => $value,
				'text'  => $text !== '' ? $text : $value,
			);
		}
		return $definitions;
	}

	/**
	 * @param GF_Field $field
	 * @param array    $choice
	 */
	private function resolve_choice_value( $field, $choice ) {
		$value = isset( $choice['value'] ) ? trim( (string) $choice['value'] ) : '';
		$text  = isset( $choice['text'] ) ? trim( (string) $choice['text'] ) : '';
		if ( $value === '' && empty( $field->enableChoiceValue ) ) {
			$value = $text;
		}
		return $value;
	}

	/**
	 * @param GF_Field $field
	 */
	private function field_type_label( $field ) {
		$type = $field->get_input_type();
		if ( $type === 'select' ) {
			return 'Drop Down';
		}
		if ( $type === 'radio' ) {
			return 'Radio';
		}
		if ( $type === 'checkbox' ) {
			return 'Checkbox';
		}
		return $type;
	}

	/**
	 * @param array      $form
	 * @param GF_Field[] $fields
	 * @return array<string, string[]>
	 */
	private function current_selections( $form, array $fields ) {
		$is_post    = (int) rgpost( 'gform_submit' ) === (int) $form['id'];
		$selections = array();

		foreach ( $fields as $field ) {
			$field_id = (string) $field->id;
			if ( $this->field_is_hidden( $form, $field ) ) {
				$selections[ $field_id ] = array();
				continue;
			}
			if ( $is_post ) {
				$selections[ $field_id ] = $this->posted_choice_values( $field );
			} else {
				$selections[ $field_id ] = $this->default_choice_values( $field );
			}
		}

		return $selections;
	}

	/**
	 * @param array $form
	 * @param array $config
	 * @return mixed
	 */
	private function current_quantity( $form, array $config ) {
		if ( $config['count_mode'] !== 'number' || empty( $config['quantity_field'] ) ) {
			return 0;
		}

		$field = $config['quantity_field'];
		if ( $this->field_is_hidden( $form, $field ) ) {
			return 0;
		}
		if ( (int) rgpost( 'gform_submit' ) === (int) $form['id'] ) {
			return rgpost( 'input_' . $field->id );
		}

		return $field->defaultValue;
	}

	/**
	 * @param GF_Field $field
	 * @return string[]
	 */
	private function posted_choice_values( $field ) {
		$raw_values = array();
		if ( $field->get_input_type() === 'checkbox' && is_array( $field->inputs ) ) {
			foreach ( $field->inputs as $input ) {
				if ( isset( $input['id'] ) ) {
					$raw_values[] = rgpost( 'input_' . str_replace( '.', '_', (string) $input['id'] ) );
				}
			}
		} else {
			$raw_values[] = rgpost( 'input_' . $field->id );
		}

		return $this->normalize_list( $field, $raw_values );
	}

	/**
	 * @param GF_Field $field
	 * @return string[]
	 */
	private function default_choice_values( $field ) {
		$values = array();
		foreach ( (array) $field->choices as $choice ) {
			if ( empty( $choice['isSelected'] ) ) {
				continue;
			}
			$value = $this->resolve_choice_value( $field, $choice );
			if ( $value !== '' ) {
				$values[] = $value;
			}
		}

		if ( empty( $values ) && $field->get_input_type() !== 'checkbox' && isset( $field->defaultValue ) && $field->defaultValue !== '' ) {
			$normalized = $this->normalize_submitted_value( $field, $field->defaultValue );
			if ( $normalized !== '' ) {
				$values[] = $normalized;
			}
		}

		return $this->normalize_list( $field, $values );
	}

	/**
	 * @param GF_Field $field
	 * @param array    $raw_values
	 * @return string[]
	 */
	private function normalize_list( $field, array $raw_values ) {
		$values = array();
		foreach ( $raw_values as $raw ) {
			$normalized = $this->normalize_submitted_value( $field, $raw );
			if ( $normalized !== '' ) {
				$values[] = $normalized;
			}
		}
		$values = array_values( array_unique( $values ) );
		if ( $field->get_input_type() !== 'checkbox' ) {
			$values = array_slice( $values, 0, 1 );
		}
		return $values;
	}

	/**
	 * @param GF_Field $field
	 * @param mixed    $raw
	 */
	private function normalize_submitted_value( $field, $raw ) {
		if ( is_array( $raw ) || $raw === null ) {
			return '';
		}
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}

		$definitions = $this->choice_definitions( $field );
		foreach ( $definitions as $choice ) {
			if ( $raw === $choice['value'] ) {
				return $choice['value'];
			}
		}

		$pipe = strpos( $raw, '|' );
		if ( $pipe !== false ) {
			$left = substr( $raw, 0, $pipe );
			foreach ( $definitions as $choice ) {
				if ( $left === $choice['value'] ) {
					return $choice['value'];
				}
			}
		}

		return '';
	}

	/**
	 * @param array                $config
	 * @param array<string, mixed> $raw
	 * @return array<string, string[]>
	 */
	private function sanitize_posted_selections( array $config, $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$selections = array();
		foreach ( $config['fields'] as $field ) {
			$field_id = (string) $field->id;
			$incoming = array();
			if ( isset( $raw[ $field_id ] ) ) {
				$incoming = (array) $raw[ $field_id ];
			} elseif ( isset( $raw[ (int) $field_id ] ) ) {
				$incoming = (array) $raw[ (int) $field_id ];
			}
			$selections[ $field_id ] = $this->normalize_list( $field, $incoming );
		}

		return $selections;
	}

	/**
	 * @param array $form
	 * @param GF_Field $field
	 */
	private function field_is_hidden( $form, $field ) {
		return (bool) GFFormsModel::is_field_hidden( $form, $field, array() );
	}

	/**
	 * @param array $form
	 */
	private function is_final_submission( $form ) {
		return (string) rgpost( 'gform_target_page_number_' . absint( $form['id'] ) ) === '0';
	}

	/**
	 * @param int $form_id
	 */
	private function acquire_lock( $form_id ) {
		global $wpdb;

		$name = 'gfeq_' . absint( $form_id );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
		if ( $got === null ) {
			return true;
		}
		if ( (string) $got !== '1' ) {
			return false;
		}

		$this->lock_name = $name;
		return true;
	}

	/**
	 * @param array $result
	 * @param array<string, string[]> $messages_by_field
	 * @return array
	 */
	private function fail_fields( $result, array $messages_by_field ) {
		$result['is_valid'] = false;
		foreach ( $result['form']['fields'] as $field ) {
			$field_id = (string) $field->id;
			if ( empty( $messages_by_field[ $field_id ] ) ) {
				continue;
			}
			$message = implode( ', ', array_unique( $messages_by_field[ $field_id ] ) );
			if ( ! empty( $field->failed_validation ) && ! empty( $field->validation_message ) ) {
				$field->validation_message .= ' ' . $message;
			} else {
				$field->failed_validation  = true;
				$field->validation_message = $message;
			}
		}
		return $result;
	}

	/**
	 * @param array $config
	 * @param array $violations
	 * @return array<string, string[]>
	 */
	private function messages_by_field( array $config, array $violations ) {
		$text = array();
		foreach ( $config['field_meta'] as $field_id => $meta ) {
			foreach ( $meta['choices'] as $choice ) {
				$text[ $field_id . ':' . $choice['value'] ] = $choice['text'];
			}
		}

		$messages = array();
		foreach ( $violations as $violation ) {
			$key     = $violation['field_id'] . ':' . $violation['value'];
			$label   = isset( $text[ $key ] ) ? $text[ $key ] : $violation['value'];
			$messages[ $violation['field_id'] ][] = $label . $this->translate_full_message( $violation['message'] );
		}
		return $messages;
	}

	/**
	 * Default suffix shown on a full choice. The English source stays in the
	 * calculator so a translation file can replace this later.
	 */
	private function default_full_message() {
		/* translators: Appended to a choice label when that choice has no quota left. */
		return __( '(Full)', 'gf-event-quota' );
	}

	/**
	 * @param mixed $message Saved suffix, or the English default.
	 */
	private function translate_full_message( $message ) {
		$message = trim( (string) $message );
		if ( $message === '' || $message === GFEQ_Calculator::DEFAULT_FULL_MESSAGE ) {
			return $this->default_full_message();
		}

		return $message;
	}

	/**
	 * @param array<string, array<string, array{full?: bool, message?: string}>> $states
	 * @return array<string, array<string, array{full?: bool, message?: string}>>
	 */
	private function translate_option_states( array $states ) {
		foreach ( $states as $field_id => $choices ) {
			if ( ! is_array( $choices ) ) {
				continue;
			}
			foreach ( $choices as $value => $state ) {
				if ( ! is_array( $state ) || empty( $state['full'] ) ) {
					continue;
				}
				$states[ $field_id ][ $value ]['message'] = $this->translate_full_message( isset( $state['message'] ) ? $state['message'] : '' );
			}
		}

		return $states;
	}

	/**
	 * @param string $html
	 * @param string $attr
	 */
	private function inject_tag_attributes( $html, $attr ) {
		foreach ( array( '<option ', '<input ' ) as $token ) {
			$pos = strpos( $html, $token );
			if ( $pos === false ) {
				continue;
			}
			return substr_replace( $html, rtrim( $token ) . $attr . ' ', $pos, strlen( $token ) );
		}
		return $html;
	}

	/**
	 * @param string $html
	 * @param string $label
	 * @param string $message
	 */
	private function append_visible_label( $html, $label, $message ) {
		$pairs = array(
			array( $label . '</label>', $label . $message . '</label>' ),
			array( esc_html( $label ) . '</option>', esc_html( $label . $message ) . '</option>' ),
			array( $label . '</option>', $label . $message . '</option>' ),
		);
		foreach ( $pairs as $pair ) {
			if ( $pair[0] === '</label>' || $pair[0] === '</option>' ) {
				continue;
			}
			$pos = strrpos( $html, $pair[0] );
			if ( $pos !== false ) {
				return substr_replace( $html, $pair[1], $pos, strlen( $pair[0] ) );
			}
		}
		return $html;
	}
}
